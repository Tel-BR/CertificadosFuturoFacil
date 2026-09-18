<?php
/**
 * Suíte de Testes Automatizados — Ticket 11: Sincronização Excel com Prévia de Diff Visual e Preservação de Dados
 * Cobertura TDD:
 *  - Costura 1: Serviço de Diff em Memória (ExcelSyncService::generateDiff)
 *  - Costura 2: Regra de Preservação Estrita de Seções Vazias
 *  - Costura 3: Reagendamento Seguro de Aulas com Presenças e Conteúdos Preservados
 *  - Costura 4: Borda de Interface Web, Prévia com Diff Visual e Transação Atômica (turma.php)
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../src/Config/Database.php';
require_once __DIR__ . '/../src/Services/ValidatorService.php';
require_once __DIR__ . '/../src/Services/AttendanceService.php';
require_once __DIR__ . '/../src/Services/CalendarService.php';
require_once __DIR__ . '/../src/Services/TurmaService.php';
require_once __DIR__ . '/../src/Services/ExcelSyncService.php';
require_once __DIR__ . '/../src/Services/AuthService.php';

use FuturoFacil\Config\Database;
use FuturoFacil\Services\ValidatorService;
use FuturoFacil\Services\AttendanceService;
use FuturoFacil\Services\CalendarService;
use FuturoFacil\Services\TurmaService;
use FuturoFacil\Services\ExcelSyncService;
use FuturoFacil\Services\AuthService;

$verde = "\033[32m";
$vermelho = "\033[31m";
$amarelo = "\033[33m";
$azul = "\033[36m";
$reset = "\033[0m";

echo "{$azul}======================================================================\n";
echo " Executando Suíte de Testes — Ticket 11: Diff Visual & Preservação Excel\n";
echo "======================================================================{$reset}\n\n";

$testDbPath = __DIR__ . '/test_temp_ticket_11.db';
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

/** Edita células de uma cópia do arquivo exportado para simular edição offline no Excel. */
function editWorkbookForTest(string $xlsxBinary, array $edits): string
{
    $path = tempnam(sys_get_temp_dir(), 'ff_test11_edit_');
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

                while ($cell->hasChildNodes()) {
                    $cell->removeChild($cell->firstChild);
                }

                $namespace = $cell->namespaceURI ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
                if ($value === null || $value === '') {
                    $cell->removeAttribute('t');
                } elseif (is_numeric($value)) {
                    $cell->removeAttribute('t');
                    $valNode = $document->createElementNS($namespace, 'v', (string)$value);
                    $cell->appendChild($valNode);
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

// Gerador de CPFs válidos
function gerarCpfValidoT11(int $seed): string
{
    $base = str_pad((string)(300000000 + $seed), 9, '0', STR_PAD_LEFT);
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

try {
    // 1. Inicializa banco SQLite isolado
    Database::setConfig([
        'driver'   => 'sqlite',
        'database' => $testDbPath,
    ]);
    $pdo = Database::getConnection();
    $schemaSql = file_get_contents(__DIR__ . '/../database/schema_sqlite.sql');
    $pdo->exec($schemaSql);

    // 2. Cria turma de teste com 5 alunos e 3 encontros
    $pdo->exec("
        INSERT INTO turmas (
            codigo_turma, curso_nome, cliente_nome, ordem_servico,
            data_inicio, data_conclusao, turno_padrao, status, chave_acesso,
            carga_horaria, instrutor, cidade, ementa
        ) VALUES (
            'TURMA-DIFF-TEST', 'Análise de Dados com Power BI', 'Cooperativa Agro', 'OS-2026-011',
            '2026-10-01', '2026-10-03', 'V', 'em_andamento', 'CHAVE-DIFF-2026',
            12, 'Telmo Tropia', 'Goiânia - GO', 'Modelagem, DAX e Visualização de Dados'
        )
    ");
    $turmaId = (int)$pdo->lastInsertId();

    $encontrosData = [
        [1, '2026-10-01', '14:00', '18:00', 'Modelagem Dimensional e Star Schema', 'Aula ministrada com Star Schema e ETL.', 'aula', 0],
        [2, '2026-10-02', '14:00', '18:00', 'Medidas DAX Essenciais e CALCULATE', 'Aula ministrada com CALCULATE e time intelligence.', 'aula', 0],
        [3, '2026-10-03', '14:00', '18:00', 'Design de Relatórios e Publicação', null, 'aula', 0],
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

    $stmtAluno = $pdo->prepare("
        INSERT INTO alunos (turma_id, nome_completo, cpf, cpf_limpo, cpf_mascarado)
        VALUES (?, ?, ?, ?, ?)
    ");
    $alunoIds = [];
    for ($i = 1; $i <= 5; $i++) {
        $nome = "Aluno Teste " . str_pad((string)$i, 2, '0', STR_PAD_LEFT);
        $cpfLimpo = gerarCpfValidoT11($i);
        $cpfFormatado = ValidatorService::formatCpf($cpfLimpo);
        $cpfMascarado = ValidatorService::maskCpf($cpfLimpo);
        $stmtAluno->execute([$turmaId, $nome, $cpfFormatado, $cpfLimpo, $cpfMascarado]);
        $alunoIds[] = (int)$pdo->lastInsertId();
    }

    // Registra chamada: Encontro 1 e 2 com chamada realizada (todos presentes); Encontro 3 pendente
    $stmtFreqInit = $pdo->prepare("INSERT INTO frequencias (encontro_id, aluno_id, presente) VALUES (?, ?, ?)");
    foreach ($alunoIds as $aId) {
        $stmtFreqInit->execute([$encontroIds[1], $aId, 1]);
        $stmtFreqInit->execute([$encontroIds[2], $aId, 1]);
    }

    $syncService = new ExcelSyncService($pdo);

    // =========================================================================
    // COSTURA 1: Análise em Memória e Diff Visual (generateDiff)
    // =========================================================================
    echo "\n{$amarelo}-> 1. Testando Análise em Memória e Estrutura do Diff Visual...{$reset}\n";

    $baseXlsx = $syncService->exportTurmaSpreadsheet($turmaId);
    assertTest(strlen($baseXlsx) > 1000, "Planilha base exportada com sucesso");

    // Prepara edições para testar todas as categorias do Diff:
    // - Linha 2 (Aluno 01): Nome alterado para 'Aluno Teste 01 Editado'
    // - Linha 3 (Aluno 02): Presença no Encontro 2 alterada para 0 (Falta)
    // - Linha 7 (Novo Aluno 1): 'Novo Aluno Válido', CPF válido novo
    // - Linha 8 (Novo Aluno 2): 'Novo Aluno Inválido', CPF matematicamente incorreto
    // - Aba 2 Linha 3 (Encontro 2): Reagendamento seguro da aula de 2026-10-02 para 2026-10-05
    // - Aba 2 Linha 4 (Encontro 3): Aula 3 atualizada com novo conteúdo previsto
    $novoCpfValido = gerarCpfValidoT11(99);
    $editsDiff = [
        1 => [
            'B2' => 'Aluno Teste 01 Editado', // Edição de nome
            'E3' => '0',                      // Presença alterada para falta
            'A7' => '6', 'B7' => 'Novo Aluno Válido', 'C7' => ValidatorService::formatCpf($novoCpfValido), 'D7' => '1',
            'A8' => '7', 'B8' => 'Novo Aluno Inválido', 'C8' => '123.456.789-00', 'D8' => '1',
        ],
        2 => [
            'B3' => '05/10/2026', // Reagendamento de aula com chamada realizada!
            'D3' => '14:30', 'E3' => '18:30',
            'H4' => 'Design avançado com Bookmarks e Tooltips', // Novo conteúdo previsto
        ],
        3 => [
            'B2' => 'Análise de Dados Avançada com Power BI', // Título alterado
        ]
    ];

    $modifiedXlsx = editWorkbookForTest($baseXlsx, $editsDiff);

    // Executa generateDiff em memória
    $diff = $syncService->generateDiff($turmaId, $modifiedXlsx);

    assertTest(is_array($diff), "generateDiff retornou array estruturado");
    assertTest(isset($diff['resumo']), "Diff contém seção 'resumo'");
    assertTest($diff['resumo']['total_novos_alunos'] === 1, "Exatamente 1 novo aluno válido detectado (excluindo CPF inválido)");
    assertTest($diff['resumo']['total_atualizar_alunos'] === 2, "Exatamente 2 alunos existentes a atualizar (1 por nome, 1 por presença)");
    assertTest($diff['resumo']['total_inalterados_alunos'] === 3, "Exatamente 3 alunos existentes inalterados");
    assertTest($diff['resumo']['total_inconformidades'] === 1, "Exatamente 1 inconformidade cadastral identificada (CPF inválido)");
    assertTest($diff['resumo']['total_encontros_alterados'] >= 2, "Pelo menos 2 encontros com alterações identificados");
    assertTest($diff['resumo']['total_reagendamentos_seguros'] >= 1, "Pelo menos 1 reagendamento seguro detectado em aula com presenças");

    // Verifica que generateDiff NÃO persistiu nada no banco de dados (análise estritamente em memória)
    $stmtCheckNome = $pdo->prepare("SELECT nome_completo FROM alunos WHERE id = ?");
    $stmtCheckNome->execute([$alunoIds[0]]);
    $nomeAtual = $stmtCheckNome->fetchColumn();
    assertTest($nomeAtual === 'Aluno Teste 01', "generateDiff NÃO alterou o nome do aluno no banco de dados");

    $stmtCheckCount = $pdo->prepare("SELECT COUNT(*) FROM alunos WHERE turma_id = ?");
    $stmtCheckCount->execute([$turmaId]);
    $totalAtualAlunos = (int)$stmtCheckCount->fetchColumn();
    assertTest($totalAtualAlunos === 5, "generateDiff NÃO inseriu novos alunos no banco de dados (total permanece 5)");

    // Verifica alerta de Reagendamento Seguro no diff
    $encontrosAlterados = $diff['encontros']['alterados'] ?? [];
    $reagendamentoEncontrado = false;
    foreach ($encontrosAlterados as $ea) {
        if ($ea['numero'] === 2 && !empty($ea['reagendamento_com_chamada'])) {
            $reagendamentoEncontrado = true;
            assertTest(!empty($ea['aviso']), "Aviso de chamada realizada preservada presente no encontro reagendado");
        }
    }
    assertTest($reagendamentoEncontrado, "Encontro 2 classificado corretamente como Reagendamento Seguro com chamadas existentes");

    // =========================================================================
    // COSTURA 2: Regra Estrita de Preservação de Seções Vazias
    // =========================================================================
    echo "\n{$amarelo}-> 2. Testando Regra Estrita de Preservação de Seções Vazias...{$reset}\n";

    // Cria planilha com Aba 1 (Alunos) vazia (apenas cabeçalhos limpos, sem linhas de alunos)
    $limpezaAba1 = [];
    for ($r = 2; $r <= 8; $r++) {
        $limpezaAba1["A{$r}"] = '';
        $limpezaAba1["B{$r}"] = '';
        $limpezaAba1["C{$r}"] = '';
        $limpezaAba1["D{$r}"] = '';
        $limpezaAba1["E{$r}"] = '';
        $limpezaAba1["F{$r}"] = '';
    }
    // E altera uma data na Aba 2 para simular operador que só queria ajustar calendário
    $editsAbaVazia = [
        1 => $limpezaAba1,
        2 => [
            'B4' => '06/10/2026', // Encontro 3 reagendado
        ]
    ];
    $xlsxAbaVazia = editWorkbookForTest($baseXlsx, $editsAbaVazia);

    $diffVazio = $syncService->generateDiff($turmaId, $xlsxAbaVazia);
    assertTest($diffVazio['resumo']['aba_alunos_vazia'] === true, "Diff detectou que a aba de Alunos está vazia");
    assertTest($diffVazio['resumo']['preservacao_alunos_ativa'] === true, "Regra de Preservação Ativa sinalizada no Diff");
    assertTest($diffVazio['resumo']['total_ausentes_preservados'] === 5, "Todos os 5 alunos existentes marcados como preservados");

    // Executa a importação com aba vazia
    $resImportVazio = $syncService->importTurmaSpreadsheet($turmaId, $xlsxAbaVazia);
    assertTest($resImportVazio['success'] === true, "Importação com aba de alunos vazia concluída sem erro");

    // Verifica que 100% dos alunos e chamadas continuam no banco
    $stmtAlunosApos = $pdo->prepare("SELECT COUNT(*) FROM alunos WHERE turma_id = ?");
    $stmtAlunosApos->execute([$turmaId]);
    assertTest((int)$stmtAlunosApos->fetchColumn() === 5, "Regra de Preservação manteve 100% dos alunos intactos no banco (5 alunos)");

    $stmtFreqApos = $pdo->prepare("
        SELECT COUNT(*) FROM frequencias f
        JOIN encontros e ON e.id = f.encontro_id
        WHERE e.turma_id = ?
    ");
    $stmtFreqApos->execute([$turmaId]);
    assertTest((int)$stmtFreqApos->fetchColumn() === 10, "100% das presenças anteriores (10 registros) mantidas intactas");

    // =========================================================================
    // COSTURA 3: Reagendamento Seguro de Aulas Realizadas
    // =========================================================================
    echo "\n{$amarelo}-> 3. Testando Reagendamento Seguro de Aulas Realizadas...{$reset}\n";

    // Encontro 1 tem data 2026-10-01, chamada realizada (5 presentes) e conteudo_ministrado
    // 'Aula ministrada com Star Schema e ETL.'
    // Vamos reagendar para 2026-10-10 com célula de conteudo_ministrado vazia no Excel
    $editsReagendamento = [
        2 => [
            'B2' => '10/10/2026', // Nova data
            'D2' => '13:00', 'E2' => '17:00', // Novo horário (Vespertino)
            'H2' => '', // Célula vazia de conteúdo ministrado: DEVE PRESERVAR O ANTERIOR!
        ]
    ];
    $xlsxReagendamento = editWorkbookForTest($baseXlsx, $editsReagendamento);

    $diffReag = $syncService->generateDiff($turmaId, $xlsxReagendamento);
    assertTest($diffReag['resumo']['total_reagendamentos_seguros'] >= 1, "Diff apontou reagendamento seguro para Encontro 1");

    // Aplica sincronização
    $resReag = $syncService->importTurmaSpreadsheet($turmaId, $xlsxReagendamento);
    assertTest($resReag['success'] === true, "Sincronização do reagendamento aplicada com sucesso");

    // Verifica que data e horário foram atualizados no banco
    $stmtEnc1 = $pdo->prepare("SELECT data_encontro, horario_inicio, horario_fim, conteudo_ministrado FROM encontros WHERE turma_id = ? AND numero_encontro = 1");
    $stmtEnc1->execute([$turmaId]);
    $enc1Row = $stmtEnc1->fetch(PDO::FETCH_ASSOC);

    assertTest($enc1Row['data_encontro'] === '2026-10-10', "Data do Encontro 1 atualizada para 2026-10-10");
    assertTest(str_starts_with((string)$enc1Row['horario_inicio'], '13:00'), "Horário de início atualizado para 13:00");
    assertTest(str_starts_with((string)$enc1Row['horario_fim'], '17:00'), "Horário de término atualizado para 17:00");
    assertTest($enc1Row['conteudo_ministrado'] === 'Aula ministrada com Star Schema e ETL.', "Conteúdo ministrado anterior PRESERVADO (não foi apagado por célula vazia)");

    // Verifica que presenças continuam intactas para o Encontro 1
    $stmtFreqEnc1 = $pdo->prepare("
        SELECT COUNT(*) FROM frequencias f
        JOIN encontros e ON e.id = f.encontro_id
        WHERE e.turma_id = ? AND e.numero_encontro = 1 AND f.presente = 1
    ");
    $stmtFreqEnc1->execute([$turmaId]);
    assertTest((int)$stmtFreqEnc1->fetchColumn() === 5, "Todas as 5 presenças do Encontro 1 foram 100% preservadas na nova data");

    // =========================================================================
    // COSTURA 4: Borda de Interface Web, Prévia com Diff Visual e Transação Atômica
    // =========================================================================
    echo "\n{$amarelo}-> 4. Testando Borda de Interface Web e Transação Atômica...{$reset}\n";

    $turmaPhpPath = __DIR__ . '/../public/diario/turma.php';
    assertTest(file_exists($turmaPhpPath), "Arquivo public/diario/turma.php existe");

    // Simula autenticação
    $_SESSION['usuario_admin'] = [
        'id'       => 1,
        'username' => 'admin',
        'nome'     => 'Operador Teste',
        'email'    => 'admin@futurofacil.com.br'
    ];
    $csrfToken = bin2hex(random_bytes(32));
    $_SESSION['admin_csrf_token'] = $csrfToken;

    // Simula upload com ação preview_sync
    $tempUploadPath = tempnam(sys_get_temp_dir(), 'ff_test_up_');
    file_put_contents($tempUploadPath, $modifiedXlsx);

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_GET['turma_id'] = $turmaId;
    $_POST = [
        'action'     => 'preview_sync',
        'csrf_token' => $csrfToken,
    ];
    $_FILES = [
        'planilha' => [
            'name'     => 'turma_editada.xlsx',
            'type'     => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'tmp_name' => $tempUploadPath,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($tempUploadPath),
        ]
    ];

    ob_start();
    include $turmaPhpPath;
    $renderedHtmlPreview = ob_get_clean();

    // Verificações na renderização do Diff Visual
    assertTest(str_contains($renderedHtmlPreview, 'Prévia de Sincronização'), "Interface renderiza o cabeçalho de Prévia de Sincronização");
    assertTest(str_contains($renderedHtmlPreview, 'Novos Alunos'), "Interface exibe contador de Novos Alunos");
    assertTest(str_contains($renderedHtmlPreview, 'Alunos a Atualizar'), "Interface exibe contador de Alunos a Atualizar");
    assertTest(str_contains($renderedHtmlPreview, 'Aulas Reagendadas'), "Interface exibe contador de Aulas Reagendadas");
    assertTest(str_contains($renderedHtmlPreview, 'Confirmar e Aplicar Sincronização'), "Interface contém botão explícito 'Confirmar e Aplicar Sincronização'");
    assertTest(str_contains($renderedHtmlPreview, 'Cancelar'), "Interface contém botão/link explícito 'Cancelar'");
    assertTest(str_contains($renderedHtmlPreview, 'Novo Aluno Válido'), "Interface lista o novo aluno a ser cadastrado");
    assertTest(!str_contains($renderedHtmlPreview, '⚠️'), "Interface segue estritamente ADR 0006 sem uso do emoji de alerta");

    // Verifica que token temporário de sincronização foi gravado na sessão
    assertTest(!empty($_SESSION['pending_sync'][$turmaId]), "Sessão armazena estado de sincronização pendente para confirmação atômica");
    $pendingSyncToken = $_SESSION['pending_sync'][$turmaId]['token'] ?? '';

    // Simula confirmação (apply_sync)
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = [
        'action'       => 'apply_sync',
        'csrf_token'   => $csrfToken,
        'sync_token'   => $pendingSyncToken,
    ];
    $_FILES = [];

    ob_start();
    include $turmaPhpPath;
    $renderedHtmlApply = ob_get_clean();

    assertTest(str_contains($renderedHtmlApply, 'Sincronização aplicada com sucesso'), "Aplicação da sincronização emite feedback visual de sucesso");
    assertTest(empty($_SESSION['pending_sync'][$turmaId]), "Sessão pendente limpa após aplicação bem-sucedida");

    // Verifica no banco que o novo aluno foi realmente inserido agora
    $stmtNewAluno = $pdo->prepare("SELECT id FROM alunos WHERE turma_id = ? AND nome_completo = ?");
    $stmtNewAluno->execute([$turmaId, 'Novo Aluno Válido']);
    assertTest($stmtNewAluno->fetch() !== false, "Novo aluno foi persistido atomicamente no banco de dados após confirmação");

    // Limpeza
    if (file_exists($tempUploadPath)) {
        unlink($tempUploadPath);
    }
    $pdo = null;
    if (file_exists($testDbPath)) {
        @unlink($testDbPath);
    }

    echo "\n----------------------------------------------------------------------\n";
    echo "RESULTADO: {$passedCount}/{$totalCount} asserções passaram.\n";
    if ($passedCount === $totalCount) {
        echo "{$verde}100% DOS TESTES DO TICKET 11 FORAM APROVADOS!{$reset}\n";
    } else {
        echo "{$vermelho}HOUVE FALHAS NA SUÍTE DE TESTES DO TICKET 11!{$reset}\n";
    }
    echo "----------------------------------------------------------------------\n";

} catch (Throwable $e) {
    echo "{$vermelho}ERRO FATAL DURANTE TESTE: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "{$reset}\n";
    $pdo = null;
    if (file_exists($testDbPath)) {
        @unlink($testDbPath);
    }
    exit(1);
}
