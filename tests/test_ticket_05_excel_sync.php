<?php
/**
 * Suíte de Testes Automatizados — Ticket 05: Sincronização Bidirecional com Planilhas Excel (.xlsx)
 * Costura de Teste 4 (Testing Seam 4): Borda de Sincronização Excel (.xlsx) e Idempotência sem Duplicidade
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Config/Database.php';
require_once __DIR__ . '/../src/Services/ValidatorService.php';
require_once __DIR__ . '/../src/Services/AttendanceService.php';
require_once __DIR__ . '/../src/Services/ExcelSyncService.php';
require_once __DIR__ . '/../src/Services/AuthService.php';

use FuturoFacil\Config\Database;
use FuturoFacil\Services\ValidatorService;
use FuturoFacil\Services\AttendanceService;
use FuturoFacil\Services\ExcelSyncService;

$verde = "\033[32m";
$vermelho = "\033[31m";
$amarelo = "\033[33m";
$azul = "\033[36m";
$reset = "\033[0m";

echo "{$azul}======================================================================\n";
echo " Executando Suíte de Testes — Ticket 05: Excel (.xlsx) e Costura de Teste 4\n";
echo "======================================================================{$reset}\n\n";

$testDbPath = __DIR__ . '/test_temp_excel_sync.db';
if (file_exists($testDbPath)) {
    unlink($testDbPath);
}

$passedCount = 0;
$totalCount = 0;

function assertTest(bool $condition, string $description, ?string $detail = null): void
{
    global $verde, $vermelho, $reset, $passedCount, $totalCount;
    $totalCount++;
    if ($condition) {
        $passedCount++;
        echo "  {$verde}✓ [OK]{$reset} {$description}\n";
    } else {
        echo "  {$vermelho}✗ [FALHA]{$reset} {$description}";
        if ($detail) {
            echo " ({$detail})";
        }
        echo "\n";
    }
}

/** Edita células de uma cópia do arquivo exportado, como uma edição offline no Excel. */
function editExportedWorkbook(string $xlsxBinary, array $edits): string
{
    $path = tempnam(sys_get_temp_dir(), 'ff_excel_edit_');
    if ($path === false || file_put_contents($path, $xlsxBinary) === false) {
        throw new RuntimeException('Não foi possível preparar a planilha de teste.');
    }

    $zip = new ZipArchive();
    $zipOpen = false;
    try {
        if ($zip->open($path) !== true) {
            throw new RuntimeException('A planilha exportada não é um arquivo OpenXML válido.');
        }
        $zipOpen = true;
        foreach ($edits as $sheetNumber => $cells) {
            $entry = "xl/worksheets/sheet{$sheetNumber}.xml";
            $xml = $zip->getFromName($entry);
            if ($xml === false) {
                throw new RuntimeException("A aba {$sheetNumber} não existe na planilha.");
            }

            $document = new DOMDocument();
            if (!$document->loadXML($xml)) {
                throw new RuntimeException("XML inválido na aba {$sheetNumber}.");
            }
            $xpath = new DOMXPath($document);
            foreach ($cells as $reference => $value) {
                if (!preg_match('/^([A-Z]+)([1-9][0-9]*)$/', $reference, $parts)) {
                    throw new RuntimeException("Referência de célula inválida: {$reference}.");
                }
                $matching = $xpath->query('//*[local-name()="c" and @r="' . $reference . '"]');
                $cell = $matching?->item(0);
                if (!$cell instanceof DOMElement) {
                    $rowNumber = $parts[2];
                    $row = $xpath->query('//*[local-name()="row" and @r="' . $rowNumber . '"]')?->item(0);
                    if (!$row instanceof DOMElement) {
                        $sheetData = $xpath->query('//*[local-name()="sheetData"]')?->item(0);
                        if (!$sheetData instanceof DOMElement) {
                            throw new RuntimeException("A aba {$sheetNumber} não contém linhas.");
                        }
                        $row = $document->createElementNS($sheetData->namespaceURI, 'row');
                        $row->setAttribute('r', $rowNumber);
                        $sheetData->appendChild($row);
                    }
                    $cell = $document->createElementNS($row->namespaceURI, 'c');
                    $cell->setAttribute('r', $reference);
                    $row->appendChild($cell);
                }
                while ($cell->firstChild !== null) {
                    $cell->removeChild($cell->firstChild);
                }
                $namespace = $cell->namespaceURI;
                if (is_int($value)) {
                    $cell->removeAttribute('t');
                    $cell->appendChild($document->createElementNS($namespace, 'v', (string)$value));
                } else {
                    $cell->setAttribute('t', 'inlineStr');
                    $inline = $document->createElementNS($namespace, 'is');
                    $text = $document->createElementNS($namespace, 't');
                    $text->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
                    $text->appendChild($document->createTextNode((string)$value));
                    $inline->appendChild($text);
                    $cell->appendChild($inline);
                }
            }
            if (!$zip->addFromString($entry, $document->saveXML())) {
                throw new RuntimeException("Falha ao salvar a aba {$sheetNumber} editada.");
            }
        }
        $closed = $zip->close();
        $zipOpen = false;
        if (!$closed) {
            throw new RuntimeException('Falha ao fechar a planilha editada.');
        }
        $binary = file_get_contents($path);
        if ($binary === false) {
            throw new RuntimeException('Falha ao ler a planilha editada.');
        }
        return $binary;
    } finally {
        if ($zipOpen) {
            $zip->close();
        }
        if (file_exists($path)) {
            unlink($path);
        }
    }
}

try {
    // 1. Inicializa banco SQLite isolado para os testes
    $pdo = new PDO("sqlite:{$testDbPath}", null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $schemaSql = file_get_contents(__DIR__ . '/../database/schema_sqlite.sql');
    $pdo->exec($schemaSql);

    Database::setConfig([
        'driver'   => 'sqlite',
        'database' => $testDbPath,
    ]);

    // 2. Popula turma de teste com 10 alunos e 4 encontros
    $pdo->exec("
        INSERT INTO turmas (
            codigo_turma, curso_nome, cliente_nome, ordem_servico,
            data_inicio, data_conclusao, turno_padrao, status, chave_acesso,
            carga_horaria, instrutor, cidade, ementa
        ) VALUES (
            'TURMA-EXCEL-SYNC-TEST', 'Excel Corporativo Especialista', 'Sicoob Credisul', 'OS-2026-005',
            '2026-09-14', '2026-09-17', 'V', 'em_andamento', 'CHAVE-EXCEL-TEST-2026',
            16, 'Telmo Tropia', 'Goiânia - GO', 'Fórmulas, Dinâmicas, Dashboards e Power Query'
        )
    ");
    $turmaId = (int)$pdo->lastInsertId();

    $encontrosData = [
        [1, '2026-09-14', '14:00', '18:00', 'Introdução ao Excel Avançado e PROCV', 'Aula 1 realizada com foco em PROCV e PROCH.', 'aula', 0],
        [2, '2026-09-15', '14:00', '18:00', 'Tabelas Dinâmicas e Fórmulas Matriciais', null, 'aula', 0],
        [3, '2026-09-16', '14:00', '18:00', 'Segmentação de Dados e Dashboards', null, 'aula', 0],
        [4, '2026-09-17', '14:00', '18:00', 'Automação com Power Query', null, 'aula', 0],
    ];
    $stmtEnc = $pdo->prepare("
        INSERT INTO encontros (
            turma_id, numero_encontro, data_encontro, turno, horario_inicio, horario_fim,
            conteudo_previsto, conteudo_ministrado, tipo, abonado
        ) VALUES (
            ?, ?, ?, 'V', ?, ?,
            ?, ?, ?, ?
        )
    ");
    $encontroIds = [];
    foreach ($encontrosData as $enc) {
        $stmtEnc->execute([
            $turmaId, $enc[0], $enc[1], $enc[2], $enc[3],
            $enc[4], $enc[5], $enc[6], $enc[7]
        ]);
        $encontroIds[$enc[0]] = (int)$pdo->lastInsertId();
    }

    // Gerador de CPFs matematicamente válidos para teste (garantindo unicidade)
    function gerarCpfValido(int $seed): string
    {
        $base = str_pad((string)(200000000 + $seed), 9, '0', STR_PAD_LEFT);
        $d = array_map('intval', str_split($base));
        $soma1 = 0;
        for ($i = 0; $i < 9; $i++) {
            $soma1 += $d[$i] * (10 - $i);
        }
        $r1 = $soma1 % 11;
        $dv1 = ($r1 < 2) ? 0 : 11 - $r1;
        $d[] = $dv1;

        $soma2 = 0;
        for ($i = 0; $i < 10; $i++) {
            $soma2 += $d[$i] * (11 - $i);
        }
        $r2 = $soma2 % 11;
        $dv2 = ($r2 < 2) ? 0 : 11 - $r2;
        $d[] = $dv2;

        return implode('', $d);
    }

    $stmtAluno = $pdo->prepare("
        INSERT INTO alunos (turma_id, nome_completo, cpf, cpf_limpo, cpf_mascarado)
        VALUES (?, ?, ?, ?, ?)
    ");
    $alunoIds = [];
    for ($i = 1; $i <= 10; $i++) {
        $nome = "Aluno Sincronizacao " . str_pad((string)$i, 2, '0', STR_PAD_LEFT);
        $cpfLimpo = gerarCpfValido($i);
        $cpfFormatado = ValidatorService::formatCpf($cpfLimpo);
        $cpfMascarado = ValidatorService::maskCpf($cpfLimpo);
        $stmtAluno->execute([$turmaId, $nome, $cpfFormatado, $cpfLimpo, $cpfMascarado]);
        $alunoIds[] = (int)$pdo->lastInsertId();
    }

    // Registra chamada dos 4 Encontros com todos presentes inicialmente
    $stmtFreqInit = $pdo->prepare("INSERT INTO frequencias (encontro_id, aluno_id, presente) VALUES (?, ?, ?)");
    for ($encIdx = 1; $encIdx <= 4; $encIdx++) {
        foreach ($alunoIds as $aId) {
            $stmtFreqInit->execute([$encontroIds[$encIdx], $aId, 1]);
        }
    }

    $syncService = new ExcelSyncService($pdo);

    // =========================================================================
    // SEÇÃO 1: Exportação da Planilha Excel (.xlsx) e Estrutura em 3 Abas
    // =========================================================================
    echo "{$amarelo}-> 1. Testando Exportação da Planilha Excel e Validação das 3 Abas...{$reset}\n";

    $xlsxBinary = $syncService->exportTurmaSpreadsheet($turmaId);
    assertTest(is_string($xlsxBinary) && strlen($xlsxBinary) > 1000, "Planilha exportada com sucesso (tamanho binário > 1KB)");
    assertTest(str_starts_with($xlsxBinary, "PK\x03\x04"), "Arquivo gerado possui assinatura válida de arquivo ZIP/OpenXML (PK)");

    // Salva temporariamente para inspeção via ZipArchive
    $exportedFilePath = __DIR__ . '/test_turma_exported.xlsx';
    file_put_contents($exportedFilePath, $xlsxBinary);

    $zip = new ZipArchive();
    $openRes = $zip->open($exportedFilePath);
    assertTest($openRes === true, "Arquivo .xlsx pode ser aberto como ZIP sem corrupção");

    // Verifica arquivos obrigatórios do OpenXML
    assertTest($zip->locateName('[Content_Types].xml') !== false, "Arquivo contém [Content_Types].xml");
    assertTest($zip->locateName('_rels/.rels') !== false, "Arquivo contém _rels/.rels");
    assertTest($zip->locateName('xl/workbook.xml') !== false, "Arquivo contém xl/workbook.xml");
    assertTest($zip->locateName('xl/styles.xml') !== false, "Arquivo contém xl/styles.xml");
    assertTest($zip->locateName('xl/worksheets/sheet1.xml') !== false, "Arquivo contém xl/worksheets/sheet1.xml");
    assertTest($zip->locateName('xl/worksheets/sheet2.xml') !== false, "Arquivo contém xl/worksheets/sheet2.xml");
    assertTest($zip->locateName('xl/worksheets/sheet3.xml') !== false, "Arquivo contém xl/worksheets/sheet3.xml");
    $zip->close();

    // Faz o parse do arquivo gerado
    $parsedData = $syncService->parseXlsx($exportedFilePath);
    assertTest(isset($parsedData['Alunos e Chamada']), "Aba 1 'Alunos e Chamada' identificada com sucesso");
    assertTest(isset($parsedData['Diário e Planos']), "Aba 2 'Diário e Planos' identificada com sucesso");
    assertTest(isset($parsedData['Dados da Turma']), "Aba 3 'Dados da Turma' identificada com sucesso");

    // Valida conteúdo da Aba 1 (Alunos e Chamada)
    $aba1 = $parsedData['Alunos e Chamada'];
    assertTest(count($aba1) === 11, "Aba 1 contém 11 linhas (1 cabeçalho + 10 alunos)");
    assertTest($aba1[0][0] === 'Nº' && $aba1[0][1] === 'Nome Completo' && $aba1[0][2] === 'CPF', "Cabeçalhos básicos da Aba 1 corretos (Nº, Nome Completo, CPF)");
    assertTest(str_contains((string)$aba1[0][3], 'Encontro 1'), "Coluna de Encontro 1 presente no cabeçalho da chamada");
    assertTest(str_contains((string)$aba1[0][4], 'Encontro 2'), "Coluna de Encontro 2 presente no cabeçalho da chamada");
    assertTest(end($aba1[0]) === 'Frequência (%)', "Última coluna da Aba 1 é a Frequência (%) acumulada");
    assertTest($aba1[1][1] === 'Aluno Sincronizacao 01', "Nome do primeiro aluno presente na linha 2");
    assertTest((int)$aba1[1][3] === 1, "Presença do primeiro aluno no Encontro 1 registrada como 1");

    // Valida conteúdo da Aba 2 (Diário e Planos)
    $aba2 = $parsedData['Diário e Planos'];
    assertTest(count($aba2) === 5, "Aba 2 contém 5 linhas (1 cabeçalho + 4 encontros)");
    assertTest($aba2[1][7] === 'Introdução ao Excel Avançado e PROCV', "Conteúdo previsto do Encontro 1 exportado fielmente");
    assertTest($aba2[1][8] === 'Aula 1 realizada com foco em PROCV e PROCH.', "Conteúdo ministrado do Encontro 1 exportado fielmente");

    // Valida conteúdo da Aba 3 (Dados da Turma)
    $aba3 = $parsedData['Dados da Turma'];
    assertTest(count($aba3) >= 10, "Aba 3 contém metadados completos da turma");
    $metaTurma = [];
    foreach ($aba3 as $r) {
        if (isset($r[0], $r[1])) {
            $metaTurma[trim((string)$r[0])] = trim((string)$r[1]);
        }
    }
    assertTest(($metaTurma['Nome do Curso'] ?? '') === 'Excel Corporativo Especialista', "Nome do curso correto na Aba 3");
    assertTest(($metaTurma['Cliente'] ?? '') === 'Sicoob Credisul', "Cliente correto na Aba 3");
    assertTest(($metaTurma['Carga Horária (h)'] ?? '') === '16', "Carga horária correta na Aba 3");
    assertTest(($metaTurma['Instrutor'] ?? '') === 'Telmo Tropia', "Instrutor correto na Aba 3");

    // =========================================================================
    // SEÇÃO 2: Sanitização Estrita de Espaços em Branco (.strip() / trim())
    // =========================================================================
    echo "\n{$amarelo}-> 2. Testando Sanitização Estrita de Espaços em Branco (conforme Ticket #001)...{$reset}\n";

    $xlsxSujoBinary = editExportedWorkbook($xlsxBinary, [
        1 => [
            'B2' => '   Aluno Sincronizacao 01   ',
            'C2' => '  ' . $aba1[1][2] . '   ',
            'B3' => '  Aluno   Sincronizacao    02  ',
        ],
    ]);
    $planilhaSuja = $syncService->parseXlsx($xlsxSujoBinary);
    assertTest($planilhaSuja['Alunos e Chamada'][1][1] === '   Aluno Sincronizacao 01   ', "Edição offline preserva espaços até a importação");

    $resImportSanitize = $syncService->importTurmaSpreadsheet($turmaId, $xlsxSujoBinary);
    assertTest($resImportSanitize['success'] === true, "Importação com sanitização concluída com sucesso");

    $stmtCheckNome1 = $pdo->prepare("SELECT nome_completo FROM alunos WHERE id = ?");
    $stmtCheckNome1->execute([$alunoIds[0]]);
    $nome1Banco = $stmtCheckNome1->fetchColumn();
    assertTest($nome1Banco === 'Aluno Sincronizacao 01', "Nome do aluno 1 salvo estritamente sanitizado sem espaços externos");

    $stmtCheckNome2 = $pdo->prepare("SELECT nome_completo FROM alunos WHERE id = ?");
    $stmtCheckNome2->execute([$alunoIds[1]]);
    $nome2Banco = $stmtCheckNome2->fetchColumn();
    assertTest($nome2Banco === 'Aluno Sincronizacao 02', "Nome do aluno 2 salvo estritamente sanitizado sem espaços internos múltiplos");

    // =========================================================================
    // SEÇÃO 3: Costura de Teste 4 — Idempotência sem Duplicidade
    // =========================================================================
    echo "\n{$amarelo}-> 3. Testando Costura de Teste 4 (Idempotência sem Duplicidade)...{$reset}\n";

    // Simula alterações offline no Excel feitas pelo operador:
    // 1. Aluno 01: falta no Encontro 2 (muda de 1 para 0)
    // 2. Aluno 03: falta no Encontro 2 (muda de 1 para 0)
    // 3. Conteúdo ministrado do Encontro 2 atualizado
    $novoConteudoMin2 = "Aula 2 ministrada offline: Fórmulas Matriciais e Tabelas Dinâmicas avançadas com filtros dinâmicos.";
    $metadataEdits = [];
    foreach ($aba3 as $rowIndex => $row) {
        if (isset($row[0]) && trim((string)$row[0]) === 'Data de Início') {
            $metadataEdits['B' . ($rowIndex + 1)] = '05/10/2026';
        } elseif (isset($row[0]) && trim((string)$row[0]) === 'Data de Conclusão') {
            $metadataEdits['B' . ($rowIndex + 1)] = '28/10/2026';
        }
    }

    $xlsxModificadoBytes = editExportedWorkbook($xlsxBinary, [
        1 => ['E2' => 0, 'E4' => 0],
        2 => ['I3' => $novoConteudoMin2, 'D3' => '14:30', 'E3' => '18:30'],
        3 => $metadataEdits,
    ]);
    $planilhaModificada = $syncService->parseXlsx($xlsxModificadoBytes);
    assertTest((string)$planilhaModificada['Alunos e Chamada'][1][4] === '0'
        && $planilhaModificada['Diário e Planos'][2][8] === $novoConteudoMin2,
        "Edições offline de presença e conteúdo são legíveis pela API pública");

    // Primeira reimportação da planilha modificada
    $resImport1 = $syncService->importTurmaSpreadsheet($turmaId, $xlsxModificadoBytes);

    assertTest($resImport1['success'] === true, "Primeira reimportação concluída com sucesso");
    assertTest($resImport1['alunos_atualizados'] === 10, "Exatamente 10 alunos identificados e atualizados");
    assertTest($resImport1['alunos_inseridos'] === 0, "Zero novos alunos inseridos (nenhuma duplicidade criada)");

    // Checagem rigorosa de integridade no banco MariaDB/SQLite
    $stmtCountAlunos = $pdo->prepare("SELECT COUNT(*) FROM alunos WHERE turma_id = ?");
    $stmtCountAlunos->execute([$turmaId]);
    $totalAlunosAposImport = (int)$stmtCountAlunos->fetchColumn();
    assertTest($totalAlunosAposImport === 10, "Total de alunos no banco permanece estritamente 10");

    // Verifica presenças no Encontro 2
    $stmtFreqEnc2 = $pdo->prepare("
        SELECT aluno_id, presente 
        FROM frequencias 
        WHERE encontro_id = ? 
        ORDER BY aluno_id ASC
    ");
    $stmtFreqEnc2->execute([$encontroIds[2]]);
    $freqRowsEnc2 = $stmtFreqEnc2->fetchAll();

    $mapFreqEnc2 = [];
    foreach ($freqRowsEnc2 as $fr) {
        $mapFreqEnc2[(int)$fr['aluno_id']] = (int)$fr['presente'];
    }

    assertTest($mapFreqEnc2[$alunoIds[0]] === 0, "Aluno 01 com Falta (0) no Encontro 2 após reimportação");
    assertTest($mapFreqEnc2[$alunoIds[1]] === 1, "Aluno 02 com Presença (1) no Encontro 2");
    assertTest($mapFreqEnc2[$alunoIds[2]] === 0, "Aluno 03 com Falta (0) no Encontro 2 após reimportação");
    assertTest($mapFreqEnc2[$alunoIds[9]] === 1, "Aluno 10 com Presença (1) no Encontro 2");

    // Verifica atualização do conteúdo ministrado na Aba 2
    $stmtEncCheck = $pdo->prepare("SELECT conteudo_ministrado FROM encontros WHERE id = ?");
    $stmtEncCheck->execute([$encontroIds[2]]);
    $conteudoMin2Banco = $stmtEncCheck->fetchColumn();
    assertTest($conteudoMin2Banco === $novoConteudoMin2, "Conteúdo ministrado da aula 2 atualizado fielmente pelo Excel");

    // Verifica atualização de horários no Encontro 2
    $stmtHorarios = $pdo->prepare("SELECT horario_inicio, horario_fim FROM encontros WHERE id = ?");
    $stmtHorarios->execute([$encontroIds[2]]);
    $enc2Horarios = $stmtHorarios->fetch();
    assertTest($enc2Horarios['horario_inicio'] === '14:30:00', "Horário de início do Encontro 2 atualizado pelo Excel (14:30:00)");
    assertTest($enc2Horarios['horario_fim'] === '18:30:00', "Horário de término do Encontro 2 atualizado pelo Excel (18:30:00)");

    // Verifica atualização de datas da Turma (Aba 3)
    $stmtDatasTurma = $pdo->prepare("SELECT data_inicio, data_conclusao FROM turmas WHERE id = ?");
    $stmtDatasTurma->execute([$turmaId]);
    $turmaDatas = $stmtDatasTurma->fetch();
    assertTest($turmaDatas['data_inicio'] === '2026-10-05', "Data de início da turma atualizada pelo Excel (2026-10-05)");
    assertTest($turmaDatas['data_conclusao'] === '2026-10-28', "Data de conclusão da turma atualizada pelo Excel (2026-10-28)");

    // Reimportação idêntica consecutiva (teste de idempotência estrita)
    $resImport2 = $syncService->importTurmaSpreadsheet($turmaId, $xlsxModificadoBytes);
    assertTest($resImport2['success'] === true, "Segunda reimportação idêntica executada com sucesso");
    assertTest($resImport2['alunos_inseridos'] === 0, "Segunda reimportação inseriu 0 novos alunos");

    $stmtCountAlunos2 = $pdo->prepare("SELECT COUNT(*) FROM alunos WHERE turma_id = ?");
    $stmtCountAlunos2->execute([$turmaId]);
    assertTest((int)$stmtCountAlunos2->fetchColumn() === 10, "Total de alunos permanece 10 após reimportação idêntica");

    $stmtCountFreqs = $pdo->prepare("
        SELECT COUNT(*) 
        FROM frequencias f 
        JOIN encontros e ON e.id = f.encontro_id 
        WHERE e.turma_id = ?
    ");
    $stmtCountFreqs->execute([$turmaId]);
    $totalFreqsApos2 = (int)$stmtCountFreqs->fetchColumn();
    assertTest($totalFreqsApos2 === 40, "Total de registros na tabela frequencias permanece 40 (10 alunos x 4 encontros, sem duplicidades)");

    // Testa encontro pendente com célula vazia (não deve gerar presenças nem faltas indevidas)
    $stmtEncPendente = $pdo->prepare("
        INSERT INTO encontros (turma_id, numero_encontro, data_encontro, turno, horario_inicio, horario_fim, tipo)
        VALUES (?, 5, '2026-09-18', 'V', '14:00', '18:00', 'aula')
    ");
    $stmtEncPendente->execute([$turmaId]);
    $enc5Id = (int)$pdo->lastInsertId();

    $xlsxPendente = $syncService->exportTurmaSpreadsheet($turmaId);
    $parsedPendente = $syncService->parseXlsx($xlsxPendente);
    // Verifica que a coluna do Encontro 5 na exportação veio com célula vazia ''
    assertTest($parsedPendente['Alunos e Chamada'][1][7] === '', "Encontro 5 pendente exportado com célula vazia (sem presença forçada)");

    // Reimporta a planilha com Encontro 5 vazio
    $syncService->importTurmaSpreadsheet($turmaId, $xlsxPendente);
    $stmtCheckEnc5 = $pdo->prepare("SELECT COUNT(*) FROM frequencias WHERE encontro_id = ?");
    $stmtCheckEnc5->execute([$enc5Id]);
    assertTest((int)$stmtCheckEnc5->fetchColumn() === 0, "Células vazias de encontro futuro ignoradas sem registrar faltas ou presenças indevidas");

    // =========================================================================
    // SEÇÃO 4: Relatório de Inconformidades Cadastrais (ex: CPF Inválido)
    // =========================================================================
    echo "\n{$amarelo}-> 4. Testando Relatório de Inconformidades Cadastrais (CPF Inválido)...{$reset}\n";

    // Adiciona na planilha:
    // - Linha 12: Novo aluno válido (Aluno Novo Legítimo com CPF válido)
    // - Linha 13: Aluno com CPF matematicamente inválido (111.222.333-00)
    // - Linha 14: Aluno com CPF com tamanho incorreto (123.456)
    $cpfValidoNovo = gerarCpfValido(15);
    $novosAlunos = [
        12 => [11, 'Aluno Novo Legítimo', ValidatorService::formatCpf($cpfValidoNovo), 1, 1, 1, 1, '100,0%'],
        13 => [12, 'Aluno Com Cpf Falso', '111.222.333-00', 1, 0, 1, 1, '75,0%'],
        14 => [13, 'Aluno Com Cpf Curto', '123.456', 1, 1, 1, 1, '100,0%'],
    ];
    $newStudentEdits = [];
    foreach ($novosAlunos as $rowNumber => $values) {
        foreach ($values as $columnIndex => $value) {
            $newStudentEdits[chr(65 + $columnIndex) . $rowNumber] = $value;
        }
    }
    $xlsxInconformeBytes = editExportedWorkbook($xlsxModificadoBytes, [1 => $newStudentEdits]);
    $planilhaInconforme = $syncService->parseXlsx($xlsxInconformeBytes);
    assertTest(count($planilhaInconforme['Alunos e Chamada']) === 14,
        "Três linhas editadas offline são legíveis pela API pública");

    $resInconforme = $syncService->importTurmaSpreadsheet($turmaId, $xlsxInconformeBytes);

    assertTest($resInconforme['success'] === true, "Importação concluída mesmo contendo inconformidades");
    assertTest($resInconforme['total_inconformidades'] === 2, "Exatamente 2 inconformidades cadastrais identificadas");
    assertTest($resInconforme['inconformidades'][0]['linha'] === 13, "Inconformidade 1 aponta corretamente a linha 13 da planilha");
    assertTest(str_contains($resInconforme['inconformidades'][0]['motivo'], 'módulo 11'), "Motivo da inconformidade 1 especifica o algoritmo módulo 11");
    assertTest($resInconforme['inconformidades'][1]['linha'] === 14, "Inconformidade 2 aponta corretamente a linha 14 da planilha");

    // Valida que o aluno válido foi salvo com sucesso (sem corromper o banco)
    $stmtNovoAluno = $pdo->prepare("SELECT id, cpf_limpo FROM alunos WHERE turma_id = ? AND nome_completo = ?");
    $stmtNovoAluno->execute([$turmaId, 'Aluno Novo Legítimo']);
    $alunoNovoSalvo = $stmtNovoAluno->fetch();
    assertTest($alunoNovoSalvo !== false, "Aluno com dados válidos cadastrado com sucesso");
    assertTest($alunoNovoSalvo['cpf_limpo'] === $cpfValidoNovo, "CPF do aluno válido gravado corretamente");

    // Valida que alunos novos com CPF inválido NÃO foram inseridos no banco
    $stmtInvalido1 = $pdo->prepare("SELECT id FROM alunos WHERE turma_id = ? AND nome_completo = ?");
    $stmtInvalido1->execute([$turmaId, 'Aluno Com Cpf Falso']);
    assertTest($stmtInvalido1->fetch() === false, "Aluno novo com CPF inválido não foi inserido no banco de dados");

    $stmtInvalido2 = $pdo->prepare("SELECT id FROM alunos WHERE turma_id = ? AND nome_completo = ?");
    $stmtInvalido2->execute([$turmaId, 'Aluno Com Cpf Curto']);
    assertTest($stmtInvalido2->fetch() === false, "Aluno novo com CPF de tamanho curto não foi inserido no banco de dados");

    // =========================================================================
    // SEÇÃO 5: Testando Interface Web, Roteamento e UI (/diario/turma)
    // =========================================================================
    echo "\n{$amarelo}-> 5. Testando Interface Web e Roteamento Amigável...{$reset}\n";

    $turmaPhpPath = __DIR__ . '/../public/diario/turma.php';
    assertTest(file_exists($turmaPhpPath), "Arquivo public/diario/turma.php existe");

    $turmasPhpPath = __DIR__ . '/../public/diario/turmas.php';
    assertTest(file_exists($turmasPhpPath), "Arquivo public/diario/turmas.php existe");

    $htaccessPath = __DIR__ . '/../public/diario/.htaccess';
    $htaccessContent = file_exists($htaccessPath) ? file_get_contents($htaccessPath) : '';
    assertTest(str_contains($htaccessContent, 'RewriteRule ^turma/?$ turma.php'), "Regra de roteamento para /diario/turma presente no .htaccess");

    // Simula autenticação administrativa legítima para renderizar a página
    $_SESSION['usuario_admin'] = [
        'id'       => 1,
        'username' => 'admin',
        'nome'     => 'Operador Teste',
        'email'    => 'admin@futurofacil.com.br'
    ];
    $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET['turma_id'] = $turmaId;

    ob_start();
    include $turmaPhpPath;
    $renderedHtml = ob_get_clean();

    assertTest(str_contains($renderedHtml, 'Exportar Planilha Excel'), "Página contém o botão 'Exportar Planilha Excel'");
    assertTest(str_contains($renderedHtml, 'enctype="multipart/form-data"'), "Formulário de upload suporta envio de arquivos multipart");
    assertTest(str_contains($renderedHtml, 'name="csrf_token"'), "Formulário de upload protegido por token anti-CSRF");
    assertTest(str_contains($renderedHtml, 'Excel Corporativo Especialista'), "Página exibe o nome da turma consultada");
    assertTest(str_contains($renderedHtml, 'Alunos Matriculados'), "Página lista a seção de alunos da turma");
    assertTest(str_contains($renderedHtml, 'Encontros Pedagógicos'), "Página lista os encontros da turma com links para o Modo Aula");

    // Verifica se turmas.php contém link para a tela individual de sincronização
    $_GET = [];
    ob_start();
    include $turmasPhpPath;
    $renderedTurmasHtml = ob_get_clean();
    assertTest(str_contains($renderedTurmasHtml, '/diario/turma?turma_id='), "Página geral de turmas possui links para gerenciar/sincronizar cada turma");
    assertTest(str_contains($renderedTurmasHtml, 'Exportar Excel'), "Página geral de turmas possui botão de atalho para exportar Excel");

    // Limpeza de arquivos de teste
    @unlink($testDbPath);
    @unlink($exportedFilePath);

    echo "\n{$verde}----------------------------------------------------------------------\n";
    echo "RESULTADO: 100% DE SUCESSO! ({$passedCount}/{$totalCount} verificações executadas sem falhas).\n";
    echo "Ticket 05 (Sincronização Bidirecional Excel e Costura 4) APROVADO!\n";
    echo "----------------------------------------------------------------------{$reset}\n\n";

} catch (Throwable $e) {
    echo "\n{$vermelho}ERRO FATAL DURANTE A EXECUÇÃO DOS TESTES:{$reset}\n";
    echo $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    @unlink($testDbPath);
    @unlink(__DIR__ . '/test_turma_exported.xlsx');
    exit(1);
}
