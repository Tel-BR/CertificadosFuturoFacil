<?php
/**
 * Calendário de Capacidade e Agendamento Pedagógico (/diario/calendario)
 * Plataforma Integrada de Gestão Pedagógica, Diário e Certificados Futuro Fácil
 * 
 * Funcionalidades:
 * - Alternância fluida entre Visão Mensal e Visão Anual (12 meses).
 * - Indicadores visuais para os 4 turnos ([M], [V], [N], [D]) em posições dedicadas e paleta acessível.
 * - Estilização automática de datas passadas (< hoje) em tons neutros desbotados.
 * - Rollover interativo (popover) com detalhes das turmas, clientes e horários ocupados.
 * - Clique no dia: atalhos diretos para chamada e formulário rápido para agendar turma/reposição.
 * - Bloqueio rigoroso de choque de horário (Costura de Teste 5).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/Database.php';
require_once __DIR__ . '/../../src/Services/AuthService.php';
require_once __DIR__ . '/../../src/Services/CalendarService.php';
require_once __DIR__ . '/../../src/Views/layout_admin.php';

use FuturoFacil\Config\Database;
use FuturoFacil\Services\AuthService;
use FuturoFacil\Services\CalendarService;
use function FuturoFacil\Views\renderAdminLayout;

if (!headers_sent()) {
    header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
    header('Content-Type: text/html; charset=UTF-8');
}

// 1. Exige autenticação estrita
$user = AuthService::requireAuth();
$csrfToken = AuthService::getCsrfToken();
$pdo = Database::getConnection();
$calendarService = new CalendarService($pdo);

$feedbackSuccess = null;
$feedbackError = null;

// 2. Processamento de Ações POST (Agendamento rápido com bloqueio de choque)
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($requestMethod === 'POST') {
    $submittedToken = (string)($_POST['csrf_token'] ?? '');
    if (!AuthService::validateCsrfToken($submittedToken)) {
        $feedbackError = "Sessão expirada ou token de segurança inválido. Atualize a página e tente novamente.";
    } else {
        $acao = $_POST['acao'] ?? '';

        if ($acao === 'agendar_encontro') {
            $turmaId = (int)($_POST['turma_id'] ?? 0);
            $dataEncontro = trim((string)($_POST['data_encontro'] ?? ''));
            $turno = strtoupper(trim((string)($_POST['turno'] ?? 'V')));
            $hIni = trim((string)($_POST['horario_inicio'] ?? ''));
            $hFim = trim((string)($_POST['horario_fim'] ?? ''));
            $conteudo = trim((string)($_POST['conteudo_previsto'] ?? ''));

            try {
                // Descobrir o próximo número do encontro para a turma
                $stmtNum = $pdo->prepare("SELECT COALESCE(MAX(numero_encontro), 0) + 1 FROM encontros WHERE turma_id = ?");
                $stmtNum->execute([$turmaId]);
                $proxNumero = (int)$stmtNum->fetchColumn();

                $calendarService->scheduleEncontro([
                    'turma_id'          => $turmaId,
                    'numero_encontro'   => $proxNumero,
                    'data_encontro'     => $dataEncontro,
                    'turno'             => $turno,
                    'horario_inicio'    => $hIni ?: null,
                    'horario_fim'       => $hFim ?: null,
                    'conteudo_previsto' => $conteudo ?: null,
                ]);

                $feedbackSuccess = "Aula/reposição agendada com sucesso para o dia " . date('d/m/Y', strtotime($dataEncontro)) . " no turno [{$turno}].";
            } catch (InvalidArgumentException $e) {
                $feedbackError = $e->getMessage();
            } catch (Throwable $e) {
                $feedbackError = "Erro inesperado ao registrar agendamento: " . $e->getMessage();
            }
        } elseif ($acao === 'agendar_nova_turma') {
            $cursoNome = trim((string)($_POST['curso_nome'] ?? ''));
            $clienteNome = trim((string)($_POST['cliente_nome'] ?? ''));
            $dataEncontro = trim((string)($_POST['data_encontro'] ?? ''));
            $turno = strtoupper(trim((string)($_POST['turno'] ?? 'V')));
            $cargaHoraria = (int)($_POST['carga_horaria'] ?? 8);
            $modalidade = trim((string)($_POST['modalidade'] ?? 'Presencial'));
            $hIni = trim((string)($_POST['horario_inicio'] ?? ''));
            $hFim = trim((string)($_POST['horario_fim'] ?? ''));
            $conteudo = trim((string)($_POST['conteudo_previsto'] ?? ''));

            if (empty($cursoNome) || empty($dataEncontro)) {
                $feedbackError = "O nome do curso e a data são campos obrigatórios.";
            } else {
                try {
                    // Validação prévia de choque de horário (Costura de Teste 5)
                    $conflito = $calendarService->checkConflict($dataEncontro, $turno);
                    if ($conflito !== null) {
                        throw new InvalidArgumentException($conflito['mensagem']);
                    }

                    $pdo->beginTransaction();

                    $codigoTurma = 'TURMA-' . date('Ymd') . '-' . substr(bin2hex(random_bytes(3)), 0, 5);
                    $chaveAcesso = strtolower(preg_replace('/[^a-z0-9]/', '', $cursoNome) ?? 'turma') . '-' . rand(100, 999);

                    $stmtNovaTurma = $pdo->prepare("
                        INSERT INTO turmas (
                            codigo_turma, curso_nome, cliente_nome, carga_horaria,
                            data_inicio, data_conclusao, turno_padrao, status, chave_acesso, modalidade
                        ) VALUES (
                            :codigo, :curso, :cliente, :carga,
                            :data_inicio, :data_conclusao, :turno, 'prevista', :chave, :modalidade
                        )
                    ");

                    $stmtNovaTurma->execute([
                        ':codigo'         => $codigoTurma,
                        ':curso'          => $cursoNome,
                        ':cliente'        => $clienteNome ?: null,
                        ':carga'          => $cargaHoraria,
                        ':data_inicio'    => $dataEncontro,
                        ':data_conclusao' => $dataEncontro,
                        ':turno'          => $turno,
                        ':chave'          => $chaveAcesso,
                        ':modalidade'     => $modalidade,
                    ]);

                    $turmaId = (int)$pdo->lastInsertId();

                    $calendarService->scheduleEncontro([
                        'turma_id'          => $turmaId,
                        'numero_encontro'   => 1,
                        'data_encontro'     => $dataEncontro,
                        'turno'             => $turno,
                        'horario_inicio'    => $hIni ?: null,
                        'horario_fim'       => $hFim ?: null,
                        'conteudo_previsto' => $conteudo ?: null,
                    ]);

                    $pdo->commit();
                    $feedbackSuccess = "Nova turma '{$cursoNome}' cadastrada e agendada para " . date('d/m/Y', strtotime($dataEncontro)) . " no turno [{$turno}].";
                } catch (InvalidArgumentException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $feedbackError = $e->getMessage();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $feedbackError = "Falha ao cadastrar turma: " . $e->getMessage();
                }
            }
        }
    }
}

// 3. Parâmetros de Navegação e Visualização
$view = ($_GET['view'] ?? 'mensal') === 'anual' ? 'anual' : 'mensal';
$hoje = date('Y-m-d');
$ano = isset($_GET['ano']) ? (int)$_GET['ano'] : (int)date('Y');
$mes = isset($_GET['mes']) ? (int)$_GET['mes'] : (int)date('m');

if ($mes < 1) {
    $mes = 1;
}
if ($mes > 12) {
    $mes = 12;
}
if ($ano < 2020 || $ano > 2035) {
    $ano = (int)date('Y');
}

$turnosConfig = CalendarService::getTurnosConfig();

// Carregar lista de turmas para o formulário rápido de agendamento
$stmtTurmasList = $pdo->query("
    SELECT id, codigo_turma, curso_nome, cliente_nome, turno_padrao, status 
    FROM turmas 
    WHERE status != 'cancelada' 
    ORDER BY status DESC, curso_nome ASC
");
$turmasDisponiveis = $stmtTurmasList->fetchAll(PDO::FETCH_ASSOC);

// Carregar dados de acordo com a visão selecionada
if ($view === 'anual') {
    $calendarData = $calendarService->getYearCalendarData($ano, $hoje);
} else {
    $calendarData = $calendarService->getMonthCalendarData($ano, $mes, $hoje);
}

ob_start();
?>

<!-- Estilos Específicos do Calendário de Capacidade -->
<style>
    /* Variáveis e Paleta de Alto Contraste (Acessível a Daltônicos) */
    .cal-container {
        display: flex;
        flex-direction: column;
        gap: 1.5rem;
    }

    .cal-header-bar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 1rem;
        background: var(--surface);
        padding: 1.25rem 1.5rem;
        border-radius: var(--radius-lg);
        border: 1px solid var(--border);
        box-shadow: var(--shadow-sm);
    }

    .cal-title-area h1 {
        font-size: 1.625rem;
        font-weight: 700;
        color: var(--dark);
        letter-spacing: -0.5px;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .cal-title-area p {
        color: var(--text-muted);
        font-size: 0.875rem;
        margin-top: 0.2rem;
    }

    .cal-nav-controls {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        flex-wrap: wrap;
    }

    .view-switcher {
        display: inline-flex;
        background: var(--bg);
        padding: 4px;
        border-radius: 8px;
        border: 1px solid var(--border);
    }

    .view-btn {
        padding: 0.45rem 0.9rem;
        font-size: 0.8125rem;
        font-weight: 600;
        color: var(--text-muted);
        text-decoration: none;
        border-radius: 6px;
        transition: all 0.15s ease;
    }

    .view-btn.active {
        background: var(--surface);
        color: var(--primary);
        box-shadow: var(--shadow-sm);
    }

    .nav-arrow-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 36px;
        height: 36px;
        border-radius: 8px;
        border: 1px solid var(--border);
        background: var(--surface);
        color: var(--text);
        text-decoration: none;
        font-weight: 700;
        transition: all 0.15s ease;
    }

    .nav-arrow-btn:hover {
        background: var(--surface-hover);
        color: var(--primary);
        border-color: var(--primary);
    }

    .btn-today {
        padding: 0.45rem 0.85rem;
        font-size: 0.8125rem;
        font-weight: 600;
        border-radius: 8px;
        border: 1px solid var(--border);
        background: var(--surface);
        color: var(--text);
        text-decoration: none;
        transition: all 0.15s ease;
    }

    .btn-today:hover {
        border-color: var(--primary);
        color: var(--primary);
    }

    /* Legenda Acessível dos Turnos */
    .turnos-legend {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.875rem;
        background: var(--surface);
        padding: 0.875rem 1.25rem;
        border-radius: var(--radius-md);
        border: 1px solid var(--border);
        font-size: 0.8125rem;
    }

    .legend-title {
        font-weight: 700;
        color: var(--dark);
        margin-right: 0.25rem;
        display: flex;
        align-items: center;
        gap: 0.35rem;
    }

    .shift-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 22px;
        height: 22px;
        border-radius: 5px;
        font-weight: 700;
        font-size: 0.75rem;
        font-family: var(--font-mono);
        border-width: 1px;
        border-style: solid;
        flex-shrink: 0;
    }

    /* Grade Mensal Detalhada */
    .cal-month-grid {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        overflow: hidden;
        box-shadow: var(--shadow-sm);
    }

    .cal-weekdays-header {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        background: var(--bg);
        border-bottom: 1px solid var(--border);
        text-align: center;
        font-weight: 700;
        font-size: 0.8125rem;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .cal-weekday-cell {
        padding: 0.75rem 0.5rem;
    }

    .cal-days-body {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
    }

    .cal-day-cell {
        min-height: 115px;
        border-right: 1px solid var(--border);
        border-bottom: 1px solid var(--border);
        padding: 0.5rem;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        position: relative;
        cursor: pointer;
        transition: background-color 0.15s ease, box-shadow 0.15s ease;
    }

    .cal-day-cell:nth-child(7n) {
        border-right: none;
    }

    .cal-day-cell:hover {
        background-color: #F8FAFC;
    }

    /* Datas anteriores a hoje (< hoje): desbotadas em tons neutros */
    .cal-day-cell.day-past {
        background-color: #FAFAFA;
        opacity: 0.58;
        filter: grayscale(85%);
    }

    .cal-day-cell.day-past:hover {
        opacity: 0.95;
        filter: grayscale(0%);
        background-color: #F1F5F9;
    }

    /* Fora do mês atual */
    .cal-day-cell.other-month {
        background-color: #F9FAFB;
        opacity: 0.35;
    }

    /* Dia de Hoje */
    .cal-day-cell.day-today {
        background-color: #F0FDF4;
        border: 2px solid var(--primary);
        z-index: 2;
    }

    .day-top-bar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 0.35rem;
    }

    .day-number {
        font-weight: 700;
        font-size: 0.875rem;
        color: var(--dark);
        width: 26px;
        height: 26px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .day-today .day-number {
        background: var(--primary);
        color: #FFFFFF;
    }

    .today-tag {
        font-size: 0.65rem;
        font-weight: 700;
        background: #DCFCE7;
        color: #166534;
        padding: 1px 5px;
        border-radius: 4px;
        text-transform: uppercase;
    }

    /* Slots fixos dedicados para os 4 turnos */
    .dedicated-turn-slots {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 3px;
        margin-top: auto;
        padding-top: 4px;
        border-top: 1px dashed #E2E8F0;
    }

    .turn-slot {
        height: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 4px;
        font-size: 0.6875rem;
        font-weight: 700;
        font-family: var(--font-mono);
    }

    .turn-slot.empty-slot {
        background: transparent;
        color: #CBD5E1;
        border: 1px dashed #E2E8F0;
    }

    /* Mini chips de compromissos no dia */
    .day-classes-list {
        display: flex;
        flex-direction: column;
        gap: 3px;
        margin-bottom: 0.35rem;
    }

    .class-chip {
        font-size: 0.7125rem;
        padding: 2px 6px;
        border-radius: 4px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 4px;
        line-height: 1.2;
    }

    /* Popover Flutuante Interativo (Rollover) */
    .cal-popover {
        position: absolute;
        z-index: 1000;
        width: 290px;
        background: #FFFFFF;
        border: 1px solid var(--border);
        border-radius: var(--radius-md);
        box-shadow: var(--shadow-md);
        padding: 1rem;
        pointer-events: none;
        opacity: 0;
        transition: opacity 0.15s ease, transform 0.15s ease;
        transform: translateY(6px);
    }

    .cal-popover.visible {
        opacity: 1;
        pointer-events: auto;
        transform: translateY(0);
    }

    .popover-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 0.65rem;
        padding-bottom: 0.5rem;
        border-bottom: 1px solid var(--border);
    }

    .popover-date {
        font-weight: 700;
        color: var(--dark);
        font-size: 0.875rem;
    }

    .popover-item {
        background: var(--bg);
        border: 1px solid var(--border);
        border-radius: 6px;
        padding: 0.6rem;
        margin-bottom: 0.5rem;
    }

    .popover-item:last-child {
        margin-bottom: 0;
    }

    .popover-item-title {
        font-weight: 700;
        font-size: 0.8125rem;
        color: var(--dark);
        margin-bottom: 0.2rem;
    }

    .popover-item-client {
        font-size: 0.75rem;
        color: var(--text-muted);
        margin-bottom: 0.35rem;
    }

    .popover-item-footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        font-size: 0.7125rem;
    }

    /* Visão Anual (12 Meses) */
    .year-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(290px, 1fr));
        gap: 1.25rem;
    }

    .mini-month-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-md);
        padding: 1rem;
        box-shadow: var(--shadow-sm);
    }

    .mini-month-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 0.75rem;
    }

    .mini-month-title {
        font-weight: 700;
        font-size: 0.9375rem;
        color: var(--dark);
        text-decoration: none;
    }

    .mini-month-title:hover {
        color: var(--primary);
    }

    .mini-month-badge {
        font-size: 0.6875rem;
        background: #ECFEFF;
        color: var(--primary);
        font-weight: 700;
        padding: 2px 6px;
        border-radius: 4px;
    }

    .mini-weekdays {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        text-align: center;
        font-size: 0.6875rem;
        font-weight: 700;
        color: var(--text-muted);
        margin-bottom: 0.35rem;
    }

    .mini-days-grid {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 2px;
    }

    .mini-day-cell {
        aspect-ratio: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        border-radius: 4px;
        font-size: 0.7125rem;
        font-weight: 600;
        position: relative;
        cursor: pointer;
        transition: background-color 0.1s ease;
    }

    .mini-day-cell.day-past {
        opacity: 0.45;
        filter: grayscale(80%);
    }

    .mini-day-cell.day-today {
        border: 1.5px solid var(--primary);
        font-weight: 700;
    }

    .mini-day-cell:hover {
        background-color: var(--surface-hover);
    }

    .mini-day-dots {
        display: flex;
        gap: 1.5px;
        margin-top: 1px;
    }

    .mini-dot {
        width: 4px;
        height: 4px;
        border-radius: 50%;
    }

    /* Modais Interativos */
    .cal-modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.65);
        backdrop-filter: blur(3px);
        z-index: 2000;
        align-items: center;
        justify-content: center;
        padding: 1rem;
    }

    .cal-modal-overlay.active {
        display: flex;
    }

    .cal-modal {
        background: #FFFFFF;
        border-radius: var(--radius-lg);
        max-width: 540px;
        width: 100%;
        max-height: 90vh;
        overflow-y: auto;
        box-shadow: var(--shadow-md);
        border: 1px solid var(--border);
    }

    .cal-modal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 1.25rem 1.5rem;
        border-bottom: 1px solid var(--border);
    }

    .cal-modal-title {
        font-size: 1.125rem;
        font-weight: 700;
        color: var(--dark);
    }

    .cal-modal-close {
        background: none;
        border: none;
        font-size: 1.5rem;
        color: var(--text-muted);
        cursor: pointer;
    }

    .cal-modal-body {
        padding: 1.5rem;
    }

    .form-group {
        margin-bottom: 1rem;
    }

    .form-label {
        display: block;
        font-size: 0.8125rem;
        font-weight: 600;
        color: var(--dark);
        margin-bottom: 0.35rem;
    }

    .form-control {
        width: 100%;
        padding: 0.6rem 0.75rem;
        border: 1px solid var(--border);
        border-radius: 7px;
        font-size: 0.875rem;
        font-family: inherit;
        transition: border-color 0.15s ease;
    }

    .form-control:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(14, 116, 144, 0.15);
    }

    .btn-submit {
        background: var(--primary);
        color: #FFFFFF;
        border: none;
        padding: 0.65rem 1.25rem;
        border-radius: 8px;
        font-weight: 600;
        font-size: 0.875rem;
        cursor: pointer;
        transition: background-color 0.15s ease;
    }

    .btn-submit:hover {
        background: var(--primary-hover);
    }

    .alert-box {
        padding: 0.875rem 1.25rem;
        border-radius: var(--radius-md);
        font-size: 0.875rem;
        margin-bottom: 1.25rem;
        display: flex;
        align-items: flex-start;
        gap: 0.6rem;
    }

    .alert-danger {
        background: var(--danger-bg);
        border: 1px solid #FCA5A5;
        color: var(--danger);
    }

    .alert-success {
        background: var(--success-bg);
        border: 1px solid #86EFAC;
        color: var(--success);
    }

    @media (max-width: 768px) {
        .cal-day-cell {
            min-height: 85px;
            padding: 0.25rem;
        }
        .class-chip {
            display: none;
        }
        .turn-slot {
            font-size: 0.6rem;
            height: 16px;
        }
    }
</style>

<div class="cal-container">

    <!-- Alertas de Feedback -->
    <?php if ($feedbackError): ?>
        <div class="alert-box alert-danger" role="alert">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <div>
                <strong>Atenção ao Agendamento:</strong> <?= htmlspecialchars($feedbackError, ENT_QUOTES, 'UTF-8') ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($feedbackSuccess): ?>
        <div class="alert-box alert-success" role="alert">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <div>
                <?= htmlspecialchars($feedbackSuccess, ENT_QUOTES, 'UTF-8') ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Barra de Controle do Calendário -->
    <div class="cal-header-bar">
        <div class="cal-title-area">
            <h1>
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <span>Calendário de Capacidade</span>
            </h1>
            <p>
                <?= $view === 'mensal' ? "Visão Mensal Detalhada — {$calendarData['nome_mes']} de {$ano}" : "Visão Anual Estratégica — Ano de {$ano}" ?>
            </p>
        </div>

        <div class="cal-nav-controls">
            <!-- Alternância de Visão -->
            <div class="view-switcher">
                <a href="/diario/calendario?view=mensal&ano=<?= $ano ?>&mes=<?= $mes ?>" class="view-btn <?= $view === 'mensal' ? 'active' : '' ?>">Visão Mensal</a>
                <a href="/diario/calendario?view=anual&ano=<?= $ano ?>" class="view-btn <?= $view === 'anual' ? 'active' : '' ?>">Visão Anual</a>
            </div>

            <!-- Navegação Temporal -->
            <?php if ($view === 'mensal'): ?>
                <a href="/diario/calendario?view=mensal&ano=<?= $calendarData['nav']['prev_ano'] ?>&mes=<?= $calendarData['nav']['prev_mes'] ?>" class="nav-arrow-btn" title="Mês Anterior">‹</a>
                <a href="/diario/calendario?view=mensal&ano=<?= $calendarData['nav']['hoje_ano'] ?>&mes=<?= $calendarData['nav']['hoje_mes'] ?>" class="btn-today">Hoje</a>
                <a href="/diario/calendario?view=mensal&ano=<?= $calendarData['nav']['next_ano'] ?>&mes=<?= $calendarData['nav']['next_mes'] ?>" class="nav-arrow-btn" title="Próximo Mês">›</a>
            <?php else: ?>
                <a href="/diario/calendario?view=anual&ano=<?= $calendarData['nav']['prev_ano'] ?>" class="nav-arrow-btn" title="Ano Anterior">‹</a>
                <a href="/diario/calendario?view=anual&ano=<?= $calendarData['nav']['hoje_ano'] ?>" class="btn-today">Ano Atual</a>
                <a href="/diario/calendario?view=anual&ano=<?= $calendarData['nav']['next_ano'] ?>" class="nav-arrow-btn" title="Próximo Ano">›</a>
            <?php endif; ?>

            <button type="button" class="btn-submit" onclick="openFastScheduleModal('<?= $hoje ?>', 'V')">+ Agendar</button>
        </div>
    </div>

    <!-- Legenda Acessível dos Turnos Pedagógicos -->
    <div class="turnos-legend">
        <span class="legend-title">Turnos Pedagógicos (Slots Fixos Acessíveis):</span>
        <?php foreach ($turnosConfig as $sigla => $t): ?>
            <div style="display: flex; align-items: center; gap: 0.35rem;">
                <span class="shift-badge" style="background: <?= $t['cor_fundo'] ?>; color: <?= $t['cor_texto'] ?>; border-color: <?= $t['cor_borda'] ?>;">
                    <?= $t['letra'] ?>
                </span>
                <span style="font-weight: 500; color: var(--text);"><?= $t['nome'] ?></span>
            </div>
        <?php endforeach; ?>
        <div style="margin-left: auto; display: flex; align-items: center; gap: 0.5rem; color: var(--text-muted);">
            <span style="display: inline-block; width: 10px; height: 10px; background: #94A3B8; border-radius: 2px; opacity: 0.6;"></span>
            <span>Datas anteriores a hoje aparecem desbotadas</span>
        </div>
    </div>

    <?php if ($view === 'mensal'): ?>
        <!-- ===================================================================
             VISÃO MENSAL DETALHADA
             =================================================================== -->
        <div class="cal-month-grid">
            <div class="cal-weekdays-header">
                <?php foreach ($calendarData['dias_semana_abrv'] as $dNome): ?>
                    <div class="cal-weekday-cell"><?= $dNome ?></div>
                <?php endforeach; ?>
            </div>

            <div class="cal-days-body">
                <?php foreach ($calendarData['semanas'] as $semana): ?>
                    <?php foreach ($semana as $dia): ?>
                        <?php
                        $dayClasses = ['cal-day-cell'];
                        if (!$dia['is_current_month']) {
                            $dayClasses[] = 'other-month';
                        }
                        if ($dia['is_past']) {
                            $dayClasses[] = 'day-past';
                        }
                        if ($dia['is_today']) {
                            $dayClasses[] = 'day-today';
                        }
                        $popoverJson = !empty($dia['popover']) ? htmlspecialchars(json_encode($dia['popover']), ENT_QUOTES, 'UTF-8') : '';
                        ?>
                        <div class="<?= implode(' ', $dayClasses) ?>"
                             data-date="<?= $dia['data'] ?>"
                             data-popover='<?= $popoverJson ?>'
                             onclick="handleDayClick('<?= $dia['data'] ?>', this)">
                            
                            <div class="day-top-bar">
                                <span class="day-number"><?= $dia['dia'] ?></span>
                                <?php if ($dia['is_today']): ?>
                                    <span class="today-tag">Hoje</span>
                                <?php endif; ?>
                            </div>

                            <!-- Lista de Chips de Encontros (se houver) -->
                            <?php if (!empty($dia['encontros'])): ?>
                                <div class="day-classes-list">
                                    <?php foreach (array_slice($dia['encontros'], 0, 2) as $enc): ?>
                                        <?php $tCfg = $enc['turno_config']; ?>
                                        <div class="class-chip" style="background: <?= $tCfg['cor_fundo'] ?>; color: <?= $tCfg['cor_texto'] ?>; border: 1px solid <?= $tCfg['cor_borda'] ?>;" title="<?= htmlspecialchars($enc['curso_nome'], ENT_QUOTES, 'UTF-8') ?>">
                                            <strong>[<?= $enc['turno'] ?>]</strong>
                                            <span><?= htmlspecialchars($enc['curso_nome'], ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (count($dia['encontros']) > 2): ?>
                                        <span style="font-size: 0.65rem; color: var(--text-muted); font-weight: 600;">+<?= count($dia['encontros']) - 2 ?> mais</span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <!-- Slots Fixos Dedicados para os 4 Turnos: [M] [V] [N] [D] -->
                            <div class="dedicated-turn-slots" title="Slots Dedicados: [M] Matutino | [V] Vespertino | [N] Noturno | [D] Dia Todo">
                                <?php foreach (['M', 'V', 'N', 'D'] as $sSigla): ?>
                                    <?php
                                    $isOcupado = in_array($sSigla, $dia['turnos_ocupados'], true) || (in_array('D', $dia['turnos_ocupados'], true) && $sSigla !== 'D');
                                    $sCfg = $turnosConfig[$sSigla];
                                    ?>
                                    <?php if ($isOcupado): ?>
                                        <div class="turn-slot" style="background: <?= $sCfg['cor_fundo'] ?>; color: <?= $sCfg['cor_texto'] ?>; border: 1px solid <?= $sCfg['cor_borda'] ?>;">
                                            <?= $sSigla ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="turn-slot empty-slot">
                                            <?= $sSigla ?>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        </div>

    <?php else: ?>
        <!-- ===================================================================
             VISÃO ANUAL (12 MESES)
             =================================================================== -->
        <div class="year-grid">
            <?php foreach ($calendarData['meses'] as $mNum => $m): ?>
                <div class="mini-month-card">
                    <div class="mini-month-header">
                        <a href="/diario/calendario?view=mensal&ano=<?= $ano ?>&mes=<?= $mNum ?>" class="mini-month-title">
                            <?= $m['nome'] ?>
                        </a>
                        <?php if ($m['total_aulas'] > 0): ?>
                            <span class="mini-month-badge"><?= $m['total_aulas'] ?> <?= $m['total_aulas'] === 1 ? 'aula' : 'aulas' ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="mini-weekdays">
                        <span>D</span><span>S</span><span>T</span><span>Q</span><span>Q</span><span>S</span><span>S</span>
                    </div>

                    <div class="mini-days-grid">
                        <?php for ($p = 0; $p < $m['start_weekday']; $p++): ?>
                            <div class="mini-day-cell" style="opacity: 0.1;">·</div>
                        <?php endfor; ?>

                        <?php foreach ($m['dias'] as $dNum => $d): ?>
                            <?php
                            $miniClasses = ['mini-day-cell'];
                            if ($d['is_past']) {
                                $miniClasses[] = 'day-past';
                            }
                            if ($d['is_today']) {
                                $miniClasses[] = 'day-today';
                            }
                            $popoverJson = !empty($d['popover']) ? htmlspecialchars(json_encode($d['popover']), ENT_QUOTES, 'UTF-8') : '';
                            ?>
                            <div class="<?= implode(' ', $miniClasses) ?>"
                                 data-date="<?= $d['data'] ?>"
                                 data-popover='<?= $popoverJson ?>'
                                 onclick="handleDayClick('<?= $d['data'] ?>', this)">
                                <span><?= $dNum ?></span>
                                <?php if (!empty($d['turnos_ocupados'])): ?>
                                    <div class="mini-day-dots">
                                        <?php foreach ($d['turnos_ocupados'] as $tOcup): ?>
                                            <span class="mini-dot" style="background: <?= $turnosConfig[$tOcup]['cor_texto'] ?? '#0E7490' ?>;"></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<!-- Popover Flutuante Interativo (Rollover) -->
<div id="calendarPopover" class="cal-popover"></div>

<!-- Modal de Detalhes do Dia & Atalhos de Chamada -->
<div id="dayDetailsModal" class="cal-modal-overlay">
    <div class="cal-modal">
        <div class="cal-modal-header">
            <h3 class="cal-modal-title" id="modalDayTitle">Compromissos do Dia</h3>
            <button type="button" class="cal-modal-close" onclick="closeDayModal()">&times;</button>
        </div>
        <div class="cal-modal-body" id="modalDayContent">
            <!-- Injetado via JS -->
        </div>
    </div>
</div>

<!-- Modal de Agendamento Rápido -->
<div id="fastScheduleModal" class="cal-modal-overlay">
    <div class="cal-modal">
        <div class="cal-modal-header">
            <h3 class="cal-modal-title">Novo Agendamento / Reposição</h3>
            <button type="button" class="cal-modal-close" onclick="closeFastScheduleModal()">&times;</button>
        </div>
        <div class="cal-modal-body">
            <!-- Tabs para escolher Turma Existente vs Nova Turma -->
            <div style="display: flex; gap: 0.5rem; margin-bottom: 1.25rem; background: var(--bg); padding: 4px; border-radius: 8px;">
                <button type="button" id="tabEncontroBtn" class="view-btn active" style="flex: 1; border: none; cursor: pointer;" onclick="switchScheduleTab('encontro')">Aula em Turma Existente</button>
                <button type="button" id="tabNovaTurmaBtn" class="view-btn" style="flex: 1; border: none; cursor: pointer;" onclick="switchScheduleTab('nova_turma')">Cadastrar Nova Turma</button>
            </div>

            <!-- Formulário 1: Aula em Turma Existente -->
            <form id="formAgendarEncontro" method="POST" action="/diario/calendario">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="acao" value="agendar_encontro">

                <div class="form-group">
                    <label class="form-label" for="enc_data">Data da Aula:</label>
                    <input type="date" id="enc_data" name="data_encontro" class="form-control" required value="<?= $hoje ?>">
                </div>

                <div class="form-group">
                    <label class="form-label" for="enc_turma">Turma Confirmada:</label>
                    <select id="enc_turma" name="turma_id" class="form-control" required>
                        <option value="">Selecione uma turma...</option>
                        <?php foreach ($turmasDisponiveis as $t): ?>
                            <option value="<?= $t['id'] ?>" data-turno="<?= $t['turno_padrao'] ?>">
                                <?= htmlspecialchars($t['curso_nome'], ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars($t['cliente_nome'] ?: 'Sem cliente', ENT_QUOTES, 'UTF-8') ?> [<?= $t['turno_padrao'] ?>]
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="enc_turno">Turno da Aula:</label>
                    <select id="enc_turno" name="turno" class="form-control" required>
                        <option value="M">[M] Matutino (08:00 - 12:00)</option>
                        <option value="V" selected>[V] Vespertino (14:00 - 18:00)</option>
                        <option value="N">[N] Noturno (19:00 - 22:30)</option>
                        <option value="D">[D] Dia Todo / Integral (08:00 - 17:00)</option>
                    </select>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                    <div class="form-group">
                        <label class="form-label" for="enc_h_ini">Horário Início:</label>
                        <input type="time" id="enc_h_ini" name="horario_inicio" class="form-control" value="14:00">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="enc_h_fim">Horário Fim:</label>
                        <input type="time" id="enc_h_fim" name="horario_fim" class="form-control" value="18:00">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="enc_conteudo">Conteúdo Previsto (Plano de Aula):</label>
                    <input type="text" id="enc_conteudo" name="conteudo_previsto" class="form-control" placeholder="Ex: Módulo 2: DAX e Medidas Calculadas">
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 1.25rem;">
                    <button type="button" class="view-btn" onclick="closeFastScheduleModal()">Cancelar</button>
                    <button type="submit" class="btn-submit">Confirmar Agendamento</button>
                </div>
            </form>

            <!-- Formulário 2: Cadastrar Nova Turma -->
            <form id="formAgendarNovaTurma" method="POST" action="/diario/calendario" style="display: none;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="acao" value="agendar_nova_turma">

                <div class="form-group">
                    <label class="form-label" for="nova_curso">Nome do Curso / Treinamento:</label>
                    <input type="text" id="nova_curso" name="curso_nome" class="form-control" required placeholder="Ex: Power BI Corporativo">
                </div>

                <div class="form-group">
                    <label class="form-label" for="nova_cliente">Cliente / Empresa Contratante:</label>
                    <input type="text" id="nova_cliente" name="cliente_nome" class="form-control" placeholder="Ex: Sicoob Credisul">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                    <div class="form-group">
                        <label class="form-label" for="nova_data">Data de Início:</label>
                        <input type="date" id="nova_data" name="data_encontro" class="form-control" required value="<?= $hoje ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="nova_turno">Turno:</label>
                        <select id="nova_turno" name="turno" class="form-control" required>
                            <option value="M">[M] Matutino</option>
                            <option value="V" selected>[V] Vespertino</option>
                            <option value="N">[N] Noturno</option>
                            <option value="D">[D] Dia Todo</option>
                        </select>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                    <div class="form-group">
                        <label class="form-label" for="nova_carga">Carga Horária (h):</label>
                        <input type="number" id="nova_carga" name="carga_horaria" class="form-control" value="16" min="1">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="nova_modalidade">Modalidade:</label>
                        <select id="nova_modalidade" name="modalidade" class="form-control">
                            <option value="Presencial">Presencial</option>
                            <option value="Ao Vivo Online">Ao Vivo Online</option>
                            <option value="Híbrido">Híbrido</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="nova_conteudo">Conteúdo Inicial Previsto:</label>
                    <input type="text" id="nova_conteudo" name="conteudo_previsto" class="form-control" placeholder="Ex: Módulo 1: Fundamentos">
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 1.25rem;">
                    <button type="button" class="view-btn" onclick="closeFastScheduleModal()">Cancelar</button>
                    <button type="submit" class="btn-submit">Criar e Agendar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- JavaScript Interativo para Popover e Ações -->
<script>
    const popoverEl = document.getElementById('calendarPopover');
    let popoverTimeout = null;

    // Gerenciador de Rollover / Hover no Desktop
    document.querySelectorAll('[data-popover]').forEach(el => {
        el.addEventListener('mouseenter', (e) => {
            const rawData = el.getAttribute('data-popover');
            if (!rawData) return;
            try {
                const data = JSON.parse(rawData);
                showPopover(e, data);
            } catch (err) {
                console.error("Erro no popover:", err);
            }
        });

        el.addEventListener('mouseleave', () => {
            hidePopover();
        });
    });

    function showPopover(e, data) {
        clearTimeout(popoverTimeout);
        if (!data || !data.encontros || data.encontros.length === 0) return;

        let html = `
            <div class="popover-header">
                <span class="popover-date">${data.data_formatada} (${data.dia_semana})</span>
                <span style="font-size: 0.75rem; color: var(--text-muted); font-weight: 600;">${data.encontros.length} aula(s)</span>
            </div>
        `;

        data.encontros.forEach(enc => {
            html += `
                <div class="popover-item">
                    <div class="popover-item-title">[${enc.turno_sigla}] ${enc.curso}</div>
                    <div class="popover-item-client">${enc.cliente}</div>
                    <div class="popover-item-footer">
                        <span style="font-weight: 600; color: var(--primary);">${enc.horario}</span>
                        <span style="text-transform: uppercase; font-size: 0.65rem; background: #E2E8F0; padding: 1px 5px; border-radius: 4px;">${enc.status_turma}</span>
                    </div>
                </div>
            `;
        });

        popoverEl.innerHTML = html;
        popoverEl.classList.add('visible');

        const rect = e.target.getBoundingClientRect();
        const popoverWidth = 290;
        let left = rect.left + window.scrollX + (rect.width / 2) - (popoverWidth / 2);
        let top = rect.top + window.scrollY - 10 - popoverEl.offsetHeight;

        // Previne transbordo lateral
        if (left < 10) left = 10;
        if (left + popoverWidth > window.innerWidth - 10) {
            left = window.innerWidth - popoverWidth - 10;
        }
        if (top < window.scrollY + 10) {
            top = rect.bottom + window.scrollY + 10;
        }

        popoverEl.style.left = `${left}px`;
        popoverEl.style.top = `${top}px`;
    }

    function hidePopover() {
        popoverTimeout = setTimeout(() => {
            popoverEl.classList.remove('visible');
        }, 120);
    }

    // Clique no Dia
    function handleDayClick(dateStr, cellEl) {
        const rawData = cellEl.getAttribute('data-popover');
        if (rawData) {
            try {
                const data = JSON.parse(rawData);
                if (data && data.encontros && data.encontros.length > 0) {
                    openDayModalWithData(data);
                    return;
                }
            } catch (err) {}
        }
        // Dia livre: abre direto o agendamento rápido
        openFastScheduleModal(dateStr, 'V');
    }

    function openDayModalWithData(data) {
        document.getElementById('modalDayTitle').innerText = `${data.data_formatada} — ${data.dia_semana}`;
        let html = `<div style="margin-bottom: 1.25rem;">`;

        data.encontros.forEach(enc => {
            html += `
                <div style="background: var(--bg); border: 1px solid var(--border); border-radius: 8px; padding: 1rem; margin-bottom: 0.75rem;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.35rem;">
                        <h4 style="font-size: 0.9375rem; font-weight: 700; color: var(--dark);">[${enc.turno_sigla}] ${enc.curso}</h4>
                        <span style="background: #E0F2FE; color: #0369A1; font-weight: 700; font-size: 0.7rem; padding: 2px 6px; border-radius: 4px;">${enc.status_turma}</span>
                    </div>
                    <p style="font-size: 0.8125rem; color: var(--text-muted); margin-bottom: 0.5rem;">Cliente: <strong>${enc.cliente}</strong> | Horário: <strong>${enc.horario}</strong></p>
                    
                    <div style="display: flex; gap: 0.5rem; margin-top: 0.75rem;">
                        <a href="/diario/turmas?turma_id=${enc.turma_id}&encontro_id=${enc.encontro_id}" class="btn-submit" style="font-size: 0.78rem; text-decoration: none; padding: 0.4rem 0.8rem;">
                            Abrir Diário & Chamada
                        </a>
                    </div>
                </div>
            `;
        });

        html += `</div>`;

        // Se houver turnos livres, permite agendar
        html += `
            <div style="border-top: 1px solid var(--border); padding-top: 1rem; display: flex; justify-content: space-between; align-items: center;">
                <span style="font-size: 0.8125rem; color: var(--text-muted);">Precisa lançar outra aula neste dia?</span>
                <button type="button" class="btn-new-schedule" onclick="closeDayModal(); openFastScheduleModal('${data.data}', 'V')">+ Agendar Outro Turno</button>
            </div>
        `;

        document.getElementById('modalDayContent').innerHTML = html;
        document.getElementById('dayDetailsModal').classList.add('active');
    }

    function closeDayModal() {
        document.getElementById('dayDetailsModal').classList.remove('active');
    }

    function openFastScheduleModal(dateStr, defaultTurno) {
        document.getElementById('enc_data').value = dateStr;
        document.getElementById('nova_data').value = dateStr;
        if (defaultTurno) {
            document.getElementById('enc_turno').value = defaultTurno;
            document.getElementById('nova_turno').value = defaultTurno;
        }
        document.getElementById('fastScheduleModal').classList.add('active');
    }

    function closeFastScheduleModal() {
        document.getElementById('fastScheduleModal').classList.remove('active');
    }

    function switchScheduleTab(tab) {
        if (tab === 'encontro') {
            document.getElementById('formAgendarEncontro').style.display = 'block';
            document.getElementById('formAgendarNovaTurma').style.display = 'none';
            document.getElementById('tabEncontroBtn').classList.add('active');
            document.getElementById('tabNovaTurmaBtn').classList.remove('active');
        } else {
            document.getElementById('formAgendarEncontro').style.display = 'none';
            document.getElementById('formAgendarNovaTurma').style.display = 'block';
            document.getElementById('tabEncontroBtn').classList.remove('active');
            document.getElementById('tabNovaTurmaBtn').classList.add('active');
        }
    }

    // Fechar modais ao clicar no overlay
    window.addEventListener('click', (e) => {
        if (e.target.classList.contains('cal-modal-overlay')) {
            closeDayModal();
            closeFastScheduleModal();
        }
    });
</script>

<?php
$contentHtml = ob_get_clean();

// 4. Renderiza Layout Administrativo Oficial
renderAdminLayout(
    title: 'Calendário de Capacidade',
    activeNav: 'calendario',
    contentHtml: $contentHtml,
    user: $user,
    breadcrumbs: [
        'Painel'     => '/diario',
        'Calendário' => null,
    ]
);
