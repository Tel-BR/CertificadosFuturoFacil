<?php
/**
 * Suíte de Testes Automatizados — Ticket 03: Calendário e Gestão de Capacidade
 * Costura de Teste 5 (Testing Seam 5): Borda de Conflito de Agenda e Choque de Horários
 * 
 * Valida:
 * 1. Detecção e bloqueio rigoroso de choques de horário (turnos colidentes M, V, N, D).
 * 2. Liberação de turnos quando não há colisão (ex: M e V no mesmo dia).
 * 3. Liberação de horários caso a turma esteja cancelada.
 * 4. Matriz do Calendário Mensal (semanas, preenchimento, dias úteis, hoje e datas passadas).
 * 5. Matriz do Calendário Anual (12 meses integrados).
 * 6. Posições dedicadas e atributos acessíveis dos 4 turnos sem confusão de cores.
 * 7. Estrutura de dados do rollover / popover flutuante.
 * 8. Execução com sucesso do script de carga de homologação test_data_seed.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Config/Database.php';
require_once __DIR__ . '/../src/Services/CalendarService.php';
require_once __DIR__ . '/../src/Services/AuthService.php';

use FuturoFacil\Config\Database;
use FuturoFacil\Services\CalendarService;
use FuturoFacil\Services\AuthService;

$verde = "\033[32m";
$vermelho = "\033[31m";
$amarelo = "\033[33m";
$azul = "\033[36m";
$reset = "\033[0m";

echo "{$azul}======================================================================\n";
echo " Executando Suíte de Testes — Ticket 03: Calendário e Costura de Teste 5\n";
echo "======================================================================{$reset}\n\n";

$testDbPath = __DIR__ . '/test_temp_calendar.db';
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

    $service = new CalendarService($pdo);

    // =========================================================================
    // SEÇÃO 1: Costura de Teste 5 — Bloqueio de Choque de Horários
    // =========================================================================
    echo "{$amarelo}-> 1. Testando Costura de Teste 5 (Bloqueio de Choque de Horários)...{$reset}\n";

    // Criar turma base confirmada
    $stmtTurma = $pdo->prepare("
        INSERT INTO turmas (
            codigo_turma, curso_nome, cliente_nome, carga_horaria, data_inicio, data_conclusao,
            turno_padrao, status, chave_acesso
        ) VALUES (
            'TURMA-TESTE-01', 'Power BI Avançado', 'Sicoob Credisul', 16, '2026-10-10', '2026-10-15',
            'M', 'prevista', 'chave-teste-pbi'
        )
    ");
    $stmtTurma->execute();
    $turmaId1 = (int)$pdo->lastInsertId();

    // Inserir encontro matutino no dia 2026-10-12 (Turno M)
    $stmtEncontro = $pdo->prepare("
        INSERT INTO encontros (turma_id, numero_encontro, data_encontro, turno, horario_inicio, horario_fim, conteudo_previsto)
        VALUES (?, 1, '2026-10-12', 'M', '08:00:00', '12:00:00', 'Módulo 1: Introdução ao DAX')
    ");
    $stmtEncontro->execute([$turmaId1]);
    $encontroId1 = (int)$pdo->lastInsertId();

    // 1.1 Tentativa de choque direto: mesmo dia e mesmo turno (M sobre M)
    $conflito = $service->checkConflict('2026-10-12', 'M');
    assertTest($conflito !== null, 'Choque direto detectado para o mesmo turno (M sobre M)');
    assertTest(isset($conflito['tipo']) && $conflito['tipo'] === 'choque_turno', 'Identificador de tipo de choque presente');
    assertTest(str_contains($conflito['mensagem'], 'Power BI Avançado'), 'Mensagem explicativa detalha o curso colidente');
    assertTest(str_contains($conflito['mensagem'], 'Sicoob Credisul'), 'Mensagem detalha o cliente colidente');

    // 1.2 Tentativa de agendar Dia Todo (D) quando a manhã (M) já está ocupada
    $conflitoD = $service->checkConflict('2026-10-12', 'D');
    assertTest($conflitoD !== null, 'Bloqueio de agendamento de Dia Todo (D) quando a manhã (M) está ocupada');
    assertTest(str_contains($conflitoD['mensagem'], 'Dia Todo'), 'Alerta explicita incompatibilidade com turno Dia Todo');

    // 1.3 Turnos livres no mesmo dia (V e N livres no dia com M)
    $conflitoV = $service->checkConflict('2026-10-12', 'V');
    assertTest($conflitoV === null, 'Turno Vespertino (V) liberado com sucesso no mesmo dia de aula Matutina');

    $conflitoN = $service->checkConflict('2026-10-12', 'N');
    assertTest($conflitoN === null, 'Turno Noturno (N) liberado com sucesso no mesmo dia de aula Matutina');

    // 1.4 Inserir encontro de Dia Todo (D) em outra data: 2026-10-20
    $stmtTurmaD = $pdo->prepare("
        INSERT INTO turmas (
            codigo_turma, curso_nome, cliente_nome, carga_horaria, data_inicio, data_conclusao,
            turno_padrao, status, chave_acesso
        ) VALUES (
            'TURMA-TESTE-D', 'Imersão em Dados de Saúde', 'Unimed Ji-Paraná', 8, '2026-10-20', '2026-10-20',
            'D', 'prevista', 'chave-teste-unimed'
        )
    ");
    $stmtTurmaD->execute();
    $turmaIdD = (int)$pdo->lastInsertId();

    $stmtEncontroD = $pdo->prepare("
        INSERT INTO encontros (turma_id, numero_encontro, data_encontro, turno, horario_inicio, horario_fim)
        VALUES (?, 1, '2026-10-20', 'D', '08:00:00', '17:00:00')
    ");
    $stmtEncontroD->execute([$turmaIdD]);

    // Tentativa de agendar M, V, N ou D no dia ocupado por D
    assertTest($service->checkConflict('2026-10-20', 'M') !== null, 'Bloqueio de Matutino (M) em dia com turma Dia Todo (D)');
    assertTest($service->checkConflict('2026-10-20', 'V') !== null, 'Bloqueio de Vespertino (V) em dia com turma Dia Todo (D)');
    assertTest($service->checkConflict('2026-10-20', 'N') !== null, 'Bloqueio de Noturno (N) em dia com turma Dia Todo (D)');
    assertTest($service->checkConflict('2026-10-20', 'D') !== null, 'Bloqueio de segundo Dia Todo (D) na mesma data');

    // 1.5 Turmas canceladas não bloqueiam agendamento
    $stmtTurmaCancel = $pdo->prepare("
        INSERT INTO turmas (
            codigo_turma, curso_nome, cliente_nome, carga_horaria, data_inicio, data_conclusao,
            turno_padrao, status, chave_acesso
        ) VALUES (
            'TURMA-CANCELADA', 'Curso Cancelado', 'Cliente Cancelado', 8, '2026-10-25', '2026-10-25',
            'V', 'cancelada', 'chave-cancelada'
        )
    ");
    $stmtTurmaCancel->execute();
    $turmaIdCancel = (int)$pdo->lastInsertId();

    $stmtEncontroCancel = $pdo->prepare("
        INSERT INTO encontros (turma_id, numero_encontro, data_encontro, turno, horario_inicio, horario_fim)
        VALUES (?, 1, '2026-10-25', 'V', '14:00:00', '18:00:00')
    ");
    $stmtEncontroCancel->execute([$turmaIdCancel]);

    assertTest($service->checkConflict('2026-10-25', 'V') === null, 'Encontro de turma com status cancelada não gera bloqueio de horário');

    // 1.6 Edição do próprio encontro ignora a si mesmo
    assertTest($service->checkConflict('2026-10-12', 'M', $encontroId1) === null, 'Atualização do próprio encontro ignora falso choque com ele mesmo');

    // 1.7 Tentativa de agendamento transacional com método scheduleEncontro
    try {
        $service->scheduleEncontro([
            'turma_id' => $turmaId1,
            'numero_encontro' => 2,
            'data_encontro' => '2026-10-12',
            'turno' => 'M',
        ]);
        assertTest(false, 'scheduleEncontro deveria lançar exceção de choque de horário');
    } catch (\InvalidArgumentException $e) {
        assertTest(str_contains($e->getMessage(), 'Choque de horário'), 'scheduleEncontro rejeita gravação e lança exceção explicativa');
    }

    // =========================================================================
    // SEÇÃO 2: Matriz do Calendário Mensal e Datas Desbotadas (< hoje)
    // =========================================================================
    echo "\n{$amarelo}-> 2. Testando Geração e Atributos da Visão Mensal...{$reset}\n";

    // Fixar "hoje" como 2026-10-15 para testes determinísticos
    $mockToday = '2026-10-15';
    $mesData = $service->getMonthCalendarData(2026, 10, $mockToday);

    assertTest($mesData['ano'] === 2026 && $mesData['mes'] === 10, 'Ano e mês corretos nos metadados da visão mensal');
    assertTest($mesData['nome_mes'] === 'Outubro', 'Nome do mês formatado em português');
    assertTest(count($mesData['semanas']) >= 4 && count($mesData['semanas']) <= 6, 'Grade do mês estruturada em semanas');

    // Verificar se cada semana possui 7 dias
    $semanasValidas = true;
    foreach ($mesData['semanas'] as $sem) {
        if (count($sem) !== 7) {
            $semanasValidas = false;
            break;
        }
    }
    assertTest($semanasValidas, 'Todas as semanas possuem exatamente 7 dias (Dom a Sáb)');

    // Localizar dias de teste na matriz
    $diaPassado = null;
    $diaHoje = null;
    $diaFuturo = null;
    $diaComAula = null;

    foreach ($mesData['semanas'] as $sem) {
        foreach ($sem as $dia) {
            if ($dia['data'] === '2026-10-10') {
                $diaPassado = $dia;
            }
            if ($dia['data'] === '2026-10-15') {
                $diaHoje = $dia;
            }
            if ($dia['data'] === '2026-10-20') {
                $diaFuturo = $dia;
            }
            if ($dia['data'] === '2026-10-12') {
                $diaComAula = $dia;
            }
        }
    }

    assertTest($diaPassado !== null && $diaPassado['is_past'] === true, 'Data anterior a hoje (< 2026-10-15) marcada com is_past = true');
    assertTest($diaHoje !== null && $diaHoje['is_today'] === true, 'Data de hoje (= 2026-10-15) marcada com is_today = true');
    assertTest($diaHoje['is_past'] === false, 'Data de hoje não é marcada como passada');
    assertTest($diaFuturo !== null && $diaFuturo['is_past'] === false && $diaFuturo['is_today'] === false, 'Data futura tem is_past = false e is_today = false');

    // Verificar ocupação de turnos no dia com aula
    assertTest($diaComAula !== null, 'Dia com aula (2026-10-12) localizado no calendário');
    assertTest(in_array('M', $diaComAula['turnos_ocupados'], true), 'Turno Matutino [M] registrado como ocupado no dia 2026-10-12');
    assertTest(in_array('V', $diaComAula['turnos_disponiveis'], true), 'Turno Vespertino [V] registrado como disponível');
    assertTest(in_array('N', $diaComAula['turnos_disponiveis'], true), 'Turno Noturno [N] registrado como disponível');

    // =========================================================================
    // SEÇÃO 3: Popover Interativo e Posições Dedicadas Acessíveis
    // =========================================================================
    echo "\n{$amarelo}-> 3. Testando Popover Interativo e Acessibilidade Cromática...{$reset}\n";

    assertTest(!empty($diaComAula['popover']), 'Dados do popover preenchidos para dia com aula');
    assertTest(count($diaComAula['popover']['encontros']) === 1, 'Popover lista 1 encontro agendado');
    $popoverEnc = $diaComAula['popover']['encontros'][0];
    assertTest($popoverEnc['curso'] === 'Power BI Avançado', 'Popover exibe o nome do curso');
    assertTest($popoverEnc['cliente'] === 'Sicoob Credisul', 'Popover exibe o cliente');
    assertTest($popoverEnc['horario'] === '08:00 - 12:00', 'Popover exibe a faixa de horário formatada');
    assertTest($popoverEnc['turno_sigla'] === 'M' && $popoverEnc['turno_nome'] === 'Matutino', 'Popover especifica turno com sigla e nome');

    // Verificar especificações acessíveis de cores e slots fixos
    $turnosConfig = CalendarService::getTurnosConfig();
    assertTest(isset($turnosConfig['M'], $turnosConfig['V'], $turnosConfig['N'], $turnosConfig['D']), 'Todos os 4 turnos definidos na configuração');
    assertTest($turnosConfig['M']['letra'] === 'M', 'Turno Matutino possui letra M visível');
    assertTest($turnosConfig['V']['letra'] === 'V', 'Turno Vespertino possui letra V visível');
    assertTest($turnosConfig['N']['letra'] === 'N', 'Turno Noturno possui letra N visível');
    assertTest($turnosConfig['D']['letra'] === 'D', 'Turno Dia Todo possui letra D visível');

    // Garantir que não há dependência de par conflitante azul vs roxo
    $corV = strtolower($turnosConfig['V']['cor_texto']);
    $corN = strtolower($turnosConfig['N']['cor_texto']);
    assertTest($corV !== $corN, 'Turnos V e N possuem cores de texto distintas e de alto contraste');

    // =========================================================================
    // SEÇÃO 4: Matriz do Calendário Anual (12 Meses)
    // =========================================================================
    echo "\n{$amarelo}-> 4. Testando Geração da Visão Anual (12 Meses)...{$reset}\n";

    $anoData = $service->getYearCalendarData(2026, $mockToday);
    assertTest($anoData['ano'] === 2026, 'Metadado de ano correto na visão anual');
    assertTest(count($anoData['meses']) === 12, 'Visão anual contém exatamente 12 meses');
    assertTest($anoData['meses'][1]['nome'] === 'Janeiro', 'Mês 1 é Janeiro');
    assertTest($anoData['meses'][10]['nome'] === 'Outubro', 'Mês 10 é Outubro');
    assertTest($anoData['meses'][12]['nome'] === 'Dezembro', 'Mês 12 é Dezembro');

    // Verificar se o mês de Outubro na visão anual reflete as aulas agendadas
    $outubroAnual = $anoData['meses'][10];
    assertTest($outubroAnual['total_aulas'] >= 2, 'Mês de Outubro contabiliza as aulas agendadas');

    // =========================================================================
    // SEÇÃO 5: Execução do Script de Carga de Homologação (test_data_seed.php)
    // =========================================================================
    echo "\n{$amarelo}-> 5. Testando Script de Carga de Homologação (test_data_seed.php)...{$reset}\n";

    $seedScript = __DIR__ . '/../database/seeds/test_data_seed.php';
    assertTest(file_exists($seedScript), 'Arquivo database/seeds/test_data_seed.php existe');

    // Executar o seed contra o banco de testes
    putenv("DB_DRIVER=sqlite");
    putenv("DB_DATABASE={$testDbPath}");

    $cmd = sprintf(
        '"%s" "%s" --force',
        'C:\Users\Admin\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe',
        $seedScript
    );
    $seedOutput = shell_exec($cmd . ' 2>&1');

    assertTest($seedOutput !== null && !str_contains($seedOutput, 'FALHA'), 'Execução do seed concluída sem erros fatais');
    assertTest(str_contains((string)$seedOutput, 'Seed de homologação concluído com sucesso'), 'Mensagem de sucesso do seed confirmada');

    // Validar turmas inseridas no banco pelo seed
    $stmtCount = $pdo->query("SELECT COUNT(*) FROM turmas");
    $totalTurmasSeed = (int)$stmtCount->fetchColumn();
    assertTest($totalTurmasSeed >= 5, 'Seed inseriu ao menos 5 turmas de homologação');

    // Validar presença de turmas nos 3 tempos: concluída (passado), em_andamento (hoje), prevista (futuro)
    $stmtStatus = $pdo->query("SELECT DISTINCT status FROM turmas");
    $statuses = $stmtStatus->fetchAll(PDO::FETCH_COLUMN);
    assertTest(in_array('concluida', $statuses, true), 'Seed inclui turma concluída (passado)');
    assertTest(in_array('em_andamento', $statuses, true), 'Seed inclui turma em andamento (hoje)');
    assertTest(in_array('prevista', $statuses, true), 'Seed inclui turma prevista (futuro)');

    // Validar presença de encontros em todos os 4 turnos (M, V, N, D)
    $stmtTurnos = $pdo->query("SELECT DISTINCT turno FROM encontros");
    $turnosSeed = $stmtTurnos->fetchAll(PDO::FETCH_COLUMN);
    assertTest(in_array('M', $turnosSeed, true), 'Seed inclui encontro no turno Matutino (M)');
    assertTest(in_array('V', $turnosSeed, true), 'Seed inclui encontro no turno Vespertino (V)');
    assertTest(in_array('N', $turnosSeed, true), 'Seed inclui encontro no turno Noturno (N)');
    assertTest(in_array('D', $turnosSeed, true), 'Seed inclui encontro no turno Dia Todo (D)');

    // Validar inserção de alunos fictícios
    $stmtAlunos = $pdo->query("SELECT COUNT(*) FROM alunos");
    $totalAlunosSeed = (int)$stmtAlunos->fetchColumn();
    assertTest($totalAlunosSeed > 0, 'Seed inseriu alunos fictícios nas turmas');

    // =========================================================================
    // SEÇÃO 6: Testando Renderização HTML da Interface do Calendário
    // =========================================================================
    echo "\n{$amarelo}-> 6. Testando Renderização HTML da Interface Web (/diario/calendario)...{$reset}\n";

    // Simular autenticação administrativa para renderizar a página
    $_SESSION['usuario_admin'] = [
        'id'       => 1,
        'username' => 'admin',
        'nome'     => 'Instrutor Futuro Fácil',
        'email'    => 'contato@futurofacil.com.br',
    ];
    $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));

    // 6.1 Testar Visão Mensal
    $_GET['view'] = 'mensal';
    $_GET['ano'] = '2026';
    $_GET['mes'] = '10';

    ob_start();
    require __DIR__ . '/../public/diario/calendario.php';
    $htmlMensal = ob_get_clean();

    assertTest(str_contains($htmlMensal, 'Calendário de Capacidade'), 'Página mensal contém o título Calendário de Capacidade');
    assertTest(str_contains($htmlMensal, 'Visão Mensal Detalhada'), 'Página mensal contém indicação da visão mensal');
    assertTest(str_contains($htmlMensal, 'Outubro de 2026'), 'Página mensal exibe o mês e ano corretos');
    assertTest(str_contains($htmlMensal, 'dedicated-turn-slots'), 'Página mensal contém slots fixos dedicados para turnos');
    assertTest(str_contains($htmlMensal, 'day-past'), 'Página mensal contém estilização de datas passadas (day-past)');
    assertTest(str_contains($htmlMensal, 'calendarPopover'), 'Página contém contêiner do popover flutuante');
    assertTest(str_contains($htmlMensal, 'dayDetailsModal'), 'Página contém modal de detalhes do dia e chamada');
    assertTest(str_contains($htmlMensal, 'fastScheduleModal'), 'Página contém modal de agendamento rápido com prevenção de choque');
    assertTest(str_contains($htmlMensal, $_SESSION['admin_csrf_token']), 'Formulários do calendário contêm token CSRF ativo');

    // 6.2 Testar Visão Anual
    $_GET['view'] = 'anual';
    $_GET['ano'] = '2026';

    ob_start();
    require __DIR__ . '/../public/diario/calendario.php';
    $htmlAnual = ob_get_clean();

    assertTest(str_contains($htmlAnual, 'Visão Anual Estratégica'), 'Página anual contém indicação da visão anual');
    assertTest(str_contains($htmlAnual, 'mini-month-card'), 'Página anual contém grade de mini-meses');
    assertTest(substr_count($htmlAnual, 'class="mini-month-card"') === 12, 'Página anual renderiza exatamente 12 cartões de meses');

} catch (Throwable $e) {
    echo "{$vermelho}[ERRO INESPERADO]{$reset} " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
} finally {
    if (file_exists($testDbPath)) {
        @unlink($testDbPath);
    }
}

echo "\n{$azul}----------------------------------------------------------------------{$reset}\n";
if ($passedCount === $totalCount) {
    echo "{$verde}RESULTADO: 100% DE SUCESSO! ({$passedCount}/{$totalCount} verificações executadas sem falhas).{$reset}\n";
    echo "{$verde}Ticket 03 (Calendário, Costura 5 e Carga de Homologação) APROVADO!{$reset}\n";
    echo "{$azul}----------------------------------------------------------------------{$reset}\n";
    exit(0);
} else {
    echo "{$vermelho}RESULTADO: {$passedCount} aprovados de {$totalCount} testes. Verifique as falhas acima.{$reset}\n";
    echo "{$azul}----------------------------------------------------------------------{$reset}\n";
    exit(1);
}
