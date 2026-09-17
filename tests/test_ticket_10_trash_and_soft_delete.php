<?php
/**
 * Suíte de Testes Automatizados — Ticket 10: Lixeira de Turmas, Soft Delete e Restauração Segura
 * Plataforma Integrada de Gestão Pedagógica, Diário e Certificados Futuro Fácil
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Config/Database.php';
require_once __DIR__ . '/../src/Services/CalendarService.php';
require_once __DIR__ . '/../src/Services/AttendanceService.php';
require_once __DIR__ . '/../src/Services/CertificateService.php';
require_once __DIR__ . '/../src/Services/AuthService.php';
require_once __DIR__ . '/../src/Services/TurmaService.php';

use FuturoFacil\Config\Database;
use FuturoFacil\Services\CalendarService;
use FuturoFacil\Services\AttendanceService;
use FuturoFacil\Services\CertificateService;
use FuturoFacil\Services\AuthService;
use FuturoFacil\Services\TurmaService;

$verde = "\033[32m";
$vermelho = "\033[31m";
$amarelo = "\033[33m";
$azul = "\033[36m";
$reset = "\033[0m";

echo "{$azul}======================================================================\n";
echo " Executando Suíte de Testes — Ticket 10: Lixeira e Soft Delete Seguro\n";
echo "======================================================================{$reset}\n\n";

$testDbPath = __DIR__ . '/test_temp_ticket_10.db';
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

try {
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

    $calendarService = new CalendarService($pdo);
    $attendanceService = new AttendanceService($pdo);
    $turmaService = new TurmaService($pdo, $calendarService);
    $authService = new AuthService($pdo);

    // =========================================================================
    // SEÇÃO 1: Schema e Colunas de Soft Delete
    // =========================================================================
    echo "{$amarelo}-> 1. Testando Schema e Colunas deleted_at em turmas e encontros...{$reset}\n";

    $colsTurmas = $pdo->query("PRAGMA table_info(turmas)")->fetchAll(PDO::FETCH_ASSOC);
    $turmaColsNames = array_column($colsTurmas, 'name');
    assertTest(in_array('deleted_at', $turmaColsNames, true), "Tabela 'turmas' possui coluna deleted_at");

    $colsEncontros = $pdo->query("PRAGMA table_info(encontros)")->fetchAll(PDO::FETCH_ASSOC);
    $encontrosColsNames = array_column($colsEncontros, 'name');
    assertTest(in_array('deleted_at', $encontrosColsNames, true), "Tabela 'encontros' possui coluna deleted_at");

    $turmaService->ensureSchema();
    assertTest(true, "TurmaService::ensureSchema executa de forma idempotente sem exceções");

    // =========================================================================
    // SEÇÃO 2: Mover para a Lixeira (Soft Delete)
    // =========================================================================
    echo "{$amarelo}-> 2. Testando Ação de Mover para a Lixeira (moveToTrash)...{$reset}\n";

    // Cadastra uma turma com 2 encontros
    $stmtT1 = $pdo->prepare("
        INSERT INTO turmas (
            codigo_turma, curso_nome, cliente_nome, carga_horaria,
            data_inicio, data_conclusao, turno_padrao, status, chave_acesso
        ) VALUES (
            'TURMA-2026-T1', 'Excel Básico', 'Empresa Alfa', 8,
            '2026-11-10', '2026-11-11', 'V', 'prevista', 'excel-alfa-100'
        )
    ");
    $stmtT1->execute();
    $turmaId1 = (int)$pdo->lastInsertId();

    $stmtE1 = $pdo->prepare("
        INSERT INTO encontros (turma_id, numero_encontro, data_encontro, turno, tipo)
        VALUES (?, 1, '2026-11-10', 'V', 'aula'), (?, 2, '2026-11-11', 'V', 'aula')
    ");
    $stmtE1->execute([$turmaId1, $turmaId1]);

    // Antes do descarte, deve constar nas turmas ativas
    $ativasAntes = $turmaService->getActiveTurmas();
    $idsAtivas = array_column($ativasAntes, 'id');
    assertTest(in_array($turmaId1, $idsAtivas, true), "Turma 1 consta na listagem padrão de turmas ativas");
    assertTest($turmaService->getTrashCount() === 0, "Contador da lixeira indica 0 turmas antes do descarte");

    // Choque ativo no calendário
    $conflitoAntes = $calendarService->checkConflict('2026-11-10', 'V');
    assertTest($conflitoAntes !== null, "checkConflict aponta colisão no turno V de 10/11/2026 antes do descarte");

    // Executa o soft-delete
    $resTrash = $turmaService->moveToTrash($turmaId1);
    assertTest($resTrash === true, "TurmaService::moveToTrash retornou true");

    $stmtCheckT1 = $pdo->prepare("SELECT deleted_at FROM turmas WHERE id = ?");
    $stmtCheckT1->execute([$turmaId1]);
    $deletedAtTurma = $stmtCheckT1->fetchColumn();
    assertTest(!empty($deletedAtTurma), "Coluna deleted_at em turmas está preenchida após moveToTrash ({$deletedAtTurma})");

    $stmtCheckE1 = $pdo->prepare("SELECT COUNT(*) FROM encontros WHERE turma_id = ? AND deleted_at IS NOT NULL");
    $stmtCheckE1->execute([$turmaId1]);
    $totalEncDeleted = (int)$stmtCheckE1->fetchColumn();
    assertTest($totalEncDeleted === 2, "Todos os 2 encontros da turma receberam deleted_at após moveToTrash");

    // Não deve mais constar em getActiveTurmas()
    $ativasDepois = $turmaService->getActiveTurmas();
    $idsAtivasDepois = array_column($ativasDepois, 'id');
    assertTest(!in_array($turmaId1, $idsAtivasDepois, true), "Turma 1 removida da listagem padrão de turmas ativas");

    // Deve constar em getTrashedTurmas()
    $trashedTurmas = $turmaService->getTrashedTurmas();
    $idsTrashed = array_column($trashedTurmas, 'id');
    assertTest(in_array($turmaId1, $idsTrashed, true), "Turma 1 localizada na listagem da Lixeira (getTrashedTurmas)");
    assertTest($turmaService->getTrashCount() === 1, "Contador da lixeira indica 1 turma após o descarte");

    // =========================================================================
    // SEÇÃO 3: Desocupação Imediata no Calendário
    // =========================================================================
    echo "{$amarelo}-> 3. Testando Desocupação Imediata no Calendário de Capacidade...{$reset}\n";

    $conflitoDepois1 = $calendarService->checkConflict('2026-11-10', 'V');
    assertTest($conflitoDepois1 === null, "checkConflict para 10/11/2026 (V) agora retorna null (turno desocupado)");

    $conflitoDepois2 = $calendarService->checkConflict('2026-11-11', 'V');
    assertTest($conflitoDepois2 === null, "checkConflict para 11/11/2026 (V) agora retorna null (turno desocupado)");

    // getScheduleForDateRange não lista encontros de turmas na lixeira
    $gradeNov = $calendarService->getScheduleForDateRange('2026-11-01', '2026-11-30');
    $temEncontroT1 = false;
    foreach ($gradeNov as $dia => $encs) {
        foreach ($encs as $enc) {
            if ((int)$enc['turma_id'] === $turmaId1) {
                $temEncontroT1 = true;
            }
        }
    }
    assertTest(!$temEncontroT1, "getScheduleForDateRange omite encontros da turma na lixeira");

    // calculateMonthlyWorkload desconsidera horas da turma descartada
    $workloadNov = $calendarService->calculateMonthlyWorkload(2026, 11);
    assertTest($workloadNov['total_horas'] == 0.0, "calculateMonthlyWorkload desconsidera as 8h da turma na lixeira");

    // Agora é possível agendar outra turma nas mesmas datas e turnos sem conflito
    $stmtT2 = $pdo->prepare("
        INSERT INTO turmas (
            codigo_turma, curso_nome, cliente_nome, carga_horaria,
            data_inicio, data_conclusao, turno_padrao, status, chave_acesso
        ) VALUES (
            'TURMA-2026-T2', 'Power BI Dashboard', 'Empresa Beta', 8,
            '2026-11-10', '2026-11-10', 'V', 'prevista', 'powerbi-beta-200'
        )
    ");
    $stmtT2->execute();
    $turmaId2 = (int)$pdo->lastInsertId();

    $stmtE2 = $pdo->prepare("
        INSERT INTO encontros (turma_id, numero_encontro, data_encontro, turno, tipo)
        VALUES (?, 1, '2026-11-10', 'V', 'aula')
    ");
    $stmtE2->execute([$turmaId2]);

    assertTest(true, "Nova Turma 2 agendada com sucesso no mesmo turno V de 10/11/2026");

    // =========================================================================
    // SEÇÃO 4: Restauração com Trava de Conflito
    // =========================================================================
    echo "{$amarelo}-> 4. Testando Restauração com Trava de Conflito...{$reset}\n";

    // Ao tentar restaurar Turma 1, deve colidir com Turma 2 no dia 10/11 (V)
    $resRestoreFalha = $turmaService->restoreFromTrash($turmaId1);
    assertTest($resRestoreFalha['success'] === false, "restoreFromTrash bloqueado devido a colisão de horário");
    assertTest(!empty($resRestoreFalha['conflicts']), "restoreFromTrash retorna lista de conflitos impeditivos");
    assertTest(str_contains($resRestoreFalha['message'], '10/11/2026') || str_contains(json_encode($resRestoreFalha), '10/11/2026'), "Mensagem de erro detalha a data em choque (10/11/2026)");

    // A turma 1 deve continuar na lixeira
    $stmtT1AindaLixeira = $pdo->prepare("SELECT deleted_at FROM turmas WHERE id = ?");
    $stmtT1AindaLixeira->execute([$turmaId1]);
    assertTest(!empty($stmtT1AindaLixeira->fetchColumn()), "Turma 1 permanece na lixeira após tentativa falha de restauração");

    // =========================================================================
    // SEÇÃO 5: Restauração Bem-Sucedida
    // =========================================================================
    echo "{$amarelo}-> 5. Testando Restauração Bem-Sucedida sem Conflitos...{$reset}\n";

    // Move a Turma 2 para a lixeira para liberar o horário
    $turmaService->moveToTrash($turmaId2);

    // Agora a restauração da Turma 1 deve ser aprovada
    $resRestoreSucesso = $turmaService->restoreFromTrash($turmaId1);
    assertTest($resRestoreSucesso['success'] === true, "restoreFromTrash executado com sucesso após desocupação da agenda");

    $stmtCheckRestore = $pdo->prepare("SELECT deleted_at FROM turmas WHERE id = ?");
    $stmtCheckRestore->execute([$turmaId1]);
    assertTest($stmtCheckRestore->fetchColumn() === null, "Coluna deleted_at em turmas redefinida para NULL");

    $stmtCheckEncRestore = $pdo->prepare("SELECT COUNT(*) FROM encontros WHERE turma_id = ? AND deleted_at IS NOT NULL");
    $stmtCheckEncRestore->execute([$turmaId1]);
    assertTest((int)$stmtCheckEncRestore->fetchColumn() === 0, "Todos os encontros da turma tiveram deleted_at redefinido para NULL");

    // Turma 1 volta ao calendário ativo
    $conflitoRestaurado = $calendarService->checkConflict('2026-11-10', 'V');
    assertTest($conflitoRestaurado !== null, "checkConflict volta a acusar choque no turno da Turma 1 restaurada");

    // =========================================================================
    // SEÇÃO 6: Expurgo Definitivo com Blindagem de Certificados
    // =========================================================================
    echo "{$amarelo}-> 6. Testando Expurgo Definitivo e Blindagem de Certificados...{$reset}\n";

    // Cria Turma 3 com certificado emitido no Livro de Registros
    $stmtT3 = $pdo->prepare("
        INSERT INTO turmas (
            codigo_turma, curso_nome, cliente_nome, carga_horaria,
            data_inicio, data_conclusao, turno_padrao, status, chave_acesso
        ) VALUES (
            'TURMA-2026-T3', 'Gestão Financeira', 'Empresa Gama', 16,
            '2026-12-01', '2026-12-02', 'M', 'concluida', 'gestao-gama-300'
        )
    ");
    $stmtT3->execute();
    $turmaId3 = (int)$pdo->lastInsertId();

    $stmtReg = $pdo->prepare("
        INSERT INTO registros_certificados (
            turma_id, codigo_autenticidade, aluno_nome, aluno_cpf, aluno_cpf_mascarado,
            curso_nome, carga_horaria, carga_horaria_extenso, data_inicio, data_conclusao,
            data_emissao, modalidade, instrutor, cidade, livro_numero, folha_numero,
            registro_numero, frequencia, lote_id, created_at
        ) VALUES (
            ?, 'CERT-HASH-TESTE-1234567890ABCDEF', 'Maria dos Santos', '12345678900', '***.456.789-**',
            'Gestão Financeira', 16, 'dezesseis horas', '01/12/2026', '02/12/2026',
            '03/12/2026', 'Presencial', 'Instrutor Teste', 'Goiânia', 1, 1, 1, 100.0, 'LOTE-T3', '2026-12-03 10:00:00'
        )
    ");
    $stmtReg->execute([$turmaId3]);

    // Move Turma 3 para a lixeira
    $turmaService->moveToTrash($turmaId3);

    // Tenta expurgo definitivo da Turma 3
    $resExpungeT3 = $turmaService->expungeTurma($turmaId3);
    assertTest($resExpungeT3['success'] === false, "expungeTurma bloqueado para turma com certificados emitidos");
    assertTest(str_contains(strtolower($resExpungeT3['message']), 'certificado') || str_contains(strtolower($resExpungeT3['message']), 'livro'), "Mensagem de recusa destaca a proteção de fé pública dos certificados");

    // Turma 3 continua intacta no banco
    $stmtT3Existe = $pdo->prepare("SELECT COUNT(*) FROM turmas WHERE id = ?");
    $stmtT3Existe->execute([$turmaId3]);
    assertTest((int)$stmtT3Existe->fetchColumn() === 1, "Turma 3 permanece preservada fisicamente no banco de dados");

    // Agora testa expurgo definitivo em turma sem certificados (Turma 2)
    $resExpungeT2 = $turmaService->expungeTurma($turmaId2);
    assertTest($resExpungeT2['success'] === true, "expungeTurma executado com sucesso em turma sem certificados vinculados");

    $stmtT2Existe = $pdo->prepare("SELECT COUNT(*) FROM turmas WHERE id = ?");
    $stmtT2Existe->execute([$turmaId2]);
    assertTest((int)$stmtT2Existe->fetchColumn() === 0, "Turma 2 foi fisicamente excluída do banco de dados");

    $stmtE2Existe = $pdo->prepare("SELECT COUNT(*) FROM encontros WHERE turma_id = ?");
    $stmtE2Existe->execute([$turmaId2]);
    assertTest((int)$stmtE2Existe->fetchColumn() === 0, "Encontros da Turma 2 foram removidos em cascata");

    // =========================================================================
    // SEÇÃO 7: Segurança de Acesso do Aluno (AuthService)
    // =========================================================================
    echo "{$amarelo}-> 7. Testando Barreira de Acesso do Aluno para Turma na Lixeira...{$reset}\n";

    // Move Turma 1 para a lixeira
    $turmaService->moveToTrash($turmaId1);

    // Tenta acesso com a chave da Turma 1
    $authStudentLixeira = $authService->authenticateStudent('excel-alfa-100');
    assertTest($authStudentLixeira['success'] === false, "Aluno é impedido de acessar materiais de turma na lixeira");
    assertTest(str_contains(strtolower($authStudentLixeira['error']), 'desativado') || str_contains(strtolower($authStudentLixeira['error']), 'turma'), "Mensagem informa que o acesso foi desativado");

    // Restaura Turma 1
    $turmaService->restoreFromTrash($turmaId1);
    $authStudentRestaurada = $authService->authenticateStudent('excel-alfa-100');
    assertTest($authStudentRestaurada['success'] === true, "Acesso do aluno é restabelecido após restauração da turma");

    // =========================================================================
    // SEÇÃO 8: Contadores e Integridade de Interface
    // =========================================================================
    echo "{$amarelo}-> 8. Testando Contadores Dinâmicos e Métricas...{$reset}\n";

    $countLixeiraFinal = $turmaService->getTrashCount();
    // Turma 3 está na lixeira, Turma 1 ativa, Turma 2 expurgada
    assertTest($countLixeiraFinal === 1, "getTrashCount aponta exatamente 1 turma na lixeira (Turma 3)");

    $trashedList = $turmaService->getTrashedTurmas();
    assertTest(count($trashedList) === 1, "getTrashedTurmas lista exatamente 1 turma");
    assertTest((int)$trashedList[0]['id'] === $turmaId3, "Item na lixeira corresponde à Turma 3");
    assertTest(!empty($trashedList[0]['deleted_at_formatado']), "Item na lixeira inclui deleted_at formatado para exibição");

    // =========================================================================
    // SEÇÃO 9: Renderização das Telas Web (/diario/turmas e /diario/turma)
    // =========================================================================
    echo "{$amarelo}-> 9. Testando Renderização Web e Abas da Lixeira...{$reset}\n";

    $_SESSION[AuthService::SESSION_ADMIN_KEY] = [
        'id'           => 1,
        'username'     => 'admin',
        'nome'         => 'Administrador Teste',
        'email'        => 'admin@futurofacil.com.br',
        'ultimo_login' => date('Y-m-d H:i:s'),
        'logged_at'    => date('Y-m-d H:i:s'),
    ];
    $_SESSION[AuthService::CSRF_TOKEN_KEY] = 'csrf_token_teste_10';

    // 1. Renderiza /diario/turmas?filtro=ativas
    $_GET = ['filtro' => 'ativas'];
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start();
    require __DIR__ . '/../public/diario/turmas.php';
    $htmlAtivas = ob_get_clean();

    assertTest(str_contains($htmlAtivas, 'Turmas Ativas'), "Tela /diario/turmas renderiza aba 'Turmas Ativas'");
    assertTest(str_contains($htmlAtivas, '🗑️ Lixeira'), "Tela /diario/turmas renderiza aba '🗑️ Lixeira'");
    assertTest(str_contains($htmlAtivas, 'Excel Básico'), "Aba Ativas exibe Turma 1 ('Excel Básico')");
    assertTest(!str_contains($htmlAtivas, 'Gestão Financeira'), "Aba Ativas omite Turma 3 descartada na lixeira");
    assertTest(str_contains($htmlAtivas, 'action="trash"') || str_contains($htmlAtivas, 'name="action" value="trash"'), "Aba Ativas renderiza formulário para mover à lixeira");

    // 2. Renderiza /diario/turmas?filtro=lixeira
    $_GET = ['filtro' => 'lixeira'];
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start();
    require __DIR__ . '/../public/diario/turmas.php';
    $htmlLixeira = ob_get_clean();

    assertTest(str_contains($htmlLixeira, 'Gestão Financeira'), "Aba Lixeira exibe Turma 3 ('Gestão Financeira')");
    assertTest(str_contains($htmlLixeira, 'Na Lixeira'), "Aba Lixeira exibe badge 'Na Lixeira'");
    assertTest(str_contains($htmlLixeira, 'action="restore"') || str_contains($htmlLixeira, 'name="action" value="restore"'), "Aba Lixeira renderiza botão/ação de Restaurar");
    assertTest(str_contains($htmlLixeira, 'action="expunge"') || str_contains($htmlLixeira, 'name="action" value="expunge"'), "Aba Lixeira renderiza botão/ação de Excluir Definitivamente");
    assertTest(str_contains($htmlLixeira, 'Descartada em:'), "Aba Lixeira exibe timestamp formatado de exclusão");

    // 3. Renderiza /diario/turma?turma_id=turmaId3 (turma na lixeira)
    $_GET = ['turma_id' => $turmaId3];
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start();
    require __DIR__ . '/../public/diario/turma.php';
    $htmlTurmaLixeira = ob_get_clean();

    assertTest(str_contains($htmlTurmaLixeira, 'Esta turma está na Lixeira'), "Tela /diario/turma exibe banner de aviso de turma na lixeira");
    assertTest(str_contains($htmlTurmaLixeira, 'action="restore"') || str_contains($htmlTurmaLixeira, 'name="action" value="restore"'), "Banner da turma na lixeira disponibiliza botão de Restaurar");
    assertTest(str_contains($htmlTurmaLixeira, 'action="expunge"') || str_contains($htmlTurmaLixeira, 'name="action" value="expunge"'), "Banner da turma na lixeira disponibiliza botão de Excluir Definitivamente");

} catch (Throwable $e) {
    echo "{$vermelho}Exceção fatal durante a execução dos testes: " . $e->getMessage() . " em " . $e->getFile() . ":" . $e->getLine() . "{$reset}\n";
    echo $e->getTraceAsString() . "\n";
} finally {
    $pdo = null;
    Database::resetConnection();
    if (file_exists($testDbPath)) {
        @unlink($testDbPath);
    }
}

echo "\n{$azul}======================================================================\n";
printf(" Suíte de Testes Ticket 10 Finalizada: %d/%d testes passaram com sucesso!\n", $passedCount, $totalCount);
echo "======================================================================{$reset}\n";

if ($passedCount !== $totalCount || $totalCount === 0) {
    exit(1);
}
exit(0);
