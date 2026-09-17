<?php
/**
 * Serviço de Fechamento Assistido, Emissão de Certificados e Gestão do Livro de Registro
 * Plataforma Integrada de Gestão Pedagógica, Diário e Certificados Futuro Fácil
 */

declare(strict_types=1);

namespace FuturoFacil\Services;

require_once __DIR__ . '/../Config/Database.php';
require_once __DIR__ . '/AttendanceService.php';
require_once __DIR__ . '/ValidatorService.php';
require_once __DIR__ . '/CertificatePdfService.php';

use FuturoFacil\Config\Database;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;
use ZipArchive;

class CertificateService
{
    private PDO $pdo;
    private AttendanceService $attendanceService;
    private CertificatePdfService $pdfService;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getConnection();
        $this->attendanceService = new AttendanceService($this->pdo);
        $this->pdfService = new CertificatePdfService();
    }

    /**
     * Compila todos os dados necessários para a tela de Fechamento Assistido:
     * - Metadados da turma e status
     * - Encontros realizados e status de abono
     * - Separação auditada entre Alunos Aptos (>= 75% ou justificados) e Inaptos (< 75%)
     * - Métricas de aproveitamento
     * - Cálculo financeiro e discriminação completa para NFS-e
     */
    public function getTurmaFechamentoData(int $turmaId): array
    {
        $stmtTurma = $this->pdo->prepare("SELECT * FROM turmas WHERE id = ?");
        $stmtTurma->execute([$turmaId]);
        $turma = $stmtTurma->fetch(PDO::FETCH_ASSOC);

        if (!$turma) {
            throw new InvalidArgumentException("Turma ID {$turmaId} não encontrada para fechamento.");
        }

        // 1. Consulta encontros da turma
        $stmtEnc = $this->pdo->prepare("
            SELECT id, numero_encontro, data_encontro, turno, tipo, abonado, conteudo_ministrado, conteudo_previsto, horario_inicio, horario_fim
            FROM encontros
            WHERE turma_id = ?
            ORDER BY numero_encontro ASC
        ");
        $stmtEnc->execute([$turmaId]);
        $encontros = $stmtEnc->fetchAll(PDO::FETCH_ASSOC);

        // 2. Consulta alunos e calcula frequências acumuladas
        $cumulative = $this->attendanceService->calculateCumulativeFrequencies($turmaId);

        $stmtAlunos = $this->pdo->prepare("
            SELECT id, nome_completo, cpf, cpf_limpo, cpf_mascarado,
                   COALESCE(justificado, 0) AS justificado,
                   justificativa_texto
            FROM alunos
            WHERE turma_id = ?
            ORDER BY nome_completo ASC
        ");
        $stmtAlunos->execute([$turmaId]);
        $alunos = $stmtAlunos->fetchAll(PDO::FETCH_ASSOC);

        $aptos = [];
        $inaptos = [];

        foreach ($alunos as $a) {
            $alunoId = (int)$a['id'];
            $stats = $cumulative[$alunoId] ?? [
                'frequencia_acumulada'    => 100.0,
                'total_presencas'         => 0,
                'total_aulas'             => count($encontros),
                'total_aulas_realizadas'  => count($encontros),
                'is_risk'                 => false,
            ];

            $freq = (float)$stats['frequencia_acumulada'];
            $isJustificado = ((int)$a['justificado'] === 1);
            $isApto = ($freq >= 75.0 || $isJustificado);

            $alunoData = [
                'id'                     => $alunoId,
                'nome_completo'          => $a['nome_completo'],
                'cpf'                    => $a['cpf'],
                'cpf_mascarado'          => $a['cpf_mascarado'],
                'frequencia'             => $freq,
                'total_presencas'        => $stats['total_presencas'],
                'total_aulas'            => $stats['total_aulas_realizadas'],
                'is_justificado'         => $isJustificado,
                'justificativa_texto'    => $a['justificativa_texto'],
                'status_aprovacao'       => $isApto ? 'apto' : 'inapto',
                'motivo_aprovacao'       => $isJustificado ? 'Justificativa Extraordinária Homologada' : ($freq >= 75.0 ? 'Frequência Regular' : 'Abaixo do Limite Mínimo (75%)'),
            ];

            if ($isApto) {
                $aptos[] = $alunoData;
            } else {
                $inaptos[] = $alunoData;
            }
        }

        $totalAlunos = count($alunos);
        $totalAptos = count($aptos);
        $totalInaptos = count($inaptos);
        $aproveitamento = ($totalAlunos > 0) ? round(($totalAptos / $totalAlunos) * 100, 1) : 0.0;

        // 3. Cálculo de Faturamento / Apoio à NFS-e
        $tipoCobranca = (string)($turma['tipo_cobranca'] ?? 'hora_aula');
        $valorHoraAula = (float)($turma['valor_hora_aula'] ?? 0.0);
        $cargaHoraria = (int)($turma['carga_horaria'] ?? 0);
        $valorTotalCadastrado = (float)($turma['valor_total'] ?? 0.0);

        if ($tipoCobranca === 'hora_aula') {
            $valorCalculado = $valorHoraAula * $cargaHoraria;
        } elseif ($tipoCobranca === 'valor_fechado') {
            $valorCalculado = $valorTotalCadastrado;
        } elseif ($tipoCobranca === 'por_aluno') {
            $valorCalculado = $valorTotalCadastrado * $totalAptos;
        } else {
            $valorCalculado = $valorTotalCadastrado > 0 ? $valorTotalCadastrado : ($valorHoraAula * $cargaHoraria);
        }

        $nfseDescription = $this->generateNfseDescription($turmaId);

        // 4. Checa se já existem certificados emitidos para esta turma
        $stmtCert = $this->pdo->prepare("SELECT COUNT(*) FROM registros_certificados WHERE turma_id = ?");
        $stmtCert->execute([$turmaId]);
        $totalCertificadosEmitidos = (int)$stmtCert->fetchColumn();

        return [
            'turma'                       => $turma,
            'encontros'                   => $encontros,
            'alunos_aptos'                => $aptos,
            'alunos_inaptos'              => $inaptos,
            'total_alunos'                => $totalAlunos,
            'total_aptos'                 => $totalAptos,
            'total_inaptos'               => $totalInaptos,
            'aproveitamento_percentual'   => $aproveitamento,
            'valor_calculado'             => $valorCalculado,
            'nfse_description'            => $nfseDescription,
            'total_certificados_emitidos' => $totalCertificadosEmitidos,
            'ja_emitida'                  => ($totalCertificadosEmitidos > 0 || $turma['status'] === 'concluida'),
        ];
    }

    /**
     * Aplica ou revoga abono coletivo de presença para toda a turma em uma aula específica.
     */
    public function abonarAulaColetiva(int $encontroId, bool $abonar = true): bool
    {
        return $this->attendanceService->abonarEncontro($encontroId, $abonar);
    }

    /**
     * Autoriza justificativa individual excepcional para aluno abaixo de 75%.
     */
    public function justificarAluno(int $alunoId, string $motivo): bool
    {
        $motivoLimpo = trim($motivo);
        if (empty($motivoLimpo)) {
            $motivoLimpo = "Deliberação formal da coordenação pedagógica.";
        }

        $stmt = $this->pdo->prepare("
            UPDATE alunos
            SET justificado = 1, justificativa_texto = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        return $stmt->execute([$motivoLimpo, $alunoId]);
    }

    /**
     * Remove a justificativa excepcional de um aluno.
     */
    public function removerJustificativa(int $alunoId): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE alunos
            SET justificado = 0, justificativa_texto = NULL, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        return $stmt->execute([$alunoId]);
    }

    /**
     * Obtém a próxima numeração sequencial atômica de (Livro, Folha, Registro).
     * Regra notarial: 1 registro por folha, virada de livro a cada 100 folhas.
     */
    public function getNextSequenceNumbers(): array
    {
        $stmt = $this->pdo->query("
            SELECT livro_numero, folha_numero, registro_numero
            FROM registros_certificados
            ORDER BY registro_numero DESC, id DESC
            LIMIT 1
        ");
        $last = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$last) {
            return [
                'livro_numero'    => 1,
                'folha_numero'    => 1,
                'registro_numero' => 1,
            ];
        }

        $lastLivro = (int)$last['livro_numero'];
        $lastFolha = (int)$last['folha_numero'];
        $lastRegistro = (int)$last['registro_numero'];

        $nextRegistro = $lastRegistro + 1;

        // Virada de livro a cada 100 folhas
        if ($lastFolha >= 100) {
            $nextLivro = $lastLivro + 1;
            $nextFolha = 1;
        } else {
            $nextLivro = $lastLivro;
            $nextFolha = $lastFolha + 1;
        }

        return [
            'livro_numero'    => $nextLivro,
            'folha_numero'    => $nextFolha,
            'registro_numero' => $nextRegistro,
        ];
    }

    /**
     * Gera a discriminação de serviços completa e formatada para Apoio ao Faturamento / NFS-e.
     */
    public function generateNfseDescription(int $turmaId): string
    {
        $stmtTurma = $this->pdo->prepare("SELECT * FROM turmas WHERE id = ?");
        $stmtTurma->execute([$turmaId]);
        $turma = $stmtTurma->fetch(PDO::FETCH_ASSOC);

        if (!$turma) {
            return "";
        }

        // Busca datas dos encontros de aula realizados
        $stmtEnc = $this->pdo->prepare("
            SELECT data_encontro
            FROM encontros
            WHERE turma_id = ? AND tipo = 'aula'
            ORDER BY numero_encontro ASC
        ");
        $stmtEnc->execute([$turmaId]);
        $encRows = $stmtEnc->fetchAll(PDO::FETCH_ASSOC);

        $datasFormatadas = [];
        foreach ($encRows as $r) {
            $ts = strtotime($r['data_encontro']);
            if ($ts !== false) {
                $datasFormatadas[] = date('d/m/Y', $ts);
            }
        }

        $totalAulas = count($datasFormatadas);
        $listaDatasStr = "";
        if ($totalAulas > 1) {
            $ult = array_pop($datasFormatadas);
            $listaDatasStr = implode(', ', $datasFormatadas) . ' e ' . $ult;
        } elseif ($totalAulas === 1) {
            $listaDatasStr = $datasFormatadas[0];
        } else {
            $listaDatasStr = date('d/m/Y', strtotime($turma['data_inicio'])) . ' a ' . date('d/m/Y', strtotime($turma['data_conclusao']));
        }

        $cargaHoraria = (int)$turma['carga_horaria'];
        $horasPorDia = ($totalAulas > 0 && $cargaHoraria > 0) ? round($cargaHoraria / $totalAulas, 1) : 0;
        $horasPorDiaStr = ($horasPorDia > 0) ? "{$horasPorDia}h/dia" : "";

        // Valor
        $tipoCobranca = (string)($turma['tipo_cobranca'] ?? 'hora_aula');
        $valorHoraAula = (float)($turma['valor_hora_aula'] ?? 0.0);
        $valorTotalCad = (float)($turma['valor_total'] ?? 0.0);
        $valorFinal = ($tipoCobranca === 'hora_aula') ? ($valorHoraAula * $cargaHoraria) : $valorTotalCad;
        $valorFormatado = number_format($valorFinal, 2, ',', '.');

        $osStr = !empty($turma['ordem_servico']) ? (string)$turma['ordem_servico'] : 'Conforme Proposta Comercial / Pedido';
        $clienteNome = !empty($turma['cliente_nome']) ? (string)$turma['cliente_nome'] : 'Cliente Tomador';
        $clienteTipo = (string)($turma['cliente_tipo'] ?? 'PJ');
        $clienteDoc = !empty($turma['cliente_cnpj']) ? (string)$turma['cliente_cnpj'] : '—';
        $cidadeUf = trim(($turma['cliente_cidade'] ?? $turma['cidade'] ?? 'Goiânia') . '-' . ($turma['cliente_uf'] ?? 'GO'), '-');
        $instrutor = !empty($turma['instrutor']) ? (string)$turma['instrutor'] : 'Tel Santana Leite';

        $cargaExtenso = CertificatePdfService::formatCargaHorariaExtenso($cargaHoraria);

        $desc = "PRESTAÇÃO DE SERVIÇOS DE CAPACITAÇÃO E TREINAMENTO PROFISSIONAL\n";
        $desc .= "Ordem de Serviço / Referência: {$osStr}\n";
        $desc .= "Curso Ministrado: {$turma['curso_nome']}\n";
        $desc .= "Modalidade: {$turma['modalidade']}\n";
        $desc .= "Carga Horária: {$cargaHoraria} horas ({$cargaExtenso})";
        if (!empty($horasPorDiaStr)) {
            $desc .= " — {$horasPorDiaStr}";
        }
        $desc .= "\n";
        $desc .= "Datas dos Encontros Realizados: {$listaDatasStr}\n";
        $desc .= "Tomador dos Serviços: {$clienteNome} | {$clienteTipo}: {$clienteDoc}\n";
        $desc .= "Município de Execução: {$cidadeUf}\n";
        $desc .= "Instrutor Responsável: {$instrutor}\n";
        $desc .= "Valor Total dos Serviços: R$ {$valorFormatado}";

        return $desc;
    }

    /**
     * Emite formalmente os certificados para todos os alunos aptos:
     * 1. Reserva e incrementa atômica e sequencialmente o Livro, Folha e Registro no banco de dados.
     * 2. Calcula o código de autenticidade SHA-256 e insere na tabela registros_certificados.
     * 3. Atualiza o status da turma para 'concluida'.
     * 4. Gera os certificados individuais em PDF duplex (A4 paisagem vetorial).
     * 5. Gera o PDF consolidado para gráfica (2 * N páginas intercaladas).
     * 6. Exporta a planilha mestre atualizada livro_registro_certificados.xlsx.
     * 7. Compacta todos os artefatos em um arquivo .zip pronto para download.
     */
    public function emitirCertificadosTurma(int $turmaId, array $options = []): array
    {
        $fechamento = $this->getTurmaFechamentoData($turmaId);
        $turma = $fechamento['turma'];
        $alunosAptos = $fechamento['alunos_aptos'];

        if ($fechamento['ja_emitida']) {
            throw new RuntimeException('Esta turma já foi concluída e possui emissão registrada.');
        }

        if (empty($alunosAptos)) {
            throw new RuntimeException("Não há alunos aptos para emissão de certificados nesta turma.");
        }

        $dataEmissao = $options['data_emissao'] ?? date('Y-m-d');
        $loteId = "TURMA-{$turma['id']}-" . date('YmdHis');

        // INÍCIO DA TRANSAÇÃO ATÔMICA
        $this->pdo->beginTransaction();

        try {
            // Reserva a turma sob lock de escrita. Uma segunda requisição espera
            // esta transação e não consegue gerar outro lote após o COMMIT.
            $stmtClaim = $this->pdo->prepare("
                UPDATE turmas
                SET status = 'concluida', updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND status NOT IN ('concluida', 'cancelada')
                  AND NOT EXISTS (
                      SELECT 1 FROM registros_certificados WHERE turma_id = ?
                  )
            ");
            $stmtClaim->execute([$turmaId, $turmaId]);
            if ($stmtClaim->rowCount() !== 1) {
                throw new RuntimeException('Esta turma já foi concluída ou não pode ser emitida novamente.');
            }

            $seq = $this->getNextSequenceNumbers();
            $currLivro = $seq['livro_numero'];
            $currFolha = $seq['folha_numero'];
            $currRegistro = $seq['registro_numero'];

            $registrosSalvos = [];

            $stmtInsert = $this->pdo->prepare("
                INSERT INTO registros_certificados (
                    turma_id, codigo_autenticidade, aluno_nome, aluno_cpf, aluno_cpf_mascarado,
                    curso_nome, carga_horaria, carga_horaria_extenso, data_inicio, data_conclusao,
                    data_emissao, modalidade, instrutor, cidade, ementa,
                    livro_numero, folha_numero, registro_numero, frequencia, lote_id, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, CURRENT_TIMESTAMP
                )
            ");

            foreach ($alunosAptos as $aluno) {
                $cpfLimpo = ValidatorService::cleanCpf((string)$aluno['cpf']);
                $cpfMascarado = ValidatorService::maskCpf($cpfLimpo);
                $identificador = !empty($cpfLimpo) ? $cpfLimpo : trim((string)$aluno['nome_completo']);

                // Geração do Hash SHA-256 de autenticidade (64 caracteres maiúsculos)
                $nonce = bin2hex(random_bytes(16));
                $payload = "{$identificador}|" . trim((string)$turma['curso_nome']) . "|{$currRegistro}|{$nonce}";
                $codigoAutenticidade = strtoupper(hash('sha256', $payload));

                $cargaHoraria = (int)$turma['carga_horaria'];
                $cargaExtenso = CertificatePdfService::formatCargaHorariaExtenso($cargaHoraria);

                $stmtInsert->execute([
                    $turmaId,
                    $codigoAutenticidade,
                    $aluno['nome_completo'],
                    $aluno['cpf'],
                    $cpfMascarado,
                    $turma['curso_nome'],
                    $cargaHoraria,
                    $cargaExtenso,
                    $turma['data_inicio'],
                    $turma['data_conclusao'],
                    $dataEmissao,
                    $turma['modalidade'] ?? 'Presencial',
                    $turma['instrutor'] ?? 'Tel Santana Leite',
                    $turma['cidade'] ?? 'Goiânia',
                    $turma['ementa'],
                    $currLivro,
                    $currFolha,
                    $currRegistro,
                    (int)round((float)$aluno['frequencia']),
                    $loteId
                ]);

                $registroData = [
                    'id'                   => (int)$this->pdo->lastInsertId(),
                    'turma_id'             => $turmaId,
                    'codigo_autenticidade' => $codigoAutenticidade,
                    'aluno_nome'           => $aluno['nome_completo'],
                    'aluno_cpf'            => $aluno['cpf'],
                    'aluno_cpf_mascarado'  => $cpfMascarado,
                    'curso_nome'           => $turma['curso_nome'],
                    'carga_horaria'        => $cargaHoraria,
                    'carga_horaria_extenso'=> $cargaExtenso,
                    'data_inicio'          => $turma['data_inicio'],
                    'data_conclusao'       => $turma['data_conclusao'],
                    'data_emissao'         => $dataEmissao,
                    'modalidade'           => $turma['modalidade'] ?? 'Presencial',
                    'instrutor'            => $turma['instrutor'] ?? 'Tel Santana Leite',
                    'cidade'               => $turma['cidade'] ?? 'Goiânia',
                    'ementa'               => $turma['ementa'],
                    'livro_numero'         => $currLivro,
                    'folha_numero'         => $currFolha,
                    'registro_numero'      => $currRegistro,
                    'frequencia'           => (int)round((float)$aluno['frequencia']),
                    'lote_id'              => $loteId
                ];

                $registrosSalvos[] = [
                    'aluno'    => $aluno,
                    'turma'    => $turma,
                    'registro' => $registroData,
                ];

                // Incrementa para o próximo assento
                $currRegistro++;
                if ($currFolha >= 100) {
                    $currLivro++;
                    $currFolha = 1;
                } else {
                    $currFolha++;
                }
            }

            // O pacote precisa estar completo antes de confirmar os assentos.
            // A própria conexão lê os novos registros ainda não comitados para
            // incluir o Livro de Registro Digital atualizado no ZIP.
            $zipBinary = $this->buildZipPackage($registrosSalvos, $turma);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new RuntimeException("Falha atômica ao emitir certificados: " . $e->getMessage(), 0, $e);
        }

        $codigoSlug = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string)$turma['codigo_turma']);
        $zipFilename = "certificados_{$codigoSlug}_" . date('Ymd_His') . ".zip";

        return [
            'success'          => true,
            'lote_id'          => $loteId,
            'total_emitidos'   => count($registrosSalvos),
            'zip_filename'     => $zipFilename,
            'zip_bytes'        => $zipBinary,
            'registros_salvos' => $registrosSalvos,
        ];
    }

    /**
     * Constrói o arquivo ZIP contendo:
     * 1. Certificados individuais duplex em PDF
     * 2. Certificados consolidado duplex para gráfica (2 * N páginas)
     * 3. Planilha do Livro de Registro Digital atualizada (livro_registro_certificados.xlsx)
     */
    public function buildZipPackage(array $registrosSalvos, array $turma): string
    {
        $tempZipPath = sys_get_temp_dir() . '/lote_cert_' . uniqid() . '.zip';
        $zip = new ZipArchive();

        if ($zip->open($tempZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Não foi possível criar o arquivo ZIP temporário.");
        }

        // 1. Gera e adiciona os certificados individuais
        foreach ($registrosSalvos as $idx => $item) {
            $aluno = $item['aluno'];
            $reg = $item['registro'];

            $indPdf = $this->pdfService->renderCertificateDuplex($aluno, $turma, $reg);
            $slugAluno = preg_replace('/[^a-zA-Z0-9_\-]/', '_', strtolower(trim((string)$aluno['nome_completo'])));
            $filenameInd = sprintf("certificados_individuais/certificado_%03d_%s.pdf", $reg['registro_numero'], $slugAluno);
            $zip->addFromString($filenameInd, $indPdf);
        }

        // 2. Gera e adiciona o PDF consolidado para gráfica (2 * N páginas)
        $consolidatedPdf = $this->pdfService->renderConsolidatedDuplex($registrosSalvos);
        $zip->addFromString("certificados_consolidado_grafica.pdf", $consolidatedPdf);

        // 3. Gera e adiciona a planilha mestre Excel do Livro de Registro atualizada
        $excelBytes = $this->exportMasterBookExcel();
        $zip->addFromString("livro_registro_certificados.xlsx", $excelBytes);

        $zip->close();

        $content = file_get_contents($tempZipPath);
        @unlink($tempZipPath);

        if ($content === false) {
            throw new RuntimeException("Erro ao ler o pacote ZIP gerado.");
        }

        return $content;
    }

    /**
     * Exporta toda a base de registros_certificados em formato OpenXML Excel (.xlsx).
     * Formatação notarial profissional com cabeçalho azul marinho, bordas e alinhamentos contextuais.
     */
    public function exportMasterBookExcel(): string
    {
        $stmt = $this->pdo->query("
            SELECT livro_numero, folha_numero, registro_numero, codigo_autenticidade,
                   aluno_nome, aluno_cpf, curso_nome, carga_horaria, data_inicio,
                   data_conclusao, data_emissao, modalidade, instrutor, cidade
            FROM registros_certificados
            ORDER BY registro_numero ASC, id ASC
        ");
        $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $tempXlsxPath = sys_get_temp_dir() . '/master_book_' . uniqid() . '.xlsx';
        $zip = new ZipArchive();
        if ($zip->open($tempXlsxPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Não foi possível inicializar a planilha Excel mestre.");
        }

        // OpenXML: [Content_Types].xml
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
            '<Default Extension="xml" ContentType="application/xml"/>' .
            '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
            '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
            '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
            '</Types>';
        $zip->addFromString('[Content_Types].xml', $contentTypes);

        // _rels/.rels
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
            '</Relationships>';
        $zip->addFromString('_rels/.rels', $rels);

        // xl/_rels/workbook.xml.rels
        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
            '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
            '</Relationships>';
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);

        // xl/workbook.xml
        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
            '<sheets>' .
            '<sheet name="Livro de Registro" sheetId="1" r:id="rId1"/>' .
            '</sheets>' .
            '</workbook>';
        $zip->addFromString('xl/workbook.xml', $workbook);

        // xl/styles.xml (Estilo notarial: cabeçalho azul marinho, zebra striping, bordas)
        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
            '<fonts count="3">' .
            '<font><sz val="10"/><name val="Calibri"/></font>' .
            '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>' .
            '<font><b/><sz val="10"/><name val="Calibri"/></font>' .
            '</fonts>' .
            '<fills count="4">' .
            '<fill><patternFill patternType="none"/></fill>' .
            '<fill><patternFill patternType="gray125"/></fill>' .
            '<fill><patternFill patternType="solid"><fgColor rgb="FF1E3A8A"/></patternFill></fill>' . // Cabeçalho Azul Notarial
            '<fill><patternFill patternType="solid"><fgColor rgb="FFF8FAFC"/></patternFill></fill>' . // Zebra
            '</fills>' .
            '<borders count="2">' .
            '<border><left/><right/><top/><bottom/></border>' .
            '<border>' .
            '<left style="thin"><color rgb="FFD1D5DB"/></left>' .
            '<right style="thin"><color rgb="FFD1D5DB"/></right>' .
            '<top style="thin"><color rgb="FFD1D5DB"/></top>' .
            '<bottom style="thin"><color rgb="FFD1D5DB"/></bottom>' .
            '</border>' .
            '</borders>' .
            '<cellXfs count="4">' .
            '<xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>' . // 0: Normal
            '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>' . // 1: Header
            '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>' . // 2: Data normal
            '<xf numFmtId="0" fontId="0" fillId="3" borderId="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>' . // 3: Data zebra
            '</cellXfs>' .
            '</styleSheet>';
        $zip->addFromString('xl/styles.xml', $styles);

        // xl/worksheets/sheet1.xml
        $headers = [
            "Livro Nº", "Folha Nº", "Registro Nº", "Código de Autenticidade",
            "Nome do Aluno", "CPF", "Curso", "Carga Horária",
            "Data de Início", "Data de Conclusão", "Data de Emissão",
            "Modalidade", "Instrutor", "Cidade"
        ];

        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
            '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
            '<sheetViews><sheetView tabSelected="1" workbookViewId="0"/></sheetViews>' .
            '<sheetFormatPr defaultRowHeight="18"/>' .
            '<cols>' .
            '<col min="1" max="1" width="10" customWidth="1"/>' .
            '<col min="2" max="2" width="10" customWidth="1"/>' .
            '<col min="3" max="3" width="12" customWidth="1"/>' .
            '<col min="4" max="4" width="34" customWidth="1"/>' .
            '<col min="5" max="5" width="30" customWidth="1"/>' .
            '<col min="6" max="6" width="16" customWidth="1"/>' .
            '<col min="7" max="7" width="28" customWidth="1"/>' .
            '<col min="8" max="8" width="14" customWidth="1"/>' .
            '<col min="9" max="9" width="14" customWidth="1"/>' .
            '<col min="10" max="10" width="16" customWidth="1"/>' .
            '<col min="11" max="11" width="16" customWidth="1"/>' .
            '<col min="12" max="12" width="16" customWidth="1"/>' .
            '<col min="13" max="13" width="20" customWidth="1"/>' .
            '<col min="14" max="14" width="16" customWidth="1"/>' .
            '</cols>' .
            '<sheetData>';

        // Linha 1: Cabeçalhos
        $sheetXml .= '<row r="1" ht="26" customHeight="1">';
        foreach ($headers as $cIdx => $h) {
            $colLetter = self::getColLetter($cIdx + 1);
            $sheetXml .= sprintf(
                '<c r="%s1" t="inlineStr" s="1"><is><t>%s</t></is></c>',
                $colLetter,
                htmlspecialchars($h, ENT_XML1, 'UTF-8')
            );
        }
        $sheetXml .= '</row>';

        // Linhas de dados
        $rIdx = 2;
        foreach ($registros as $reg) {
            $styleId = ($rIdx % 2 === 0) ? 3 : 2;
            $cpfFormatado = ValidatorService::formatCpf((string)$reg['aluno_cpf']);

            $rowVals = [
                (string)$reg['livro_numero'],
                (string)$reg['folha_numero'],
                (string)$reg['registro_numero'],
                (string)$reg['codigo_autenticidade'],
                (string)$reg['aluno_nome'],
                $cpfFormatado,
                (string)$reg['curso_nome'],
                (string)$reg['carga_horaria'] . 'h',
                (string)($reg['data_inicio'] ?: '—'),
                (string)$reg['data_conclusao'],
                (string)$reg['data_emissao'],
                (string)($reg['modalidade'] ?: 'Presencial'),
                (string)($reg['instrutor'] ?: '—'),
                (string)($reg['cidade'] ?: '—')
            ];

            $sheetXml .= sprintf('<row r="%d" ht="20" customHeight="1">', $rIdx);
            foreach ($rowVals as $cIdx => $val) {
                $colLetter = self::getColLetter($cIdx + 1);
                $sheetXml .= sprintf(
                    '<c r="%s%d" t="inlineStr" s="%d"><is><t>%s</t></is></c>',
                    $colLetter,
                    $rIdx,
                    $styleId,
                    htmlspecialchars($val, ENT_XML1, 'UTF-8')
                );
            }
            $sheetXml .= '</row>';
            $rIdx++;
        }

        $sheetXml .= '</sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);

        $zip->close();
        $excelBytes = file_get_contents($tempXlsxPath);
        @unlink($tempXlsxPath);

        if ($excelBytes === false) {
            throw new RuntimeException("Erro ao gerar a planilha Excel mestre.");
        }

        return $excelBytes;
    }

    private static function getColLetter(int $colNumber): string
    {
        $letter = '';
        while ($colNumber > 0) {
            $remainder = ($colNumber - 1) % 26;
            $letter = chr(65 + $remainder) . $letter;
            $colNumber = (int)(($colNumber - $remainder) / 26);
        }
        return $letter;
    }
}
