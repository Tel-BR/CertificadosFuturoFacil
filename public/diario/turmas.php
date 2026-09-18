<?php
/**
 * Gestão de Turmas, Diário Ativo e Lixeira com Soft Delete
 * Plataforma Integrada de Gestão Pedagógica, Diário e Certificados Futuro Fácil
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/Database.php';
require_once __DIR__ . '/../../src/Services/AuthService.php';
require_once __DIR__ . '/../../src/Services/CalendarService.php';
require_once __DIR__ . '/../../src/Services/AttendanceService.php';
require_once __DIR__ . '/../../src/Services/TurmaService.php';
require_once __DIR__ . '/../../src/Views/layout_admin.php';

use FuturoFacil\Config\Database;
use FuturoFacil\Services\AuthService;
use FuturoFacil\Services\CalendarService;
use FuturoFacil\Services\AttendanceService;
use FuturoFacil\Services\TurmaService;
use function FuturoFacil\Views\renderAdminLayout;

AuthService::requireAuth();

$pdo = Database::getConnection();
$attendanceService = new AttendanceService($pdo);
$calendarService = new CalendarService($pdo);
$turmaService = new TurmaService($pdo, $calendarService);
$turmaService->checkAndTransitionLifecycle();

// Redirecionamento amigável se encontro_id for fornecido
if (isset($_GET['encontro_id']) && (int)$_GET['encontro_id'] > 0) {
    header('Location: /diario/aula?encontro_id=' . (int)$_GET['encontro_id']);
    exit;
}

$feedbackMessage = null;
$feedbackType = 'success';
$conflictDetails = [];

// Processamento de Ações da Lixeira e Turmas
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $turmaId = (int)($_POST['turma_id'] ?? 0);
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (!AuthService::verifyCsrfToken($csrfToken)) {
        $feedbackMessage = 'Token de segurança CSRF inválido ou expirado.';
        $feedbackType = 'danger';
    } elseif ($turmaId <= 0) {
        $feedbackMessage = 'Identificador de turma inválido.';
        $feedbackType = 'danger';
    } else {
        try {
            if ($action === 'trash') {
                $turmaService->moveToTrash($turmaId);
                $feedbackMessage = 'Turma movida para a Lixeira com sucesso. Seus horários no calendário foram liberados.';
                $feedbackType = 'success';
            } elseif ($action === 'restore') {
                $res = $turmaService->restoreFromTrash($turmaId);
                if ($res['success']) {
                    $feedbackMessage = $res['message'];
                    $feedbackType = 'success';
                } else {
                    $feedbackMessage = $res['message'];
                    $feedbackType = 'danger';
                    $conflictDetails = $res['conflicts'] ?? [];
                }
            } elseif ($action === 'expunge') {
                $res = $turmaService->expungeTurma($turmaId);
                if ($res['success']) {
                    $feedbackMessage = $res['message'];
                    $feedbackType = 'success';
                } else {
                    $feedbackMessage = $res['message'];
                    $feedbackType = 'danger';
                }
            }
        } catch (Throwable $e) {
            $feedbackMessage = 'Erro ao processar ação: ' . $e->getMessage();
            $feedbackType = 'danger';
        }
    }
}

$filtro = $_GET['filtro'] ?? 'ativas';
if ($filtro !== 'lixeira') {
    $filtro = 'ativas';
}

$turmasAtivas = $turmaService->getActiveTurmas();
$turmasLixeira = $turmaService->getTrashedTurmas();
$trashCount = count($turmasLixeira);
$activeCount = count($turmasAtivas);

$csrfToken = AuthService::getCsrfToken();

ob_start();
?>
<style>
    .turmas-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 1.5rem;
        flex-wrap: wrap;
        gap: 1rem;
    }
    .turmas-title {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--dark);
        letter-spacing: -0.5px;
    }
    .turmas-tabs-bar {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-bottom: 1.5rem;
        border-bottom: 1px solid var(--border);
        padding-bottom: 0.75rem;
    }
    .tab-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.45rem 0.95rem;
        border-radius: 9999px;
        font-size: 0.8125rem;
        font-weight: 700;
        text-decoration: none;
        color: var(--text-muted);
        background: var(--surface);
        border: 1px solid var(--border);
        transition: all 0.15s ease;
    }
    .tab-pill:hover {
        border-color: var(--primary);
        color: var(--primary);
    }
    .tab-pill.active {
        background: var(--primary);
        color: #FFFFFF;
        border-color: var(--primary);
    }
    .tab-counter {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.7rem;
        padding: 2px 6px;
        border-radius: 9999px;
        background: rgba(0,0,0,0.08);
        color: inherit;
    }
    .tab-pill.active .tab-counter {
        background: rgba(255,255,255,0.25);
        color: #FFFFFF;
    }
    .turmas-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
        gap: 1.25rem;
        margin-bottom: 2rem;
    }
    .turma-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        padding: 1.5rem;
        box-shadow: var(--shadow-sm);
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        transition: transform 0.15s, box-shadow 0.15s;
    }
    .turma-card:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-md);
    }
    .turma-card.trashed {
        border-color: #FECACA;
        background: #FFFDFD;
    }
    .turma-status-badge {
        font-size: 0.6875rem;
        font-weight: 700;
        padding: 3px 8px;
        border-radius: 4px;
        text-transform: uppercase;
    }
    .status-andamento { background: #FEF3C7; color: #B45309; }
    .status-concluida { background: #ECFDF5; color: #065F46; }
    .status-prevista { background: #E0F2FE; color: #0369A1; }
    .status-lixeira { background: #FEE2E2; color: #991B1B; }
    
    .encontros-list {
        margin-top: 1rem;
        border-top: 1px solid var(--border);
        padding-top: 0.75rem;
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }
    .encontro-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0.5rem;
        background: var(--bg);
        border-radius: 6px;
        font-size: 0.8125rem;
    }
    .btn-aula-action {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        background: var(--primary);
        color: #FFFFFF;
        font-weight: 600;
        font-size: 0.75rem;
        padding: 0.35rem 0.65rem;
        border-radius: 6px;
        text-decoration: none;
        transition: background 0.15s;
    }
    .btn-aula-action:hover {
        background: var(--primary-hover);
    }
    .empty-state {
        background: var(--surface);
        border: 1px dashed var(--border);
        border-radius: var(--radius-lg);
        padding: 3rem 1.5rem;
        text-align: center;
        color: var(--text-muted);
        margin: 1.5rem 0;
    }
    .empty-state svg {
        margin-bottom: 0.75rem;
        opacity: 0.6;
    }
    .btn-action-outline {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.35rem;
        background: var(--bg);
        color: var(--dark);
        border: 1px solid var(--border);
        font-size: 0.75rem;
        font-weight: 700;
        padding: 0.45rem 0.65rem;
        border-radius: 6px;
        cursor: pointer;
        text-decoration: none;
        transition: all 0.15s;
    }
    .btn-action-outline:hover {
        background: var(--border);
    }
    .btn-action-danger {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.35rem;
        background: #FEF2F2;
        color: #DC2626;
        border: 1px solid #FECACA;
        font-size: 0.75rem;
        font-weight: 700;
        padding: 0.45rem 0.65rem;
        border-radius: 6px;
        cursor: pointer;
        transition: all 0.15s;
    }
    .btn-action-danger:hover {
        background: #FEE2E2;
        border-color: #FCA5A5;
    }
    .btn-action-restore {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.35rem;
        background: #ECFEFF;
        color: #0E7490;
        border: 1px solid #A5F3FC;
        font-size: 0.75rem;
        font-weight: 700;
        padding: 0.45rem 0.65rem;
        border-radius: 6px;
        cursor: pointer;
        transition: all 0.15s;
    }
    .btn-action-restore:hover {
        background: #CFFAFE;
        border-color: #67E8F9;
    }
</style>

<div class="turmas-header">
    <div>
        <h1 class="turmas-title">Diário & Turmas</h1>
        <p style="color: var(--text-muted); font-size: 0.9375rem;">
            Acompanhamento em tempo real, diário de classe mobile e controle de presenças.
        </p>
    </div>
    <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
        <a href="/diario/turmas/novo" class="btn-new-schedule" style="background: var(--primary); color: #FFFFFF; border: none; text-decoration: none;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <span>+ Nova Turma</span>
        </a>
        <a href="/diario/calendario" class="btn-new-schedule" style="background: var(--surface); color: var(--primary); border: 1px solid var(--border); box-shadow: none; text-decoration: none;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <span>Ver Calendário</span>
        </a>
    </div>
</div>

<?php if ($feedbackMessage): ?>
    <div style="background: <?= $feedbackType === 'success' ? '#ECFDF5' : '#FEF2F2' ?>; border: 1px solid <?= $feedbackType === 'success' ? '#A7F3D0' : '#FECACA' ?>; color: <?= $feedbackType === 'success' ? '#065F46' : '#991B1B' ?>; border-radius: 8px; padding: 1rem 1.25rem; margin-bottom: 1.5rem;">
        <div style="display: flex; align-items: flex-start; gap: 0.75rem;">
            <?php if ($feedbackType === 'success'): ?>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0; margin-top: 1px;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <?php else: ?>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0; margin-top: 1px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <?php endif; ?>
            <div>
                <strong><?= htmlspecialchars($feedbackMessage, ENT_QUOTES, 'UTF-8') ?></strong>
                <?php if (!empty($conflictDetails)): ?>
                    <ul style="margin: 0.5rem 0 0 1rem; padding: 0; font-size: 0.8125rem;">
                        <?php foreach ($conflictDetails as $c): ?>
                            <li>
                                <strong>Encontro #<?= (int)$c['numero_encontro'] ?></strong> (<?= htmlspecialchars($c['data_formatada'], ENT_QUOTES, 'UTF-8') ?> - Turno <?= htmlspecialchars($c['turno'], ENT_QUOTES, 'UTF-8') ?>): <?= htmlspecialchars($c['mensagem'], ENT_QUOTES, 'UTF-8') ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Barra de Abas (Ativas vs Lixeira) -->
<div class="turmas-tabs-bar">
    <a href="/diario/turmas?filtro=ativas" class="tab-pill <?= $filtro === 'ativas' ? 'active' : '' ?>">
        <span>Turmas Ativas</span>
        <span class="tab-counter"><?= $activeCount ?></span>
    </a>
    <a href="/diario/turmas?filtro=lixeira" class="tab-pill <?= $filtro === 'lixeira' ? 'active' : '' ?>">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
        <span>Lixeira</span>
        <span class="tab-counter"><?= $trashCount ?></span>
    </a>
</div>

<?php if ($filtro === 'lixeira'): ?>
    <!-- LISTAGEM DE TURMAS NA LIXEIRA -->
    <?php if (empty($turmasLixeira)): ?>
        <div class="empty-state">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
            <h3 style="font-size: 1.125rem; font-weight: 700; margin-bottom: 0.25rem;">A Lixeira está vazia</h3>
            <p style="font-size: 0.875rem; margin: 0;">Nenhuma turma foi descartada no momento.</p>
        </div>
    <?php else: ?>
        <div class="turmas-grid">
            <?php foreach ($turmasLixeira as $t): 
                $tId = (int)$t['id'];
            ?>
                <div class="turma-card trashed">
                    <div>
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.5rem;">
                            <span class="turma-status-badge status-lixeira">Na Lixeira</span>
                            <span style="font-size: 0.75rem; color: var(--text-muted); font-weight: 600;"><?= (int)$t['total_alunos'] ?> Alunos</span>
                        </div>
                        
                        <h3 style="font-size: 1.125rem; font-weight: 700; color: var(--dark); margin-bottom: 0.25rem;">
                            <?= htmlspecialchars($t['curso_nome'], ENT_QUOTES, 'UTF-8') ?>
                        </h3>
                        <p style="font-size: 0.875rem; color: var(--text-muted); margin-bottom: 0.5rem;">
                            Cliente: <strong><?= htmlspecialchars($t['cliente_nome'] ?? 'Institucional', ENT_QUOTES, 'UTF-8') ?></strong>
                        </p>
                        <p style="font-size: 0.8125rem; color: var(--text-muted); margin-bottom: 0.5rem;">
                            Carga Horária: <strong><?= (int)$t['carga_horaria'] ?>h</strong> | Período: <?= date('d/m', strtotime($t['data_inicio'])) ?> a <?= date('d/m/Y', strtotime($t['data_conclusao'])) ?>
                        </p>

                        <div style="background: #FEE2E2; border: 1px solid #FECACA; border-radius: 6px; padding: 0.5rem 0.75rem; margin-top: 0.75rem; font-size: 0.75rem; color: #991B1B;">
                            <strong>Descartada em:</strong> <?= htmlspecialchars($t['deleted_at_formatado'], ENT_QUOTES, 'UTF-8') ?>
                            <div style="margin-top: 2px; color: #7F1D1D;">Horários e turnos desocupados no calendário.</div>
                        </div>

                        <!-- Ações da Turma na Lixeira -->
                        <div style="display: flex; gap: 0.5rem; margin-top: 1rem; padding-top: 0.75rem; border-top: 1px solid var(--border); flex-wrap: wrap;">
                            <form method="POST" action="/diario/turmas?filtro=lixeira" style="flex: 1; margin: 0;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="action" value="restore">
                                <input type="hidden" name="turma_id" value="<?= $tId ?>">
                                <button type="submit" class="btn-action-restore" style="width: 100%;" title="Restaura a turma para a grade se os horários estiverem livres">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                    <span>Restaurar</span>
                                </button>
                            </form>

                            <form method="POST" action="/diario/turmas?filtro=lixeira" style="flex: 1; margin: 0;" onsubmit="return confirm('ATENÇÃO: A exclusão física definitiva é irreversível e removerá permanentemente todos os encontros, presenças e materiais desta turma. Se houver certificados oficiais emitidos, a exclusão será bloqueada. Confirmar?');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="action" value="expunge">
                                <input type="hidden" name="turma_id" value="<?= $tId ?>">
                                <button type="submit" class="btn-action-danger" style="width: 100%;" title="Expurga definitivamente do banco de dados se não houver certificados emitidos">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                                    <span>Excluir Definitivamente</span>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php else: ?>
    <!-- LISTAGEM DE TURMAS ATIVAS -->
    <?php if (empty($turmasAtivas)): ?>
        <div class="empty-state">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <h3 style="font-size: 1.125rem; font-weight: 700; margin-bottom: 0.25rem;">Nenhuma turma ativa</h3>
            <p style="font-size: 0.875rem; margin: 0;">Agende uma turma no calendário para começar.</p>
        </div>
    <?php else: ?>
        <div class="turmas-grid">
            <?php foreach ($turmasAtivas as $t): 
                $tId = (int)$t['id'];
                $statusClass = 'status-' . ($t['status'] === 'em_andamento' ? 'andamento' : ($t['status'] === 'concluida' ? 'concluida' : 'prevista'));
                $statusLabel = ($t['status'] === 'em_andamento' ? 'Em Andamento' : ($t['status'] === 'concluida' ? 'Concluída' : 'Prevista'));
                
                // Encontros pedagógicos da turma
                $stmtEnc = $pdo->prepare("
                    SELECT e.*,
                           (SELECT COUNT(*) FROM frequencias f WHERE f.encontro_id = e.id) as total_freq
                    FROM encontros e
                    WHERE e.turma_id = ? AND e.tipo = 'aula' AND e.deleted_at IS NULL
                    ORDER BY e.numero_encontro ASC
                ");
                $stmtEnc->execute([$tId]);
                $encontros = $stmtEnc->fetchAll();
            ?>
                <div class="turma-card">
                    <div>
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.5rem;">
                            <span class="turma-status-badge <?= $statusClass ?>"><?= $statusLabel ?></span>
                            <span style="font-size: 0.75rem; color: var(--text-muted); font-weight: 600;"><?= (int)$t['total_alunos'] ?> Alunos</span>
                        </div>
                        
                        <h3 style="font-size: 1.125rem; font-weight: 700; color: var(--dark); margin-bottom: 0.25rem;">
                            <?= htmlspecialchars($t['curso_nome'], ENT_QUOTES, 'UTF-8') ?>
                        </h3>
                        <p style="font-size: 0.875rem; color: var(--text-muted); margin-bottom: 0.75rem;">
                            Cliente: <strong><?= htmlspecialchars($t['cliente_nome'] ?? 'Institucional', ENT_QUOTES, 'UTF-8') ?></strong>
                        </p>
                        <p style="font-size: 0.8125rem; color: var(--text-muted); margin-bottom: 0.5rem;">
                            Carga Horária: <strong><?= (int)$t['carga_horaria'] ?>h</strong> | Período: <?= date('d/m', strtotime($t['data_inicio'])) ?> a <?= date('d/m/Y', strtotime($t['data_conclusao'])) ?>
                        </p>

                        <!-- Encontros / Modo Aula -->
                        <div class="encontros-list">
                            <span style="font-size: 0.6875rem; text-transform: uppercase; font-weight: 700; color: var(--text-muted);">
                                Encontros Pedagógicos (<?= count($encontros) ?>):
                            </span>
                            <?php foreach ($encontros as $enc): 
                                $encId = (int)$enc['id'];
                                $temChamada = ((int)$enc['total_freq'] > 0);
                                $isAbonado = ((int)$enc['abonado'] === 1);
                            ?>
                                <div class="encontro-row">
                                    <div>
                                        <strong>#<?= (int)$enc['numero_encontro'] ?></strong> — <?= date('d/m', strtotime($enc['data_encontro'])) ?>
                                        <?php if ($isAbonado): ?>
                                            <span style="background: #FEF3C7; color: #B45309; font-size: 0.65rem; padding: 1px 4px; border-radius: 3px; font-weight: 700;">Abonado</span>
                                        <?php elseif ($temChamada): ?>
                                            <span style="color: var(--success); font-weight: 700; font-size: 0.65rem;">✓ Realizada</span>
                                        <?php else: ?>
                                            <span style="color: var(--text-muted); font-size: 0.65rem;">Pendente</span>
                                        <?php endif; ?>
                                    </div>
                                    <a href="/diario/aula?encontro_id=<?= $encId ?>" class="btn-aula-action" title="Abrir Modo Aula neste encontro">
                                        <span>Modo Aula</span>
                                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Ações da Turma -->
                        <div style="display: flex; gap: 0.5rem; margin-top: 1rem; padding-top: 0.75rem; border-top: 1px solid var(--border); flex-wrap: wrap;">
                            <a href="/diario/turma?turma_id=<?= $tId ?>" class="btn-action-outline" style="flex: 1; min-width: 90px;">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                                <span>Gerenciar</span>
                            </a>
                            <a href="/diario/fechamento?turma_id=<?= $tId ?>" style="display: inline-flex; align-items: center; justify-content: center; gap: 0.35rem; background: var(--primary); color: #FFFFFF; font-size: 0.75rem; font-weight: 700; padding: 0.45rem 0.65rem; border-radius: 6px; text-decoration: none;" title="Fechamento Assistido e Certificados">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
                                <span>Fechamento</span>
                            </a>
                            <a href="/diario/turma?turma_id=<?= $tId ?>&action=export" style="display: inline-flex; align-items: center; justify-content: center; gap: 0.35rem; background: #DCFCE7; color: #166534; border: 1px solid #86EFAC; font-size: 0.75rem; font-weight: 700; padding: 0.45rem 0.65rem; border-radius: 6px; text-decoration: none;" title="Exportar Excel (.xlsx)">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                <span>Exportar Excel</span>
                            </a>
                            <form method="POST" action="/diario/turmas" style="margin: 0;" onsubmit="return confirm('Mover esta turma para a Lixeira? Os horários e turnos no calendário serão imediatamente desocupados.');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="action" value="trash">
                                <input type="hidden" name="turma_id" value="<?= $tId ?>">
                                <button type="submit" class="btn-action-danger" title="Mover para a Lixeira">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php
$contentHtml = ob_get_clean();

renderAdminLayout(
    title: 'Diário & Turmas',
    activeNav: 'turmas',
    contentHtml: $contentHtml,
    breadcrumbs: [
        'Início' => '/diario',
        'Diário & Turmas' => null,
    ]
);
