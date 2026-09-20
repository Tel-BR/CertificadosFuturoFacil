<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Config/Database.php';
require_once __DIR__ . '/../src/Services/CalendarService.php';
require_once __DIR__ . '/../src/Services/TurmaService.php';
require_once __DIR__ . '/../src/Services/BillingService.php';
require_once __DIR__ . '/../src/Services/ExcelSyncService.php';

use FuturoFacil\Config\Database;
use FuturoFacil\Services\BillingService;
use FuturoFacil\Services\CalendarService;
use FuturoFacil\Services\ExcelSyncService;
use FuturoFacil\Services\TurmaService;

$dbPath = __DIR__ . '/test_temp_ticket_14.db';
@unlink($dbPath);
$failures = [];

function check14(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
        echo "FALHA: {$message}\n";
        return;
    }
    echo "OK: {$message}\n";
}

try {
    Database::setConfig(['driver' => 'sqlite', 'database' => $dbPath]);
    $pdo = Database::getConnection();
    $pdo->exec((string)file_get_contents(__DIR__ . '/../database/schema_sqlite.sql'));

    $calendar = new CalendarService($pdo);
    $turmas = new TurmaService($pdo, $calendar);
    $turmas->ensureSchema();

    $columnNames = array_column($pdo->query('PRAGMA table_info(encontros)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    check14(in_array('intervalo_minutos', $columnNames, true), 'encontros persiste intervalo_minutos');

    $turmaId = $turmas->createTurma([
        'codigo_turma' => 'TESTE-INTERVALO-14',
        'chave_acesso' => 'teste-intervalo-14',
        'curso_nome' => 'Carga Horária Real',
        'data_inicio' => '2026-08-10',
        'data_conclusao' => '2026-08-10',
        'carga_horaria' => 8,
        'turno_padrao' => 'D',
    ], [[
        'data_encontro' => '2026-08-10',
        'horario_inicio' => '08:00',
        'horario_fim' => '17:00',
        'intervalo_minutos' => 60,
        'tipo' => 'aula',
    ]]);

    $encontro = $pdo->query("SELECT horario_inicio, horario_fim, intervalo_minutos, turno FROM encontros WHERE turma_id = {$turmaId}")->fetch(PDO::FETCH_ASSOC);
    check14((int)$encontro['intervalo_minutos'] === 60, 'criação preserva o intervalo informado');
    check14($encontro['turno'] === 'D', 'horários definem o turno integral');

    $billing = new BillingService($pdo);
    $dadosFaturamento = $billing->getTurmaBillingData($turmaId);
    check14((float)$dadosFaturamento['total_horas'] === 8.0, 'faturamento desconta 60 minutos de intervalo');

    $cargaMensal = $calendar->calculateMonthlyWorkload(2026, 8);
    check14((int)$cargaMensal['total_horas'] === 8, 'teto mensal usa a carga líquida do encontro');

    $sync = new ExcelSyncService($pdo);
    $xlsx = $sync->exportTurmaSpreadsheet($turmaId);
    $parser = new ReflectionMethod(ExcelSyncService::class, 'parseXlsx');
    $parser->setAccessible(true);
    $planilhaExportada = $parser->invoke($sync, $xlsx);
    check14(in_array('Intervalo (min)', $planilhaExportada['Diário e Planos'][0], true), 'exportação Excel inclui a coluna de intervalo');

    $encId = (int)$pdo->query("SELECT id FROM encontros WHERE turma_id = {$turmaId}")->fetchColumn();
    $turmas->updateEncontro($encId, ['intervalo_minutos' => 30]);
    $atualizado = (int)$pdo->query("SELECT intervalo_minutos FROM encontros WHERE id = {$encId}")->fetchColumn();
    check14($atualizado === 30, 'edição individual atualiza o intervalo');

    check14(str_contains((string)file_get_contents(__DIR__ . '/../public/diario/turma_form.php'), 'intervalo_minutos'), 'formulário expõe intervalo por encontro');
} finally {
    Database::resetConnection();
    @unlink($dbPath);
}

if ($failures !== []) {
    exit(1);
}

echo "Ticket 14 aprovado.\n";
