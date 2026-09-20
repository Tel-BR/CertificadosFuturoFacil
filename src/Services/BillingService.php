<?php
/**
 * Serviço de Faturamento, Dados Financeiros e Gerador Assistido de Texto para NFS-e
 * Plataforma Integrada de Gestão Pedagógica, Diário e Certificados Futuro Fácil
 */

declare(strict_types=1);

namespace FuturoFacil\Services;

use PDO;
use RuntimeException;
use Throwable;

class BillingService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Calcula dinamicamente o valor total com base na modalidade de cobrança.
     *
     * @param string $tipoCobranca  'hora_aula', 'por_aluno', 'valor_fechado'
     * @param float  $valorUnitario Valor unitário da hora, do aluno ou base
     * @param int    $totalAlunos   Quantidade de alunos matriculados
     * @param float  $totalHoras    Carga horária total executada ou prevista
     * @param float  $valorFechado  Valor fixo global (opcional)
     * @return float
     */
    public function calculateTotal(
        string $tipoCobranca,
        float $valorUnitario,
        int $totalAlunos,
        float $totalHoras,
        float $valorFechado = 0.0
    ): float {
        return match ($tipoCobranca) {
            'valor_fechado' => ($valorFechado > 0.0) ? round($valorFechado, 2) : round($valorUnitario, 2),
            'por_aluno'     => round($totalAlunos * $valorUnitario, 2),
            'hora_aula'     => round($totalHoras * $valorUnitario, 2),
            default         => round($valorFechado > 0.0 ? $valorFechado : ($totalHoras * $valorUnitario), 2),
        };
    }

    /**
     * Obtém os dados consolidados de faturamento e métricas financeiras da turma.
     *
     * @param int $turmaId
     * @return array
     */
    public function getTurmaBillingData(int $turmaId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM turmas WHERE id = ?");
        $stmt->execute([$turmaId]);
        $turma = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$turma) {
            throw new RuntimeException("Turma ID {$turmaId} não encontrada.");
        }

        // 1. Contagem de alunos matriculados
        $stmtAlunos = $this->pdo->prepare("SELECT COUNT(*) FROM alunos WHERE turma_id = ?");
        $stmtAlunos->execute([$turmaId]);
        $totalAlunos = (int)$stmtAlunos->fetchColumn();

        // 2. Apuração de horas reais de aulas (excluindo deslocamentos logísticos)
        $stmtEnc = $this->pdo->prepare("
            SELECT data_encontro, turno, horario_inicio, horario_fim, intervalo_minutos, tipo
            FROM encontros
            WHERE turma_id = ?
              AND (tipo IS NULL OR tipo = 'aula')
            ORDER BY data_encontro ASC, numero_encontro ASC
        ");
        $stmtEnc->execute([$turmaId]);
        $encontros = $stmtEnc->fetchAll(PDO::FETCH_ASSOC);

        $horasComputadas = 0.0;
        foreach ($encontros as $enc) {
            $hIni = trim((string)($enc['horario_inicio'] ?? ''));
            $hFim = trim((string)($enc['horario_fim'] ?? ''));
            if (!empty($hIni) && !empty($hFim)) {
                $tIni = strtotime("1970-01-01 {$hIni}");
                $tFim = strtotime("1970-01-01 {$hFim}");
                if ($tFim !== false && $tIni !== false && $tFim > $tIni) {
                    $intervalo = max(0, (int)($enc['intervalo_minutos'] ?? 0));
                    $horasComputadas += max(0.0, (($tFim - $tIni) / 3600.0) - ($intervalo / 60.0));
                    continue;
                }
            }
            // Fallback por turno se não houver horário explícito
            $t = strtoupper(trim((string)($enc['turno'] ?? 'V')));
            $horasComputadas += match ($t) {
                'M', 'V' => 4.0,
                'N'      => 3.5,
                'D'      => 8.0,
                default  => 4.0,
            };
        }

        $cargaHorariaTurma = (float)($turma['carga_horaria'] ?? 0.0);
        $totalHoras = ($horasComputadas > 0.0) ? $horasComputadas : $cargaHorariaTurma;

        $tipoCobranca = trim((string)($turma['tipo_cobranca'] ?? 'hora_aula')) ?: 'hora_aula';
        $valorUnitario = (float)($turma['valor_unitario'] ?? $turma['valor_hora_aula'] ?? 0.0);
        $valorTotalSalvo = (float)($turma['valor_total'] ?? 0.0);

        $valorTotalCalculado = $this->calculateTotal(
            $tipoCobranca,
            $valorUnitario,
            $totalAlunos,
            $totalHoras,
            $valorTotalSalvo
        );

        $razaoSocial = trim((string)($turma['razao_social'] ?? $turma['cliente_nome'] ?? ''));
        $cnpjTomador = trim((string)($turma['cnpj_tomador'] ?? $turma['cliente_cnpj'] ?? ''));
        $cidadeUf = trim((string)($turma['cidade_uf'] ?? $turma['cidade'] ?? 'Goiânia - GO'));
        $emailFinanceiro = trim((string)($turma['email_financeiro'] ?? ''));
        $numeroOsContrato = trim((string)($turma['numero_os_contrato'] ?? $turma['ordem_servico'] ?? ''));

        $nfseTexto = $this->generateNfseText($turmaId);

        return [
            'turma_id'              => $turmaId,
            'curso_nome'            => (string)$turma['curso_nome'],
            'razao_social'          => $razaoSocial,
            'cnpj_tomador'          => $cnpjTomador,
            'cidade_uf'             => $cidadeUf,
            'email_financeiro'      => $emailFinanceiro,
            'numero_os_contrato'    => $numeroOsContrato,
            'tipo_cobranca'         => $tipoCobranca,
            'valor_unitario'        => $valorUnitario,
            'valor_total_salvo'     => $valorTotalSalvo,
            'valor_total_calculado' => $valorTotalCalculado,
            'total_alunos'          => $totalAlunos,
            'total_horas'           => $totalHoras,
            'nfse_texto'            => $nfseTexto,
        ];
    }

    /**
     * Atualiza dados de faturamento e recalcula o valor total salvo.
     *
     * @param int   $turmaId
     * @param array $dados
     * @return bool
     */
    public function updateBillingData(int $turmaId, array $dados): bool
    {
        $stmt = $this->pdo->prepare("SELECT * FROM turmas WHERE id = ?");
        $stmt->execute([$turmaId]);
        $turmaAtual = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$turmaAtual) {
            return false;
        }

        $razaoSocial = array_key_exists('razao_social', $dados)
            ? (trim((string)$dados['razao_social']) ?: null)
            : ($turmaAtual['razao_social'] ?? null);

        $cnpjTomador = array_key_exists('cnpj_tomador', $dados)
            ? (trim((string)$dados['cnpj_tomador']) ?: null)
            : ($turmaAtual['cnpj_tomador'] ?? null);

        $cidadeUf = array_key_exists('cidade_uf', $dados)
            ? (trim((string)$dados['cidade_uf']) ?: null)
            : ($turmaAtual['cidade_uf'] ?? null);

        $emailFinanceiro = array_key_exists('email_financeiro', $dados)
            ? (trim((string)$dados['email_financeiro']) ?: null)
            : ($turmaAtual['email_financeiro'] ?? null);

        $numeroOsContrato = array_key_exists('numero_os_contrato', $dados)
            ? (trim((string)$dados['numero_os_contrato']) ?: null)
            : ($turmaAtual['numero_os_contrato'] ?? null);

        $tipoCobranca = array_key_exists('tipo_cobranca', $dados)
            ? (trim((string)$dados['tipo_cobranca']) ?: 'hora_aula')
            : ($turmaAtual['tipo_cobranca'] ?? 'hora_aula');

        $valorUnitario = array_key_exists('valor_unitario', $dados)
            ? (float)$dados['valor_unitario']
            : (float)($turmaAtual['valor_unitario'] ?? $turmaAtual['valor_hora_aula'] ?? 0.0);

        // Se o valor total não for passado explicitamente, calcula com base nas regras
        if (array_key_exists('valor_total', $dados) && $dados['valor_total'] !== '' && (float)$dados['valor_total'] > 0.0) {
            $valorTotal = (float)$dados['valor_total'];
        } else {
            // Obtém dados atuais de alunos e horas para cálculo automático
            $stmtAlunos = $this->pdo->prepare("SELECT COUNT(*) FROM alunos WHERE turma_id = ?");
            $stmtAlunos->execute([$turmaId]);
            $totalAlunos = (int)$stmtAlunos->fetchColumn();

            $stmtEnc = $this->pdo->prepare("
                SELECT horario_inicio, horario_fim, intervalo_minutos, turno
                FROM encontros
                WHERE turma_id = ? AND (tipo IS NULL OR tipo = 'aula')
            ");
            $stmtEnc->execute([$turmaId]);
            $encRows = $stmtEnc->fetchAll(PDO::FETCH_ASSOC);

            $horas = 0.0;
            foreach ($encRows as $enc) {
                $hIni = trim((string)($enc['horario_inicio'] ?? ''));
                $hFim = trim((string)($enc['horario_fim'] ?? ''));
                if (!empty($hIni) && !empty($hFim)) {
                    $tIni = strtotime("1970-01-01 {$hIni}");
                    $tFim = strtotime("1970-01-01 {$hFim}");
                    if ($tFim !== false && $tIni !== false && $tFim > $tIni) {
                        $intervalo = max(0, (int)($enc['intervalo_minutos'] ?? 0));
                        $horas += max(0.0, (($tFim - $tIni) / 3600.0) - ($intervalo / 60.0));
                        continue;
                    }
                }
                $t = strtoupper(trim((string)($enc['turno'] ?? 'V')));
                $horas += match ($t) {
                    'M', 'V' => 4.0,
                    'N'      => 3.5,
                    'D'      => 8.0,
                    default  => 4.0,
                };
            }
            $totalHoras = ($horas > 0.0) ? $horas : (float)($turmaAtual['carga_horaria'] ?? 0.0);
            $valorTotal = $this->calculateTotal($tipoCobranca, $valorUnitario, $totalAlunos, $totalHoras);
        }

        // Sincronização com colunas legadas
        $clienteNome = $razaoSocial ?: ($turmaAtual['cliente_nome'] ?? null);
        $ordemServico = $numeroOsContrato ?: ($turmaAtual['ordem_servico'] ?? null);
        $clienteCnpj = $cnpjTomador ?: ($turmaAtual['cliente_cnpj'] ?? null);
        $valorHoraAula = ($tipoCobranca === 'hora_aula') ? $valorUnitario : (float)($turmaAtual['valor_hora_aula'] ?? 0.0);

        $stmtUp = $this->pdo->prepare("
            UPDATE turmas SET
                razao_social       = ?,
                cnpj_tomador       = ?,
                cidade_uf          = ?,
                email_financeiro   = ?,
                numero_os_contrato = ?,
                tipo_cobranca      = ?,
                valor_unitario     = ?,
                valor_total        = ?,
                cliente_nome       = ?,
                ordem_servico      = ?,
                cliente_cnpj       = ?,
                valor_hora_aula    = ?,
                updated_at         = ?
            WHERE id = ?
        ");

        return $stmtUp->execute([
            $razaoSocial,
            $cnpjTomador,
            $cidadeUf,
            $emailFinanceiro,
            $numeroOsContrato,
            $tipoCobranca,
            $valorUnitario,
            $valorTotal,
            $clienteNome,
            $ordemServico,
            $clienteCnpj,
            $valorHoraAula,
            date('Y-m-d H:i:s'),
            $turmaId,
        ]);
    }

    /**
     * Gera a discriminação detalhada dos serviços prestados para NFS-e.
     *
     * @param int $turmaId
     * @return string
     */
    public function generateNfseText(int $turmaId): string
    {
        $stmt = $this->pdo->prepare("SELECT * FROM turmas WHERE id = ?");
        $stmt->execute([$turmaId]);
        $turma = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$turma) {
            return '';
        }

        // Busca encontros cronológicos de aula (exclui deslocamentos)
        $stmtEnc = $this->pdo->prepare("
            SELECT data_encontro, turno, horario_inicio, horario_fim
            FROM encontros
            WHERE turma_id = ?
              AND (tipo IS NULL OR tipo = 'aula')
            ORDER BY data_encontro ASC, numero_encontro ASC
        ");
        $stmtEnc->execute([$turmaId]);
        $encontros = $stmtEnc->fetchAll(PDO::FETCH_ASSOC);

        $encontrosListados = [];
        foreach ($encontros as $enc) {
            $ts = strtotime($enc['data_encontro']);
            if ($ts === false) {
                continue;
            }
            $dataStr = date('d/m/Y', $ts);
            $horarioStr = $this->formatHorarioRange(
                $enc['horario_inicio'],
                $enc['horario_fim'],
                (string)($enc['turno'] ?? 'V')
            );
            $encontrosListados[] = "{$dataStr} {$horarioStr}";
        }

        $listaDatasStr = !empty($encontrosListados)
            ? implode(', ', $encontrosListados)
            : date('d/m/Y', strtotime($turma['data_inicio'])) . ' a ' . date('d/m/Y', strtotime($turma['data_conclusao']));

        $cursoNome = (string)$turma['curso_nome'];
        $razaoSocial = trim((string)($turma['razao_social'] ?? $turma['cliente_nome'] ?? 'Cliente Tomador'));
        $cnpjTomador = trim((string)($turma['cnpj_tomador'] ?? $turma['cliente_cnpj'] ?? '—'));
        $osContrato = trim((string)($turma['numero_os_contrato'] ?? $turma['ordem_servico'] ?? 'Conforme Pedido / Proposta Comercial'));
        $cargaHoraria = (int)($turma['carga_horaria'] ?? 0);
        $cidadeUf = trim((string)($turma['cidade_uf'] ?? $turma['cidade'] ?? 'Goiânia - GO'));

        $desc = "PRESTAÇÃO DE SERVIÇOS DE CAPACITAÇÃO E TREINAMENTO PROFISSIONAL\n";
        $desc .= "Curso / Treinamento: {$cursoNome}\n";
        $desc .= "Tomador do Serviço: {$razaoSocial}\n";
        $desc .= "CNPJ / CPF: {$cnpjTomador}\n";
        $desc .= "Ordem de Serviço / Contrato: {$osContrato}\n";
        $desc .= "Carga Horária Total: {$cargaHoraria} horas\n";
        $desc .= "Município de Execução: {$cidadeUf}\n";
        $desc .= "Aulas ministradas nos dias: {$listaDatasStr}";

        return $desc;
    }

    /**
     * Formata o intervalo de horário no formato amigável (ex: "(14h às 18h)" ou "(14h30 às 18h30)").
     */
    private function formatHorarioRange(?string $inicio, ?string $fim, string $turno): string
    {
        $ini = trim((string)$inicio);
        $fim = trim((string)$fim);

        if (empty($ini) || empty($fim)) {
            $padroes = TurmaService::getTurnoDefaultHorarios($turno);
            $ini = $padroes['horario_inicio'];
            $fim = $padroes['horario_fim'];
        }

        $fmtHora = function(string $timeStr): string {
            $parts = explode(':', $timeStr);
            $h = (int)($parts[0] ?? 0);
            $m = (int)($parts[1] ?? 0);
            $hPad = str_pad((string)$h, 2, '0', STR_PAD_LEFT);
            if ($m === 0) {
                return "{$hPad}h";
            }
            $mPad = str_pad((string)$m, 2, '0', STR_PAD_LEFT);
            return "{$hPad}h{$mPad}";
        };

        return "(" . $fmtHora($ini) . " às " . $fmtHora($fim) . ")";
    }
}
