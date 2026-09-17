<?php
/**
 * Visualização e Sincronização de Turma Individual
 * Plataforma Integrada de Gestão Pedagógica, Diário e Certificados Futuro Fácil
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/Database.php';
require_once __DIR__ . '/../../src/Services/AuthService.php';
require_once __DIR__ . '/../../src/Services/CalendarService.php';
require_once __DIR__ . '/../../src/Services/AttendanceService.php';
require_once __DIR__ . '/../../src/Services/ExcelSyncService.php';
require_once __DIR__ . '/../../src/Views/layout_admin.php';

use FuturoFacil\Config\Database;
use FuturoFacil\Services\AuthService;
use FuturoFacil\Services\CalendarService;
use FuturoFacil\Services\AttendanceService;
use FuturoFacil\Services\ExcelSyncService;
use function FuturoFacil\Views\renderAdminLayout;

AuthService::requireAuth();

$pdo = Database::getConnection();
$attendanceService = new AttendanceService($pdo);
$excelSyncService = new ExcelSyncService($pdo);

$turmaId = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);

if ($turmaId <= 0) {
    // Se nenhum ID for passado, busca a primeira turma ativa ou redireciona para turmas
    $stmtFirst = $pdo->query("SELECT id FROM turmas ORDER BY id DESC LIMIT 1");
    $turmaId = (int)$stmtFirst->fetchColumn();
    if ($turmaId <= 0) {
        header('Location: /diario/turmas');
        exit;
    }
}

// Consulta dados da turma
$stmtTurma = $pdo->prepare("SELECT * FROM turmas WHERE id = ?");
$stmtTurma->execute([$turmaId]);
$turma = $stmtTurma->fetch(PDO::FETCH_ASSOC);

if (!$turma) {
    header('Location: /diario/turmas');
    exit;
}

// -------------------------------------------------------------
// AÇÃO: Exportação direta do arquivo .xlsx
// -------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'export') {
    try {
        $xlsxBytes = $excelSyncService->exportTurmaSpreadsheet($turmaId);
        $codigoSanitizado = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string)$turma['codigo_turma']);
        $filename = "turma_{$codigoSanitizado}_planilha.xlsx";

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($xlsxBytes));
        header('Cache-Control: max-age=0');
        header('Pragma: public');

        echo $xlsxBytes;
        exit;
    } catch (Throwable $e) {
        $errorMessage = "Erro ao exportar planilha: " . $e->getMessage();
    }
}

// -------------------------------------------------------------
// AÇÃO: Importação e Sincronização via Upload de Planilha (.xlsx)
// -------------------------------------------------------------
$feedbackMessage = null;
$feedbackType = 'success';
$inconformidadesList = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!AuthService::verifyCsrfToken($csrfToken)) {
        $feedbackMessage = 'Token de segurança inválido ou expirado. Por favor, tente novamente.';
        $feedbackType = 'danger';
    } elseif (!isset($_FILES['planilha']) || $_FILES['planilha']['error'] !== UPLOAD_ERR_OK) {
        $feedbackMessage = 'Nenhum arquivo válido foi selecionado para upload.';
        $feedbackType = 'danger';
    } else {
        $uploadedFile = $_FILES['planilha']['tmp_name'];
        $origName = $_FILES['planilha']['name'];

        if (!str_ends_with(strtolower($origName), '.xlsx')) {
            $feedbackMessage = 'Formato de arquivo inválido. Apenas planilhas Excel no formato .xlsx são suportadas.';
            $feedbackType = 'danger';
        } else {
            try {
                $importResult = $excelSyncService->importTurmaSpreadsheet($turmaId, $uploadedFile);

                $feedbackMessage = sprintf(
                    'Sincronização concluída com sucesso! %d aluno(s) atualizados, %d novos inseridos, %d presenças sincronizadas e %d plano(s) de aula atualizados.',
                    $importResult['alunos_atualizados'],
                    $importResult['alunos_inseridos'],
                    $importResult['presencas_atualizadas'],
                    $importResult['encontros_atualizados']
                );
                $feedbackType = 'success';

                if (!empty($importResult['inconformidades'])) {
                    $inconformidadesList = $importResult['inconformidades'];
                }

                // Recarrega dados atualizados da turma
                $stmtTurma->execute([$turmaId]);
                $turma = $stmtTurma->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $feedbackMessage = 'Falha na importação da planilha: ' . $e->getMessage();
                $feedbackType = 'danger';
            }
        }
    }
}

// Consulta encontros da turma
$stmtEnc = $pdo->prepare("
    SELECT e.*,
           (SELECT COUNT(*) FROM frequencias f WHERE f.encontro_id = e.id) as total_freq
    FROM encontros e 
    WHERE e.turma_id = ? 
    ORDER BY e.numero_encontro ASC
");
$stmtEnc->execute([$turmaId]);
$encontros = $stmtEnc->fetchAll(PDO::FETCH_ASSOC);

// Consulta alunos da turma e frequências
$stmtAlunos = $pdo->prepare("
    SELECT a.* 
    FROM alunos a 
    WHERE a.turma_id = ? 
    ORDER BY a.nome_completo ASC
");
$stmtAlunos->execute([$turmaId]);
$alunos = $stmtAlunos->fetchAll(PDO::FETCH_ASSOC);

$cumulativeFrequencies = $attendanceService->calculateCumulativeFrequencies($turmaId);

$statusClass = 'status-' . ($turma['status'] === 'em_andamento' ? 'andamento' : ($turma['status'] === 'concluida' ? 'concluida' : 'prevista'));
$statusLabel = ($turma['status'] === 'em_andamento' ? 'Em Andamento' : ($turma['status'] === 'concluida' ? 'Concluída' : 'Prevista'));

ob_start();
?>
<style>
    .turma-detail-header {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        padding: 1.5rem 1.75rem;
        margin-bottom: 1.5rem;
        box-shadow: var(--shadow-sm);
    }
    .turma-badge-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.75rem;
        flex-wrap: wrap;
        gap: 0.5rem;
    }
    .turma-title-lg {
        font-size: 1.625rem;
        font-weight: 800;
        color: var(--dark);
        letter-spacing: -0.5px;
        margin-bottom: 0.5rem;
    }
    .turma-meta-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        margin-top: 1rem;
        padding-top: 1rem;
        border-top: 1px solid var(--border);
        font-size: 0.875rem;
    }
    .meta-item label {
        display: block;
        font-size: 0.6875rem;
        font-weight: 700;
        text-transform: uppercase;
        color: var(--text-muted);
        margin-bottom: 0.2rem;
    }
    .meta-item span {
        font-weight: 600;
        color: var(--dark);
    }
    .sync-actions-card {
        background: linear-gradient(135deg, #F0FDF4 0%, #DCFCE7 100%);
        border: 1px solid #BBF7D0;
        border-radius: var(--radius-lg);
        padding: 1.5rem 1.75rem;
        margin-bottom: 1.75rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 1.25rem;
    }
    .sync-text h3 {
        font-size: 1.125rem;
        font-weight: 700;
        color: #166534;
        margin-bottom: 0.25rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .sync-text p {
        font-size: 0.875rem;
        color: #15803D;
        margin: 0;
        max-width: 580px;
    }
    .sync-buttons {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        flex-wrap: wrap;
    }
    .btn-export-excel {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        background: #15803D;
        color: #FFFFFF;
        font-weight: 700;
        font-size: 0.875rem;
        padding: 0.65rem 1.15rem;
        border-radius: 8px;
        text-decoration: none;
        box-shadow: 0 2px 4px rgba(21, 128, 61, 0.2);
        transition: background 0.15s, transform 0.15s;
    }
    .btn-export-excel:hover {
        background: #166534;
        transform: translateY(-1px);
    }
    .btn-import-trigger {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        background: #FFFFFF;
        color: #166534;
        border: 1px solid #86EFAC;
        font-weight: 700;
        font-size: 0.875rem;
        padding: 0.65rem 1.15rem;
        border-radius: 8px;
        cursor: pointer;
        transition: background 0.15s;
    }
    .btn-import-trigger:hover {
        background: #F0FDF4;
    }
    .upload-box {
        display: none;
        background: var(--surface);
        border: 2px dashed #86EFAC;
        border-radius: var(--radius-md);
        padding: 1.25rem;
        margin-top: 1rem;
        width: 100%;
    }
    .upload-box.active {
        display: block;
    }
    .table-container {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        overflow: hidden;
        margin-bottom: 1.75rem;
        box-shadow: var(--shadow-sm);
    }
    .table-header-bar {
        padding: 1rem 1.5rem;
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .table-header-title {
        font-size: 1.0625rem;
        font-weight: 700;
        color: var(--dark);
    }
    .data-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.875rem;
        text-align: left;
    }
    .data-table th {
        background: var(--bg);
        color: var(--text-muted);
        font-weight: 700;
        font-size: 0.75rem;
        text-transform: uppercase;
        padding: 0.75rem 1rem;
        border-bottom: 1px solid var(--border);
    }
    .data-table td {
        padding: 0.75rem 1rem;
        border-bottom: 1px solid var(--border);
        color: var(--dark);
    }
    .data-table tr:last-child td {
        border-bottom: none;
    }
    .badge-risk {
        background: #FEE2E2;
        color: #991B1B;
        font-size: 0.6875rem;
        font-weight: 700;
        padding: 2px 6px;
        border-radius: 4px;
    }
    .badge-safe {
        background: #DCFCE7;
        color: #166534;
        font-size: 0.6875rem;
        font-weight: 700;
        padding: 2px 6px;
        border-radius: 4px;
    }
    .report-card {
        background: #FFFBEB;
        border: 1px solid #FCD34D;
        border-radius: var(--radius-md);
        padding: 1.25rem;
        margin-bottom: 1.5rem;
    }
    .report-card h4 {
        color: #92400E;
        font-size: 0.9375rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
</style>

<!-- Mensagem de Feedback -->
<?php if ($feedbackMessage): ?>
    <div style="padding: 1rem 1.25rem; border-radius: 8px; margin-bottom: 1.25rem; font-weight: 600; font-size: 0.9375rem; background: <?= $feedbackType === 'success' ? '#DCFCE7' : '#FEE2E2' ?>; color: <?= $feedbackType === 'success' ? '#166534' : '#991B1B' ?>; border: 1px solid <?= $feedbackType === 'success' ? '#86EFAC' : '#FCA5A5' ?>;">
        <?= htmlspecialchars($feedbackMessage, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

<!-- Relatório de Inconformidades Cadastrais (se houver) -->
<?php if (!empty($inconformidadesList)): ?>
    <div class="report-card">
        <h4>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            Relatório de Inconformidades Cadastrais na Planilha (<?= count($inconformidadesList) ?>)
        </h4>
        <p style="font-size: 0.8125rem; color: #B45309; margin-bottom: 0.75rem;">
            Os registros cadastrais válidos foram salvos no sistema. As seguintes linhas continham inconsistências que requerem correção na planilha:
        </p>
        <table style="width: 100%; border-collapse: collapse; font-size: 0.8125rem; background: #FFFFFF; border-radius: 6px; overflow: hidden; border: 1px solid #FDE68A;">
            <thead>
                <tr style="background: #FEF3C7; text-align: left;">
                    <th style="padding: 6px 10px; border-bottom: 1px solid #FDE68A;">Linha Excel</th>
                    <th style="padding: 6px 10px; border-bottom: 1px solid #FDE68A;">Nome na Planilha</th>
                    <th style="padding: 6px 10px; border-bottom: 1px solid #FDE68A;">CPF Digitado</th>
                    <th style="padding: 6px 10px; border-bottom: 1px solid #FDE68A;">Motivo da Rejeição</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($inconformidadesList as $inc): ?>
                    <tr>
                        <td style="padding: 6px 10px; border-bottom: 1px solid #FDE68A; font-weight: 700;">Linha <?= (int)$inc['linha'] ?></td>
                        <td style="padding: 6px 10px; border-bottom: 1px solid #FDE68A;"><?= htmlspecialchars((string)$inc['nome'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td style="padding: 6px 10px; border-bottom: 1px solid #FDE68A; font-family: monospace;"><?= htmlspecialchars((string)$inc['cpf'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td style="padding: 6px 10px; border-bottom: 1px solid #FDE68A; color: #DC2626;"><?= htmlspecialchars((string)$inc['motivo'], ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<!-- Cabeçalho da Turma -->
<div class="turma-detail-header">
    <div class="turma-badge-row">
        <span class="turma-status-badge <?= $statusClass ?>"><?= $statusLabel ?></span>
        <span style="font-size: 0.8125rem; color: var(--text-muted); font-weight: 600;">
            Código: <strong><?= htmlspecialchars($turma['codigo_turma'], ENT_QUOTES, 'UTF-8') ?></strong>
        </span>
    </div>
    <h1 class="turma-title-lg"><?= htmlspecialchars($turma['curso_nome'], ENT_QUOTES, 'UTF-8') ?></h1>
    <p style="color: var(--text-muted); font-size: 0.9375rem; margin: 0;">
        Cliente: <strong><?= htmlspecialchars($turma['cliente_nome'] ?? 'Institucional', ENT_QUOTES, 'UTF-8') ?></strong>
        <?php if (!empty($turma['ordem_servico'])): ?>
            | OS: <strong><?= htmlspecialchars($turma['ordem_servico'], ENT_QUOTES, 'UTF-8') ?></strong>
        <?php endif; ?>
    </p>

    <div class="turma-meta-grid">
        <div class="meta-item">
            <label>Carga Horária</label>
            <span><?= (int)$turma['carga_horaria'] ?> horas-aula</span>
        </div>
        <div class="meta-item">
            <label>Período de Realização</label>
            <span><?= date('d/m/Y', strtotime($turma['data_inicio'])) ?> a <?= date('d/m/Y', strtotime($turma['data_conclusao'])) ?></span>
        </div>
        <div class="meta-item">
            <label>Instrutor Responsável</label>
            <span><?= htmlspecialchars($turma['instrutor'] ?? 'Telmo Tropia', ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="meta-item">
            <label>Local / Cidade</label>
            <span><?= htmlspecialchars($turma['cidade'] ?? 'Goiânia - GO', ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    </div>
</div>

<!-- Card de Sincronização e Download/Upload Excel -->
<div class="sync-actions-card">
    <div class="sync-text">
        <h3>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
            Sincronização Bidirecional com Excel (.xlsx)
        </h3>
        <p>
            Baixe a planilha com as 3 abas estruturadas (<em>Alunos e Chamada</em>, <em>Diário e Planos</em>, <em>Dados da Turma</em>), edite offline no Excel e reimporte com garantia total de idempotência e blindagem de dados.
        </p>
    </div>
    <div class="sync-buttons">
        <a href="/diario/fechamento?turma_id=<?= $turmaId ?>" class="btn-fechamento" style="display: inline-flex; align-items: center; gap: 0.5rem; background: var(--primary); color: #FFFFFF; font-weight: 700; font-size: 0.875rem; padding: 0.65rem 1.15rem; border-radius: 8px; text-decoration: none; box-shadow: 0 2px 4px rgba(14, 116, 144, 0.2);" title="Abrir Fechamento Assistido e Emissão de Certificados">
            <span>🎓 Fechamento & Certificados</span>
        </a>
        <a href="/diario/turma?turma_id=<?= $turmaId ?>&action=export" class="btn-export-excel" title="Baixar planilha Excel desta turma">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            <span>Exportar Planilha Excel</span>
        </a>
        <button type="button" class="btn-import-trigger" onclick="document.getElementById('uploadBox').classList.toggle('active');">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            <span>Importar Planilha</span>
        </button>
    </div>

    <!-- Caixa retrátil de upload -->
    <div id="uploadBox" class="upload-box">
        <form method="POST" action="/diario/turma?turma_id=<?= $turmaId ?>" enctype="multipart/form-data" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthService::getCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="import">
            <div>
                <label style="display: block; font-size: 0.8125rem; font-weight: 700; color: var(--dark); margin-bottom: 0.25rem;">
                    Selecione o arquivo Excel editado (.xlsx):
                </label>
                <input type="file" name="planilha" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required style="font-size: 0.875rem;">
            </div>
            <button type="submit" class="btn-export-excel" style="background: var(--primary);">
                <span>Enviar e Sincronizar</span>
            </button>
        </form>
    </div>
</div>

<!-- Tabela de Encontros Pedagógicos -->
<div class="table-container">
    <div class="table-header-bar">
        <div class="table-header-title">Encontros Pedagógicos (<?= count($encontros) ?>)</div>
    </div>
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 60px;">#</th>
                <th>Data</th>
                <th>Turno / Horário</th>
                <th>Conteúdo Previsto</th>
                <th>Conteúdo Ministrado</th>
                <th>Status</th>
                <th style="text-align: right;">Ação</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($encontros as $enc): 
                $encId = (int)$enc['id'];
                $temChamada = ((int)$enc['total_freq'] > 0);
                $isAbonado = ((int)$enc['abonado'] === 1);
            ?>
                <tr>
                    <td style="font-weight: 700;">#<?= (int)$enc['numero_encontro'] ?></td>
                    <td><?= date('d/m/Y', strtotime($enc['data_encontro'])) ?></td>
                    <td>
                        <strong>[<?= htmlspecialchars($enc['turno'], ENT_QUOTES, 'UTF-8') ?>]</strong>
                        <?= substr((string)$enc['horario_inicio'], 0, 5) ?> - <?= substr((string)$enc['horario_fim'], 0, 5) ?>
                    </td>
                    <td><?= htmlspecialchars($enc['conteudo_previsto'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($enc['conteudo_ministrado'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <?php if ($isAbonado): ?>
                            <span class="turma-status-badge status-prevista">Abonado</span>
                        <?php elseif ($temChamada): ?>
                            <span class="badge-safe">✓ Realizada</span>
                        <?php else: ?>
                            <span style="color: var(--text-muted); font-size: 0.75rem;">Pendente</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: right;">
                        <a href="/diario/aula?encontro_id=<?= $encId ?>" class="btn-aula-action" style="padding: 4px 8px; font-size: 0.75rem;">
                            <span>Modo Aula</span>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Tabela de Alunos Matriculados -->
<div class="table-container">
    <div class="table-header-bar">
        <div class="table-header-title">Alunos Matriculados (<?= count($alunos) ?>)</div>
    </div>
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 50px;">Nº</th>
                <th>Nome Completo</th>
                <th>CPF (LGPD)</th>
                <th style="text-align: center;">Presenças / Total</th>
                <th style="text-align: center;">Frequência Acumulada</th>
                <th style="text-align: center;">Condição</th>
            </tr>
        </thead>
        <tbody>
            <?php $aIndex = 1; foreach ($alunos as $a): 
                $aId = (int)$a['id'];
                $stats = $cumulativeFrequencies[$aId] ?? [
                    'frequencia_acumulada' => 100.0,
                    'total_presencas' => 0,
                    'total_aulas_realizadas' => 0,
                    'is_risk' => false,
                ];
                $isRisk = $stats['is_risk'];
            ?>
                <tr>
                    <td style="color: var(--text-muted); font-weight: 600;"><?= $aIndex++ ?></td>
                    <td style="font-weight: 700;"><?= htmlspecialchars($a['nome_completo'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td style="font-family: monospace; font-size: 0.8125rem;"><?= htmlspecialchars($a['cpf_mascarado'] ?: '—', ENT_QUOTES, 'UTF-8') ?></td>
                    <td style="text-align: center;"><?= $stats['total_presencas'] ?> / <?= $stats['total_aulas_realizadas'] ?></td>
                    <td style="text-align: center; font-weight: 700;">
                        <?= number_format($stats['frequencia_acumulada'], 1, ',', '') ?>%
                    </td>
                    <td style="text-align: center;">
                        <?php if ($isRisk): ?>
                            <span class="badge-risk">Risco (&lt;75%)</span>
                        <?php else: ?>
                            <span class="badge-safe">Apto</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php
$contentHtml = ob_get_clean();

renderAdminLayout(
    title: $turma['curso_nome'] . ' — Detalhes da Turma',
    activeNav: 'turmas',
    contentHtml: $contentHtml,
    breadcrumbs: [
        'Início' => '/diario',
        'Diário & Turmas' => '/diario/turmas',
        $turma['curso_nome'] => null,
    ]
);
