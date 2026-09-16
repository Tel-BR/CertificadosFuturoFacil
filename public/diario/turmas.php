<?php
/**
 * Gestão de Turmas e Diário Ativo
 * Plataforma Integrada de Gestão Pedagógica, Diário e Certificados Futuro Fácil
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/Database.php';
require_once __DIR__ . '/../../src/Services/AuthService.php';
require_once __DIR__ . '/../../src/Services/CalendarService.php';
require_once __DIR__ . '/../../src/Services/AttendanceService.php';
require_once __DIR__ . '/../../src/Views/layout_admin.php';

use FuturoFacil\Config\Database;
use FuturoFacil\Services\AuthService;
use FuturoFacil\Services\CalendarService;
use FuturoFacil\Services\AttendanceService;
use function FuturoFacil\Views\renderAdminLayout;

AuthService::requireAuth();

$pdo = Database::getConnection();
$attendanceService = new AttendanceService($pdo);

// Redirecionamento amigável se encontro_id for fornecido
if (isset($_GET['encontro_id']) && (int)$_GET['encontro_id'] > 0) {
    header('Location: /diario/aula?encontro_id=' . (int)$_GET['encontro_id']);
    exit;
}

$turmaId = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;

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
</style>

<div class="turmas-header">
    <div>
        <h1 class="turmas-title">Diário & Turmas</h1>
        <p style="color: var(--text-muted); font-size: 0.9375rem;">
            Acompanhamento em tempo real, diário de classe mobile e controle de presenças.
        </p>
    </div>
    <a href="/diario/calendario" class="btn-new-schedule" style="background: var(--surface); color: var(--primary); border: 1px solid var(--border); box-shadow: none;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        <span>Ver Calendário</span>
    </a>
</div>

<?php
// Consulta as turmas cadastradas
$stmtTurmas = $pdo->query("
    SELECT t.*,
           (SELECT COUNT(*) FROM alunos a WHERE a.turma_id = t.id) as total_alunos,
           (SELECT COUNT(*) FROM encontros e WHERE e.turma_id = t.id AND e.tipo = 'aula') as total_aulas
    FROM turmas t
    ORDER BY 
        CASE t.status 
            WHEN 'em_andamento' THEN 1 
            WHEN 'prevista' THEN 2 
            ELSE 3 
        END,
        t.data_inicio DESC
");
$turmas = $stmtTurmas->fetchAll();
?>

<div class="turmas-grid">
    <?php foreach ($turmas as $t): 
        $tId = (int)$t['id'];
        $statusClass = 'status-' . ($t['status'] === 'em_andamento' ? 'andamento' : ($t['status'] === 'concluida' ? 'concluida' : 'prevista'));
        $statusLabel = ($t['status'] === 'em_andamento' ? 'Em Andamento' : ($t['status'] === 'concluida' ? 'Concluída' : 'Prevista'));
        
        // Encontros pedagógicos da turma
        $stmtEnc = $pdo->prepare("
            SELECT e.*,
                   (SELECT COUNT(*) FROM frequencias f WHERE f.encontro_id = e.id) as total_freq
            FROM encontros e
            WHERE e.turma_id = ? AND e.tipo = 'aula'
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
            </div>
        </div>
    <?php endforeach; ?>
</div>

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
