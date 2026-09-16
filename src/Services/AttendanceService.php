<?php
/**
 * Serviço de Frequência, Modo Aula e Gestão da Rotina Pedagógica
 * Plataforma Integrada de Gestão Pedagógica, Diário e Certificados Futuro Fácil
 */

declare(strict_types=1);

namespace FuturoFacil\Services;

require_once __DIR__ . '/CalendarService.php';

use FuturoFacil\Config\Database;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

class AttendanceService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getConnection();
    }

    /**
     * Retorna os detalhes de um encontro específico e da sua turma vinculada.
     */
    public function getEncontroDetails(int $encontroId): ?array
    {
        $sql = "
            SELECT 
                e.id AS encontro_id,
                e.turma_id,
                e.numero_encontro,
                e.data_encontro,
                e.turno,
                e.horario_inicio,
                e.horario_fim,
                e.conteudo_previsto,
                e.conteudo_ministrado,
                e.tipo,
                e.abonado,
                t.codigo_turma,
                t.curso_nome,
                t.cliente_nome,
                t.ordem_servico,
                t.modalidade,
                t.carga_horaria,
                t.data_inicio,
                t.data_conclusao,
                t.turno_padrao,
                t.status AS status_turma,
                t.instrutor,
                t.cidade,
                t.ementa
            FROM encontros e
            JOIN turmas t ON t.id = e.turma_id
            WHERE e.id = ?
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$encontroId]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        // Total de encontros pedagógicos (tipo = 'aula') e total geral da turma
        $stmtCount = $this->pdo->prepare("
            SELECT 
                COUNT(*) AS total_geral,
                SUM(CASE WHEN tipo = 'aula' THEN 1 ELSE 0 END) AS total_aulas
            FROM encontros 
            WHERE turma_id = ?
        ");
        $stmtCount->execute([(int)$row['turma_id']]);
        $counts = $stmtCount->fetch();
        $totalEncontros = (int)($counts['total_geral'] ?? 1);
        $totalAulas = (int)($counts['total_aulas'] ?? 1);

        // Formatação de data em pt-BR (ex: 14/09/2026 - Segunda-feira)
        $timestamp = strtotime($row['data_encontro']);
        $diasSemana = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'];
        $diaSemana = $diasSemana[(int)date('w', $timestamp)] ?? '';
        $dataFormatada = date('d/m/Y', $timestamp);

        // Faixa de horários
        $horarioInicio = $row['horario_inicio'] ? substr($row['horario_inicio'], 0, 5) : '';
        $horarioFim = $row['horario_fim'] ? substr($row['horario_fim'], 0, 5) : '';
        $faixaHorario = ($horarioInicio && $horarioFim) ? "{$horarioInicio} - {$horarioFim}" : '';

        // Configuração visual de alto contraste do turno
        $turnosConfig = CalendarService::getTurnosConfig();
        $turno = strtoupper((string)$row['turno']);
        $turnoConfig = $turnosConfig[$turno] ?? $turnosConfig['V'];

        // Encontro anterior e próximo para navegação ágil
        $stmtNav = $this->pdo->prepare("
            SELECT id, numero_encontro 
            FROM encontros 
            WHERE turma_id = ? AND tipo = 'aula'
            ORDER BY numero_encontro ASC
        ");
        $stmtNav->execute([(int)$row['turma_id']]);
        $allEncontros = $stmtNav->fetchAll();

        $prevEncontroId = null;
        $nextEncontroId = null;
        for ($i = 0; $i < count($allEncontros); $i++) {
            if ((int)$allEncontros[$i]['id'] === $encontroId) {
                if ($i > 0) {
                    $prevEncontroId = (int)$allEncontros[$i - 1]['id'];
                }
                if ($i < count($allEncontros) - 1) {
                    $nextEncontroId = (int)$allEncontros[$i + 1]['id'];
                }
                break;
            }
        }

        // Plano Dinâmico: se conteudo_ministrado não estiver preenchido, sugere conteudo_previsto
        $conteudoMinistrado = $row['conteudo_ministrado'];
        $conteudoPrevisto = $row['conteudo_previsto'];
        $conteudoSugerido = !empty(trim((string)$conteudoMinistrado))
            ? (string)$conteudoMinistrado
            : (!empty(trim((string)$conteudoPrevisto)) ? (string)$conteudoPrevisto : '');

        return [
            'encontro_id'          => (int)$row['encontro_id'],
            'turma_id'             => (int)$row['turma_id'],
            'numero_encontro'      => (int)$row['numero_encontro'],
            'total_encontros'      => $totalEncontros,
            'total_aulas'          => $totalAulas,
            'data_encontro'        => $row['data_encontro'],
            'data_formatada'       => $dataFormatada,
            'dia_semana'           => $diaSemana,
            'turno'                => $turno,
            'turno_config'         => $turnoConfig,
            'horario_inicio'       => $horarioInicio,
            'horario_fim'          => $horarioFim,
            'horario'              => $faixaHorario,
            'conteudo_previsto'    => $conteudoPrevisto,
            'conteudo_ministrado'  => $conteudoMinistrado,
            'conteudo_sugerido'    => $conteudoSugerido,
            'tipo'                 => $row['tipo'],
            'abonado'              => (int)$row['abonado'],
            'codigo_turma'         => $row['codigo_turma'],
            'curso_nome'           => $row['curso_nome'],
            'cliente_nome'         => $row['cliente_nome'],
            'ordem_servico'        => $row['ordem_servico'],
            'modalidade'           => $row['modalidade'],
            'carga_horaria'        => (int)$row['carga_horaria'],
            'status_turma'         => $row['status_turma'],
            'instrutor'            => $row['instrutor'],
            'cidade'               => $row['cidade'],
            'prev_encontro_id'     => $prevEncontroId,
            'next_encontro_id'     => $nextEncontroId,
        ];
    }

    /**
     * Retorna a lista de alunos da turma com as presenças deste encontro e a frequência acumulada.
     */
    public function getAttendanceList(int $encontroId): array
    {
        $stmt = $this->pdo->prepare("SELECT turma_id, numero_encontro FROM encontros WHERE id = ?");
        $stmt->execute([$encontroId]);
        $encontro = $stmt->fetch();

        if (!$encontro) {
            return [];
        }

        $turmaId = (int)$encontro['turma_id'];

        // Alunos matriculados na turma
        $stmtAlunos = $this->pdo->prepare("
            SELECT id AS aluno_id, nome_completo, cpf, cpf_mascarado 
            FROM alunos 
            WHERE turma_id = ? 
            ORDER BY nome_completo ASC
        ");
        $stmtAlunos->execute([$turmaId]);
        $alunos = $stmtAlunos->fetchAll();

        // Presenças já registradas para este encontro específico
        $stmtFreq = $this->pdo->prepare("
            SELECT aluno_id, presente 
            FROM frequencias 
            WHERE encontro_id = ?
        ");
        $stmtFreq->execute([$encontroId]);
        $freqRows = $stmtFreq->fetchAll();
        $freqMap = [];
        $hasAnyRecord = count($freqRows) > 0;
        foreach ($freqRows as $f) {
            $freqMap[(int)$f['aluno_id']] = (int)$f['presente'];
        }

        // Frequências acumuladas de todos os alunos até o momento
        $cumulativeFrequencies = $this->calculateCumulativeFrequencies($turmaId, $encontroId);

        $result = [];
        foreach ($alunos as $a) {
            $alunoId = (int)$a['aluno_id'];
            $alunoStats = $cumulativeFrequencies[$alunoId] ?? [
                'frequencia_acumulada'    => 100.0,
                'total_presencas'         => 0,
                'total_aulas'             => 0,
                'total_aulas_realizadas'  => 0,
                'is_risk'                 => false,
            ];

            // Se já tem registro neste encontro, usa o valor gravado.
            // Se não tem registro, o padrão para novo lançamento é 1 (Presente).
            $presente = $hasAnyRecord ? ($freqMap[$alunoId] ?? 0) : 1;

            $result[] = [
                'aluno_id'               => $alunoId,
                'nome_completo'          => $a['nome_completo'],
                'cpf'                    => $a['cpf'],
                'cpf_mascarado'          => $a['cpf_mascarado'],
                'presente'               => $presente,
                'has_record'             => $hasAnyRecord,
                'frequencia_acumulada'   => $alunoStats['frequencia_acumulada'],
                'total_presencas'        => $alunoStats['total_presencas'],
                'total_aulas_realizadas' => $alunoStats['total_aulas_realizadas'],
                'is_risk'                => $alunoStats['is_risk'],
            ];
        }

        return $result;
    }

    /**
     * Costura de Teste 3: Gravação Transacional Atômica (BEGIN ... COMMIT).
     */
    public function saveAttendance(
        int $encontroId,
        string $conteudoMinistrado,
        array $presencas,
        ?bool $abonado = null
    ): array {
        $this->pdo->beginTransaction();

        try {
            // 1. Verifica existência do encontro
            $stmt = $this->pdo->prepare("SELECT id, turma_id FROM encontros WHERE id = ?");
            $stmt->execute([$encontroId]);
            $encontro = $stmt->fetch();

            if (!$encontro) {
                throw new InvalidArgumentException("Encontro ID {$encontroId} não encontrado no banco de dados.");
            }

            // 2. Atualiza o encontro (conteudo_ministrado e flag abonado se fornecido)
            if ($abonado !== null) {
                $stmtEnc = $this->pdo->prepare("
                    UPDATE encontros 
                    SET conteudo_ministrado = ?, abonado = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmtEnc->execute([$conteudoMinistrado, $abonado ? 1 : 0, $encontroId]);
            } else {
                $stmtEnc = $this->pdo->prepare("
                    UPDATE encontros 
                    SET conteudo_ministrado = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmtEnc->execute([$conteudoMinistrado, $encontroId]);
            }

            // 3. Remove presenças anteriores deste encontro para evitar inconsistências
            $stmtDel = $this->pdo->prepare("DELETE FROM frequencias WHERE encontro_id = ?");
            $stmtDel->execute([$encontroId]);

            // 4. Insere cada registro de presença
            $stmtIns = $this->pdo->prepare("
                INSERT INTO frequencias (encontro_id, aluno_id, presente)
                VALUES (?, ?, ?)
            ");

            $totalPresentes = 0;
            $totalFaltas = 0;

            foreach ($presencas as $alunoId => $presVal) {
                $alunoIdInt = (int)$alunoId;
                $presente = !empty($presVal) ? 1 : 0;

                $stmtIns->execute([$encontroId, $alunoIdInt, $presente]);

                if ($presente === 1) {
                    $totalPresentes++;
                } else {
                    $totalFaltas++;
                }
            }

            $this->pdo->commit();

            return [
                'success'          => true,
                'encontro_id'      => $encontroId,
                'total_registros'  => count($presencas),
                'total_presentes'  => $totalPresentes,
                'total_faltas'     => $totalFaltas,
                'mensagem'         => 'Diário de classe e frequências salvos com sucesso!'
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Calcula as frequências acumuladas de todos os alunos de uma turma.
     */
    public function calculateCumulativeFrequencies(int $turmaId, ?int $targetEncontroId = null): array
    {
        $stmtEnc = $this->pdo->prepare("
            SELECT id, numero_encontro, abonado, data_encontro
            FROM encontros 
            WHERE turma_id = ? AND tipo = 'aula'
            ORDER BY numero_encontro ASC
        ");
        $stmtEnc->execute([$turmaId]);
        $encontrosAula = $stmtEnc->fetchAll();

        if (empty($encontrosAula)) {
            return [];
        }

        $stmtAlunos = $this->pdo->prepare("
            SELECT id, nome_completo 
            FROM alunos 
            WHERE turma_id = ? 
            ORDER BY nome_completo ASC
        ");
        $stmtAlunos->execute([$turmaId]);
        $alunos = $stmtAlunos->fetchAll();

        $encontroIds = array_column($encontrosAula, 'id');
        $inClause = implode(',', array_fill(0, count($encontroIds), '?'));
        
        $stmtFreq = $this->pdo->prepare("
            SELECT encontro_id, aluno_id, presente 
            FROM frequencias 
            WHERE encontro_id IN ({$inClause})
        ");
        $stmtFreq->execute($encontroIds);
        $allFreqs = $stmtFreq->fetchAll();

        $freqMatrix = [];
        $encontrosComChamada = [];
        foreach ($allFreqs as $f) {
            $encId = (int)$f['encontro_id'];
            $alunoId = (int)$f['aluno_id'];
            $freqMatrix[$encId][$alunoId] = (int)$f['presente'];
            $encontrosComChamada[$encId] = true;
        }

        $encontrosConsiderados = [];
        foreach ($encontrosAula as $enc) {
            $encId = (int)$enc['id'];
            $isAbonado = ((int)$enc['abonado'] === 1);
            $temChamada = isset($encontrosComChamada[$encId]);

            if ($temChamada || $isAbonado || $encId === $targetEncontroId) {
                $encontrosConsiderados[] = $enc;
            }
        }

        $totalAulasRealizadas = count($encontrosConsiderados);
        $totalAulasTurma = count($encontrosAula);

        $results = [];
        foreach ($alunos as $a) {
            $alunoId = (int)$a['id'];
            $presencasAluno = 0;

            foreach ($encontrosConsiderados as $enc) {
                $encId = (int)$enc['id'];
                $isAbonado = ((int)$enc['abonado'] === 1);

                if ($isAbonado) {
                    $presencasAluno++;
                } elseif (isset($freqMatrix[$encId][$alunoId])) {
                    if ($freqMatrix[$encId][$alunoId] === 1) {
                        $presencasAluno++;
                    }
                }
            }

            if ($totalAulasRealizadas > 0) {
                $porcentagem = round(($presencasAluno / $totalAulasRealizadas) * 100, 1);
            } else {
                $porcentagem = 100.0;
            }

            $isRisk = ($porcentagem < 75.0);

            $results[$alunoId] = [
                'aluno_id'               => $alunoId,
                'nome_completo'          => $a['nome_completo'],
                'total_presencas'        => $presencasAluno,
                'total_aulas'            => $totalAulasTurma,
                'total_aulas_realizadas' => $totalAulasRealizadas,
                'frequencia_acumulada'   => $porcentagem,
                'is_risk'                => $isRisk,
            ];
        }

        return $results;
    }

    /**
     * Alterna ou define a flag de abono coletivo de um encontro.
     */
    public function abonarEncontro(int $encontroId, bool $abonar = true): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE encontros 
            SET abonado = ?, updated_at = CURRENT_TIMESTAMP 
            WHERE id = ?
        ");
        return $stmt->execute([$abonar ? 1 : 0, $encontroId]);
    }
}
