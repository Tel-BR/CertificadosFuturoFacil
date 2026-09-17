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
        $confirmarExcecao = !empty($_POST['confirmar_excecao_feriado']);

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
                    'turma_id'                  => $turmaId,
                    'numero_encontro'           => $proxNumero,
                    'data_encontro'             => $dataEncontro,
                    'turno'                     => $turno,
                    'horario_inicio'            => $hIni ?: null,
                    'horario_fim'               => $hFim ?: null,
                    'conteudo_previsto'         => $conteudo ?: null,
                    'confirmar_excecao_feriado' => $confirmarExcecao,
                ]);

                $feedbackSuccess = "Aula/reposição agendada com sucesso para o dia " . date('d/m/Y', strtotime($dataEncontro)) . " no turno [{$turno}].";
            } catch (InvalidArgumentException $e) {
                $feedbackError = $e->getMessage();
            } catch (Throwable $e) {
                $feedbackError = "Erro inesperado ao registrar agendamento: " . $e->getMessage();
            }
        } elseif ($acao === 'agendar_deslocamento') {
            $turmaId = (int)($_POST['turma_id'] ?? 0);
            $dataEncontro = trim((string)($_POST['data_encontro'] ?? ''));
            $turno = strtoupper(trim((string)($_POST['turno'] ?? 'V')));
            $direcao = trim((string)($_POST['direcao'] ?? 'ida'));
            $descricao = trim((string)($_POST['descricao'] ?? ''));

            try {
                $calendarService->scheduleDeslocamento(
                    $turmaId,
                    $dataEncontro,
                    $turno,
                    $direcao,
                    $descricao ?: null,
                    $confirmarExcecao
                );

                $feedbackSuccess = "Deslocamento logístico (✈) agendado com sucesso para o dia " . date('d/m/Y', strtotime($dataEncontro)) . " no turno [{$turno}].";
            } catch (InvalidArgumentException $e) {
                $feedbackError = $e->getMessage();
            } catch (Throwable $e) {
                $feedbackError = "Falha ao registrar deslocamento logístico: " . $e->getMessage();
            }
        } elseif ($acao === 'remarcar_turma') {
            $turmaId = (int)($_POST['turma_id'] ?? 0);
            $novaDataInicio = trim((string)($_POST['nova_data_inicio'] ?? ''));

            try {
                $res = $calendarService->rescheduleTurma(
                    $turmaId,
                    $novaDataInicio,
                    null,
                    $confirmarExcecao
                );

                $feedbackSuccess = "Turma remarcada em bloco com sucesso! Novo período: " . date('d/m/Y', strtotime($res['nova_data_inicio'])) . " a " . date('d/m/Y', strtotime($res['nova_data_conclusao'])) . " ({$res['total_encontros_movidos']} encontros/deslocamentos transferidos sem colisões).";
            } catch (InvalidArgumentException $e) {
                $feedbackError = $e->getMessage();
            } catch (Throwable $e) {
                $feedbackError = "Falha ao remarcar turma: " . $e->getMessage();
            }
        } elseif ($acao === 'adicionar_bloqueio') {
            $dataBloqueio = trim((string)($_POST['data_bloqueio'] ?? ''));
            $descricao = trim((string)($_POST['descricao'] ?? ''));
            $tipo = trim((string)($_POST['tipo'] ?? 'bloqueio_pessoal'));
            $bloqueante = !empty($_POST['bloqueante']);
            $permiteExcecao = !empty($_POST['permite_excecao']);

            try {
                $calendarService->addBloqueio(
                    $dataBloqueio,
                    $descricao,
                    $tipo,
                    $bloqueante,
                    $permiteExcecao
                );

                $feedbackSuccess = "Bloqueio de agenda registrado com sucesso para " . date('d/m/Y', strtotime($dataBloqueio)) . ".";
            } catch (InvalidArgumentException $e) {
                $feedbackError = $e->getMessage();
            } catch (Throwable $e) {
                $feedbackError = "Falha ao registrar bloqueio de agenda: " . $e->getMessage();
            }
        } elseif ($acao === 'agendar_nova_turma') {
            $cursoNome = trim((string)($_POST['curso_nome'] ?? ''));
            $clienteNome = trim((string)($_POST['cliente_nome'] ?? ''));
            $cidade = trim((string)($_POST['cidade'] ?? 'Goiânia - GO'));
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
                    // Validação prévia de choque de horário (Costura de Teste 5 e Feriados)
                    $conflito = $calendarService->checkConflict($dataEncontro, $turno, null, null, $confirmarExcecao);
                    if ($conflito !== null) {
                        throw new InvalidArgumentException($conflito['mensagem']);
                    }

                    $pdo->beginTransaction();

                    $codigoTurma = 'TURMA-' . date('Ymd') . '-' . substr(bin2hex(random_bytes(3)), 0, 5);
                    $chaveAcesso = strtolower(preg_replace('/[^a-z0-9]/', '', $cursoNome) ?? 'turma') . '-' . rand(100, 999);

                    $stmtNovaTurma = $pdo->prepare("
                        INSERT INTO turmas (
                            codigo_turma, curso_nome, cliente_nome, cidade, carga_horaria,
                            data_inicio, data_conclusao, turno_padrao, status, chave_acesso, modalidade
                        ) VALUES (
                            :codigo, :curso, :cliente, :cidade, :carga,
                            :data_inicio, :data_conclusao, :turno, 'prevista', :chave, :modalidade
                        )
                    ");

                    $stmtNovaTurma->execute([
                        ':codigo'         => $codigoTurma,
                        ':curso'          => $cursoNome,
                        ':cliente'        => $clienteNome ?: null,
                        ':cidade'         => $cidade ?: 'Goiânia - GO',
                        ':carga'          => $cargaHoraria,
                        ':data_inicio'    => $dataEncontro,
                        ':data_conclusao' => $dataEncontro,
                        ':turno'          => $turno,
                        ':chave'          => $chaveAcesso,
                        ':modalidade'     => $modalidade,
                    ]);

                    $turmaId = (int)$pdo->lastInsertId();

                    $calendarService->scheduleEncontro([
                        'turma_id'                  => $turmaId,
                        'numero_encontro'           => 1,
                        'data_encontro'             => $dataEncontro,
                        'turno'                     => $turno,
                        'horario_inicio'            => $hIni ?: null,
                        'horario_fim'               => $hFim ?: null,
                        'conteudo_previsto'         => $conteudo ?: null,
                        'confirmar_excecao_feriado' => $confirmarExcecao,
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

// Semeia feriados nacionais oficiais para o ano em exibição
$calendarService->seedFeriadosNacionais($ano);

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
             VISÃO MENSAL DETALHADA & MEDIDOR DE CAPACIDADE (TETO 80H)
             =================================================================== -->
        <?php 
        $workload = $calendarData['workload'] ?? [
            'total_horas' => 0, 'teto_horas' => 80.0, 'porcentagem' => 0, 'is_sobrecarga' => false, 'saldo_horas' => 80.0, 'total_encontros' => 0, 'total_deslocamentos' => 0
        ];
        $pct = min(100, (float)$workload['porcentagem']);
        $barColor = $workload['is_sobrecarga'] ? '#DC2626' : ((float)$workload['porcentagem'] >= 75 ? '#D97706' : '#0E7490');
        ?>

        <?php if ($workload['is_sobrecarga']): ?>
            <div class="alert-box alert-danger" style="background: #FEF2F2; border: 1.5px solid #F87171; color: #991B1B; margin-bottom: 1.25rem;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <div>
                    <div style="font-weight: 800; font-size: 0.95rem; margin-bottom: 0.25rem;">
                        ⚠️ ALERTA SEVERO DE SOBRECARGA — TETO DE 80H MENSAIS EXCEDIDO
                    </div>
                    <p style="margin: 0; font-size: 0.875rem; line-height: 1.4;">
                        A carga horária acumulada para <strong><?= $calendarData['nome_mes'] ?>/<?= $ano ?></strong> atingiu <strong><?= $workload['total_horas'] ?>h</strong>, superando o teto operacional estipulado em <strong><?= abs($workload['saldo_horas']) ?>h</strong> (<?= $workload['porcentagem'] ?>% da capacidade). 
                        Este aviso tem caráter <strong>preventivo e não-bloqueante</strong>, permitindo agendamentos adicionais caso indispensável, mas recomenda-se redistribuir as turmas para garantir a qualidade de entrega e evitar estafa.
                    </p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Card Medidor de Capacidade Mensal -->
        <div class="capacity-meter-card" style="background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 1rem 1.25rem; margin-bottom: 1.25rem; box-shadow: var(--shadow-sm);">
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem; margin-bottom: 0.6rem;">
                <div style="display: flex; align-items: center; gap: 0.6rem;">
                    <span style="display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 6px; background: <?= $workload['is_sobrecarga'] ? '#FEE2E2' : '#ECFEFF' ?>; color: <?= $workload['is_sobrecarga'] ? '#DC2626' : 'var(--primary)' ?>; font-weight: 700;">⏱️</span>
                    <span style="font-weight: 700; color: var(--dark); font-size: 0.9375rem;">Capacidade Pedagógica Mensal:</span>
                    <strong style="font-size: 1.1rem; color: <?= $workload['is_sobrecarga'] ? '#DC2626' : 'var(--primary)' ?>; font-family: var(--font-mono);"><?= $workload['total_horas'] ?>h / 80h</strong>
                    <span style="font-size: 0.8125rem; font-weight: 600; color: var(--text-muted);">(<?= $workload['porcentagem'] ?>% alocado)</span>
                </div>
                <div style="display: flex; align-items: center; gap: 1rem; font-size: 0.8125rem; color: var(--text-muted);">
                    <span>Saldo Livre: <strong style="color: <?= $workload['saldo_horas'] < 0 ? '#DC2626' : '#16A34A' ?>;"><?= $workload['saldo_horas'] ?>h</strong></span>
                    <span>•</span>
                    <span>Aulas: <strong><?= $workload['total_encontros'] ?></strong></span>
                    <span>•</span>
                    <span>Deslocamentos: <strong><?= $workload['total_deslocamentos'] ?> ✈</strong></span>
                </div>
            </div>
            <div style="background: #E2E8F0; border-radius: 999px; height: 10px; overflow: hidden; position: relative;">
                <div style="background: <?= $barColor ?>; width: <?= $pct ?>%; height: 100%; border-radius: 999px; transition: width 0.3s ease;"></div>
            </div>
        </div>

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
                        if (!empty($dia['bloqueio'])) {
                            $dayClasses[] = 'day-bloqueio';
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

                            <!-- Indicação de Feriado Nacional / Bloqueio / Sugestão de Ponte -->
                            <?php if (!empty($dia['bloqueio'])): ?>
                                <div class="bloqueio-chip" style="background: <?= $dia['bloqueio']['tipo'] === 'feriado_nacional' ? '#FEF3C7' : '#F1F5F9' ?>; color: <?= $dia['bloqueio']['tipo'] === 'feriado_nacional' ? '#92400E' : '#334155' ?>; border: 1px solid <?= $dia['bloqueio']['tipo'] === 'feriado_nacional' ? '#FDE68A' : '#CBD5E1' ?>; font-size: 0.65rem; font-weight: 700; padding: 2px 5px; border-radius: 4px; margin-bottom: 3px; display: flex; align-items: center; gap: 3px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($dia['bloqueio']['descricao'], ENT_QUOTES, 'UTF-8') ?>">
                                    <span><?= $dia['bloqueio']['tipo'] === 'feriado_nacional' ? '🇧🇷' : '🔒' ?></span>
                                    <span style="overflow: hidden; text-overflow: ellipsis;"><?= htmlspecialchars($dia['bloqueio']['descricao'], ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                            <?php elseif (!empty($dia['ponte'])): ?>
                                <div class="ponte-chip" style="background: #F0FDF4; color: #166534; border: 1px dashed #86EFAC; font-size: 0.625rem; font-weight: 600; padding: 1px 4px; border-radius: 4px; margin-bottom: 3px; display: flex; align-items: center; gap: 3px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($dia['ponte']['sugestao'], ENT_QUOTES, 'UTF-8') ?>">
                                    <span>🏖️</span>
                                    <span>Ponte</span>
                                </div>
                            <?php endif; ?>

                            <!-- Lista de Chips de Encontros e Deslocamentos -->
                            <?php if (!empty($dia['encontros'])): ?>
                                <div class="day-classes-list">
                                    <?php foreach (array_slice($dia['encontros'], 0, 2) as $enc): ?>
                                        <?php 
                                        $isDesloc = (($enc['tipo'] ?? 'aula') === 'deslocamento');
                                        $tCfg = $enc['turno_config']; 
                                        ?>
                                        <?php if ($isDesloc): ?>
                                            <div class="class-chip" style="background: #EEF2FF; color: #4338CA; border: 1px solid #C7D2FE;" title="<?= htmlspecialchars($enc['curso_nome'], ENT_QUOTES, 'UTF-8') ?>">
                                                <strong>✈ [<?= $enc['turno'] ?>]</strong>
                                                <span><?= htmlspecialchars($enc['curso_nome'], ENT_QUOTES, 'UTF-8') ?></span>
                                            </div>
                                        <?php else: ?>
                                            <div class="class-chip" style="background: <?= $tCfg['cor_fundo'] ?>; color: <?= $tCfg['cor_texto'] ?>; border: 1px solid <?= $tCfg['cor_borda'] ?>;" title="<?= htmlspecialchars($enc['curso_nome'], ENT_QUOTES, 'UTF-8') ?>">
                                                <strong>[<?= $enc['turno'] ?>]</strong>
                                                <span><?= htmlspecialchars($enc['curso_nome'], ENT_QUOTES, 'UTF-8') ?></span>
                                            </div>
                                        <?php endif; ?>
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
                                    $isDeslocTurno = false;
                                    foreach ($dia['encontros'] as $encCheck) {
                                        if ($encCheck['turno'] === $sSigla && ($encCheck['tipo'] ?? 'aula') === 'deslocamento') {
                                            $isDeslocTurno = true;
                                            break;
                                        }
                                    }
                                    $sCfg = $turnosConfig[$sSigla];
                                    ?>
                                    <?php if ($isOcupado): ?>
                                        <?php if ($isDeslocTurno): ?>
                                            <div class="turn-slot" style="background: #EEF2FF; color: #4338CA; border: 1px solid #C7D2FE;" title="Deslocamento Logístico ✈">
                                                ✈
                                            </div>
                                        <?php else: ?>
                                            <div class="turn-slot" style="background: <?= $sCfg['cor_fundo'] ?>; color: <?= $sCfg['cor_texto'] ?>; border: 1px solid <?= $sCfg['cor_borda'] ?>;">
                                                <?= $sSigla ?>
                                            </div>
                                        <?php endif; ?>
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
                        <div style="display: flex; align-items: center; gap: 0.35rem;">
                            <?php if (!empty($m['workload']['is_sobrecarga'])): ?>
                                <span class="mini-month-badge" style="background: #FEE2E2; color: #DC2626;" title="Sobrecarga: <?= $m['workload']['total_horas'] ?>h (Teto de 80h excedido)">
                                    <?= $m['workload']['total_horas'] ?>h ⚠️
                                </span>
                            <?php elseif (!empty($m['workload']['total_horas']) && $m['workload']['total_horas'] > 0): ?>
                                <span class="mini-month-badge" style="background: #ECFEFF; color: var(--primary);" title="Carga pedagógica mensal">
                                    <?= $m['workload']['total_horas'] ?>h / 80h
                                </span>
                            <?php elseif ($m['total_aulas'] > 0): ?>
                                <span class="mini-month-badge"><?= $m['total_aulas'] ?> <?= $m['total_aulas'] === 1 ? 'aula' : 'aulas' ?></span>
                            <?php endif; ?>
                        </div>
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
            <h3 class="cal-modal-title">Gestão de Agenda & Agendamento</h3>
            <button type="button" class="cal-modal-close" onclick="closeFastScheduleModal()">&times;</button>
        </div>
        <div class="cal-modal-body">
            <!-- Tabs para escolher modo de agendamento -->
            <div style="display: flex; gap: 0.35rem; margin-bottom: 1.25rem; background: var(--bg); padding: 4px; border-radius: 8px; flex-wrap: wrap;">
                <button type="button" id="tabEncontroBtn" class="view-btn active" style="flex: 1; min-width: 110px; border: none; cursor: pointer; text-align: center;" onclick="switchScheduleTab('encontro')">Aula Pedagógica</button>
                <button type="button" id="tabNovaTurmaBtn" class="view-btn" style="flex: 1; min-width: 110px; border: none; cursor: pointer; text-align: center;" onclick="switchScheduleTab('nova_turma')">Nova Turma</button>
                <button type="button" id="tabDeslocamentoBtn" class="view-btn" style="flex: 1; min-width: 120px; border: none; cursor: pointer; text-align: center;" onclick="switchScheduleTab('deslocamento')">✈ Deslocamento</button>
                <button type="button" id="tabRemarcarBtn" class="view-btn" style="flex: 1; min-width: 120px; border: none; cursor: pointer; text-align: center;" onclick="switchScheduleTab('remarcar')">🔄 Remarcar em Bloco</button>
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

                <div style="background: #F8FAFC; border: 1px dashed var(--border); border-radius: 6px; padding: 0.6rem 0.85rem; margin-top: 0.75rem;">
                    <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.8125rem; cursor: pointer; color: var(--text);">
                        <input type="checkbox" name="confirmar_excecao_feriado" value="1">
                        <span>Confirmar <strong>exceção consciente</strong> se a data for feriado ou bloqueio</span>
                    </label>
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

                <div class="form-group">
                    <label class="form-label" for="nova_cidade">Cidade / Local de Realização:</label>
                    <input type="text" id="nova_cidade" name="cidade" class="form-control" value="Goiânia - GO" placeholder="Ex: Goiânia - GO, Rio Verde - GO">
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

                <div style="background: #F8FAFC; border: 1px dashed var(--border); border-radius: 6px; padding: 0.6rem 0.85rem; margin-top: 0.75rem;">
                    <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.8125rem; cursor: pointer; color: var(--text);">
                        <input type="checkbox" name="confirmar_excecao_feriado" value="1">
                        <span>Confirmar <strong>exceção consciente</strong> se a data for feriado ou bloqueio</span>
                    </label>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 1.25rem;">
                    <button type="button" class="view-btn" onclick="closeFastScheduleModal()">Cancelar</button>
                    <button type="submit" class="btn-submit">Criar e Agendar</button>
                </div>
            </form>

            <!-- Formulário 3: Agendar Deslocamento Logístico -->
            <form id="formAgendarDeslocamento" method="POST" action="/diario/calendario" style="display: none;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="acao" value="agendar_deslocamento">

                <div style="background: #EEF2FF; border: 1px solid #C7D2FE; border-radius: 6px; padding: 0.75rem 1rem; margin-bottom: 1rem; font-size: 0.8125rem; color: #3730A3;">
                    <strong>✈ Deslocamento Logístico:</strong> Bloqueia turnos de viagem (Goiânia ⇄ Cidade do cliente) na agenda, inclusive em fins de semana, prevenindo choques sem gerar lista de chamada nem computar carga horária de certificados.
                </div>

                <div class="form-group">
                    <label class="form-label" for="desl_turma">Turma Vinculada à Viagem:</label>
                    <select id="desl_turma" name="turma_id" class="form-control" required>
                        <option value="">Selecione uma turma...</option>
                        <?php foreach ($turmasDisponiveis as $t): ?>
                            <option value="<?= $t['id'] ?>">
                                <?= htmlspecialchars($t['curso_nome'], ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars($t['cliente_nome'] ?: 'Sem cliente', ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                    <div class="form-group">
                        <label class="form-label" for="desl_data">Data da Viagem / Deslocamento:</label>
                        <input type="date" id="desl_data" name="data_encontro" class="form-control" required value="<?= $hoje ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="desl_turno">Turno Reservado:</label>
                        <select id="desl_turno" name="turno" class="form-control" required>
                            <option value="M">[M] Matutino</option>
                            <option value="V" selected>[V] Vespertino</option>
                            <option value="N">[N] Noturno</option>
                            <option value="D">[D] Dia Todo</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="desl_direcao">Direção:</label>
                    <select id="desl_direcao" name="direcao" class="form-control" required>
                        <option value="ida">✈ Ida (Goiânia ➔ Cidade do Treinamento)</option>
                        <option value="volta">✈ Volta (Cidade do Treinamento ➔ Goiânia)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="desl_descricao">Descrição Personalizada (Opcional):</label>
                    <input type="text" id="desl_descricao" name="descricao" class="form-control" placeholder="Ex: Translado rodoviário / Voo comercial">
                </div>

                <div style="background: #F8FAFC; border: 1px dashed var(--border); border-radius: 6px; padding: 0.6rem 0.85rem; margin-top: 0.75rem;">
                    <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.8125rem; cursor: pointer; color: var(--text);">
                        <input type="checkbox" name="confirmar_excecao_feriado" value="1">
                        <span>Confirmar <strong>exceção consciente</strong> se a viagem for em feriado</span>
                    </label>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 1.25rem;">
                    <button type="button" class="view-btn" onclick="closeFastScheduleModal()">Cancelar</button>
                    <button type="submit" class="btn-submit" style="background: #4338CA;">Agendar Deslocamento ✈</button>
                </div>
            </form>

            <!-- Formulário 4: Remarcação em Bloco -->
            <form id="formRemarcarTurma" method="POST" action="/diario/calendario" style="display: none;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="acao" value="remarcar_turma">

                <div style="background: #FFFBEB; border: 1px solid #FCD34D; border-radius: 6px; padding: 0.75rem 1rem; margin-bottom: 1rem; font-size: 0.8125rem; color: #92400E;">
                    <strong>Motor Atômico de Remarcação:</strong> Transfere em bloco todas as aulas e deslocamentos para uma nova data mantendo o mesmo intervalo de dias. Validação antecipada de colisões garante que, caso qualquer data futura colida, a operação é revertida integralmente.
                </div>

                <div class="form-group">
                    <label class="form-label" for="rem_turma">Turma a Ser Remarcada:</label>
                    <select id="rem_turma" name="turma_id" class="form-control" required>
                        <option value="">Selecione a turma...</option>
                        <?php foreach ($turmasDisponiveis as $t): ?>
                            <option value="<?= $t['id'] ?>">
                                <?= htmlspecialchars($t['curso_nome'], ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars($t['cliente_nome'] ?: 'Sem cliente', ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="rem_nova_data">Nova Data de Início (1º Encontro):</label>
                    <input type="date" id="rem_nova_data" name="nova_data_inicio" class="form-control" required value="<?= $hoje ?>">
                </div>

                <div style="background: #F8FAFC; border: 1px dashed var(--border); border-radius: 6px; padding: 0.6rem 0.85rem; margin-top: 0.75rem;">
                    <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.8125rem; cursor: pointer; color: var(--text);">
                        <input type="checkbox" name="confirmar_excecao_feriado" value="1">
                        <span>Confirmar <strong>exceção consciente</strong> se as novas datas incluírem feriados</span>
                    </label>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 1.25rem;">
                    <button type="button" class="view-btn" onclick="closeFastScheduleModal()">Cancelar</button>
                    <button type="submit" class="btn-submit" style="background: #0284C7;">Remarcar em Bloco 🔄</button>
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
        if (!data) return;
        const hasEncontros = data.encontros && data.encontros.length > 0;
        const hasBloqueio = !!data.bloqueio;
        const hasPonte = !!data.ponte;
        if (!hasEncontros && !hasBloqueio && !hasPonte) return;

        let html = `
            <div class="popover-header">
                <span class="popover-date">${data.data_formatada} (${data.dia_semana})</span>
                <span style="font-size: 0.75rem; color: var(--text-muted); font-weight: 600;">${hasEncontros ? data.encontros.length + ' agendamento(s)' : 'Dia Livre'}</span>
            </div>
        `;

        if (hasBloqueio) {
            const isFeriado = data.bloqueio.tipo === 'feriado_nacional';
            html += `
                <div style="background: ${isFeriado ? '#FEF3C7' : '#F1F5F9'}; border: 1px solid ${isFeriado ? '#FDE68A' : '#CBD5E1'}; border-radius: 6px; padding: 0.5rem; margin-bottom: 0.5rem; font-size: 0.8rem; color: ${isFeriado ? '#92400E' : '#334155'};">
                    <strong>${isFeriado ? '🇧🇷 Feriado Nacional' : '🔒 Bloqueio'}:</strong> ${data.bloqueio.descricao}
                </div>
            `;
        }

        if (hasPonte) {
            html += `
                <div style="background: #F0FDF4; border: 1px dashed #86EFAC; border-radius: 6px; padding: 0.45rem 0.6rem; margin-bottom: 0.5rem; font-size: 0.75rem; color: #166534;">
                    <strong>🏖️ Sugestão de Ponte:</strong> ${data.ponte.sugestao}
                </div>
            `;
        }

        if (hasEncontros) {
            data.encontros.forEach(enc => {
                const isDesloc = (enc.tipo === 'deslocamento');
                html += `
                    <div class="popover-item" style="${isDesloc ? 'border-left: 3px solid #6366F1; background: #F8FAFC;' : ''}">
                        <div class="popover-item-title">${isDesloc ? '✈ [Deslocamento Logístico]' : '[' + enc.turno_sigla + ']'} ${enc.curso}</div>
                        <div class="popover-item-client">${enc.cliente}</div>
                        <div class="popover-item-footer">
                            <span style="font-weight: 600; color: ${isDesloc ? '#4338CA' : 'var(--primary)'};">${isDesloc ? 'Turno ' + enc.turno_sigla : enc.horario}</span>
                            <span style="text-transform: uppercase; font-size: 0.65rem; background: ${isDesloc ? '#EEF2FF; color: #4338CA' : '#E2E8F0'}; padding: 1px 5px; border-radius: 4px;">${isDesloc ? 'Logística' : enc.status_turma}</span>
                        </div>
                    </div>
                `;
            });
        }

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
                if (data && ((data.encontros && data.encontros.length > 0) || data.bloqueio || data.ponte)) {
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

        if (data.bloqueio) {
            const isFeriado = data.bloqueio.tipo === 'feriado_nacional';
            html += `
                <div style="background: ${isFeriado ? '#FEF3C7' : '#F1F5F9'}; border: 1px solid ${isFeriado ? '#FDE68A' : '#CBD5E1'}; border-radius: 8px; padding: 0.85rem 1rem; margin-bottom: 0.75rem;">
                    <div style="display: flex; align-items: center; gap: 0.5rem; font-weight: 700; color: ${isFeriado ? '#92400E' : '#334155'};">
                        <span>${isFeriado ? '🇧🇷' : '🔒'}</span>
                        <span>${isFeriado ? 'Feriado Nacional Oficial' : 'Bloqueio de Agenda'}</span>
                    </div>
                    <div style="font-size: 0.875rem; color: var(--dark); margin-top: 0.25rem; font-weight: 600;">${data.bloqueio.descricao}</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.2rem;">
                        ${data.bloqueio.permite_excecao ? 'Permite agendamento mediante confirmação consciente de exceção.' : 'Bloqueio impeditivo rigoroso.'}
                    </div>
                </div>
            `;
        }

        if (data.ponte) {
            html += `
                <div style="background: #F0FDF4; border: 1px dashed #86EFAC; border-radius: 8px; padding: 0.75rem 1rem; margin-bottom: 0.75rem; font-size: 0.8125rem; color: #166534;">
                    <strong>🏖️ Sugestão de Ponte Inteligente:</strong> ${data.ponte.sugestao}
                </div>
            `;
        }

        if (data.encontros && data.encontros.length > 0) {
            data.encontros.forEach(enc => {
                const isDesloc = (enc.tipo === 'deslocamento');
                html += `
                    <div style="background: var(--bg); border: 1px solid var(--border); border-radius: 8px; padding: 1rem; margin-bottom: 0.75rem; ${isDesloc ? 'border-left: 4px solid #6366F1;' : ''}">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.35rem;">
                            <h4 style="font-size: 0.9375rem; font-weight: 700; color: var(--dark);">
                                ${isDesloc ? '✈ [Deslocamento] ' : '[' + enc.turno_sigla + '] '} ${enc.curso}
                            </h4>
                            <span style="background: ${isDesloc ? '#EEF2FF; color: #4338CA' : '#E0F2FE; color: #0369A1'}; font-weight: 700; font-size: 0.7rem; padding: 2px 6px; border-radius: 4px;">
                                ${isDesloc ? 'Logística ✈' : enc.status_turma}
                            </span>
                        </div>
                        <p style="font-size: 0.8125rem; color: var(--text-muted); margin-bottom: 0.5rem;">
                            Cliente: <strong>${enc.cliente}</strong> | Horário/Turno: <strong>${isDesloc ? 'Turno ' + enc.turno_sigla : enc.horario}</strong>
                        </p>
                        ${enc.conteudo ? '<p style="font-size: 0.775rem; color: var(--text); margin-bottom: 0.5rem; font-style: italic;">' + enc.conteudo + '</p>' : ''}
                        
                        <div style="display: flex; gap: 0.5rem; margin-top: 0.75rem; flex-wrap: wrap;">
                            ${!isDesloc ? `
                                <a href="/diario/aula?encontro_id=${enc.encontro_id}" class="btn-submit" style="font-size: 0.78rem; text-decoration: none; padding: 0.4rem 0.8rem;">
                                    Abrir Diário & Chamada
                                </a>
                            ` : `
                                <span style="font-size: 0.78rem; color: #4338CA; font-weight: 600; background: #EEF2FF; padding: 0.4rem 0.8rem; border-radius: 6px;">
                                    ✈ Bloqueio Logístico (Sem chamada / Sem carga horária)
                                </span>
                            `}
                            <a href="/diario/turma?turma_id=${enc.turma_id}" class="view-btn" style="font-size: 0.78rem; text-decoration: none; padding: 0.4rem 0.8rem;">
                                Detalhes da Turma
                            </a>
                            <button type="button" class="view-btn" style="font-size: 0.78rem; padding: 0.4rem 0.8rem; cursor: pointer;" onclick="closeDayModal(); openRescheduleTurmaModal(${enc.turma_id})">
                                🔄 Remarcar Turma
                            </button>
                        </div>
                    </div>
                `;
            });
        }

        html += `</div>`;

        html += `
            <div style="border-top: 1px solid var(--border); padding-top: 1rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
                <span style="font-size: 0.8125rem; color: var(--text-muted);">Precisa agendar compromisso neste dia?</span>
                <button type="button" class="btn-submit" style="font-size: 0.8125rem;" onclick="closeDayModal(); openFastScheduleModal('${data.data}', 'V')">+ Novo Agendamento</button>
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
        if (document.getElementById('desl_data')) {
            document.getElementById('desl_data').value = dateStr;
        }
        if (document.getElementById('rem_nova_data')) {
            document.getElementById('rem_nova_data').value = dateStr;
        }
        if (defaultTurno) {
            document.getElementById('enc_turno').value = defaultTurno;
            document.getElementById('nova_turno').value = defaultTurno;
            if (document.getElementById('desl_turno')) {
                document.getElementById('desl_turno').value = defaultTurno;
            }
        }
        document.getElementById('fastScheduleModal').classList.add('active');
    }

    function openRescheduleTurmaModal(turmaId) {
        openFastScheduleModal('<?= $hoje ?>', 'V');
        switchScheduleTab('remarcar');
        if (turmaId && document.getElementById('rem_turma')) {
            document.getElementById('rem_turma').value = turmaId;
        }
    }

    function closeFastScheduleModal() {
        document.getElementById('fastScheduleModal').classList.remove('active');
    }

    function switchScheduleTab(tab) {
        const formIds = ['formAgendarEncontro', 'formAgendarNovaTurma', 'formAgendarDeslocamento', 'formRemarcarTurma'];
        formIds.forEach(id => {
            const el = document.getElementById(id);
            if (el) el.style.display = 'none';
        });

        const btnIds = ['tabEncontroBtn', 'tabNovaTurmaBtn', 'tabDeslocamentoBtn', 'tabRemarcarBtn'];
        btnIds.forEach(id => {
            const el = document.getElementById(id);
            if (el) el.classList.remove('active');
        });

        if (tab === 'encontro') {
            document.getElementById('formAgendarEncontro').style.display = 'block';
            document.getElementById('tabEncontroBtn').classList.add('active');
        } else if (tab === 'nova_turma') {
            document.getElementById('formAgendarNovaTurma').style.display = 'block';
            document.getElementById('tabNovaTurmaBtn').classList.add('active');
        } else if (tab === 'deslocamento') {
            document.getElementById('formAgendarDeslocamento').style.display = 'block';
            document.getElementById('tabDeslocamentoBtn').classList.add('active');
        } else if (tab === 'remarcar') {
            document.getElementById('formRemarcarTurma').style.display = 'block';
            document.getElementById('tabRemarcarBtn').classList.add('active');
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
