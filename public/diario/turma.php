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
require_once __DIR__ . '/../../src/Services/MaterialService.php';
require_once __DIR__ . '/../../src/Services/TurmaService.php';
require_once __DIR__ . '/../../src/Views/layout_admin.php';

use FuturoFacil\Config\Database;
use FuturoFacil\Services\AuthService;
use FuturoFacil\Services\CalendarService;
use FuturoFacil\Services\AttendanceService;
use FuturoFacil\Services\ExcelSyncService;
use FuturoFacil\Services\MaterialService;
use FuturoFacil\Services\TurmaService;
use function FuturoFacil\Views\renderAdminLayout;

AuthService::requireAuth();

$pdo = Database::getConnection();
$attendanceService = new AttendanceService($pdo);
$excelSyncService = new ExcelSyncService($pdo);
$materialService = new MaterialService($pdo);
$calendarService = new CalendarService($pdo);
$turmaService = new TurmaService($pdo, $calendarService);

$turmaId = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);

if ($turmaId <= 0) {
    // Se nenhum ID for passado, busca a primeira turma ativa ou redireciona para turmas
    $stmtFirst = $pdo->query("SELECT id FROM turmas WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1");
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

// -------------------------------------------------------------
// AÇÃO: Atualização da Chave de Acesso da Turma
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_chave') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!AuthService::verifyCsrfToken($csrfToken)) {
        $feedbackMessage = 'Token de segurança inválido. Tente novamente.';
        $feedbackType = 'danger';
    } else {
        try {
            $novaChave = trim((string)($_POST['nova_chave'] ?? ''));
            $materialService->updateTurmaChaveAcesso($turmaId, $novaChave);
            $feedbackMessage = "Chave de Acesso da Turma atualizada com sucesso para '{$novaChave}'.";
            $feedbackType = 'success';
            $stmtTurma->execute([$turmaId]);
            $turma = $stmtTurma->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $feedbackMessage = "Erro ao atualizar chave de acesso: " . $e->getMessage();
            $feedbackType = 'danger';
        }
    }
}

// -------------------------------------------------------------
// AÇÃO: Configuração do Modo de Certificados no Portal
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_cert_modo') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!AuthService::verifyCsrfToken($csrfToken)) {
        $feedbackMessage = 'Token de segurança inválido. Tente novamente.';
        $feedbackType = 'danger';
    } else {
        try {
            $novoModo = trim((string)($_POST['cert_modo'] ?? 'nenhum'));
            $materialService->updateTurmaCertificadosModo($turmaId, $novoModo);
            $feedbackMessage = "Modo de exibição de certificados no portal do aluno atualizado com sucesso.";
            $feedbackType = 'success';
            $stmtTurma->execute([$turmaId]);
            $turma = $stmtTurma->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $feedbackMessage = "Erro ao atualizar modo de certificados: " . $e->getMessage();
            $feedbackType = 'danger';
        }
    }
}

// -------------------------------------------------------------
// AÇÃO: Cadastro de Novo Material Didático (Upload ou Link)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_material') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!AuthService::verifyCsrfToken($csrfToken)) {
        $feedbackMessage = 'Token de segurança inválido. Tente novamente.';
        $feedbackType = 'danger';
    } else {
        try {
            $data = [
                'turma_id'    => $turmaId,
                'titulo'      => trim((string)($_POST['titulo'] ?? '')),
                'descricao'   => trim((string)($_POST['descricao'] ?? '')),
                'tipo'        => trim((string)($_POST['tipo'] ?? 'apostila')),
                'ordem'       => (int)($_POST['ordem'] ?? 0),
                'ativo'       => isset($_POST['ativo']) ? 1 : 0,
                'url_externa' => trim((string)($_POST['url_externa'] ?? '')),
            ];

            $uploadedFile = isset($_FILES['arquivo_material']) && $_FILES['arquivo_material']['error'] !== UPLOAD_ERR_NO_FILE
                ? $_FILES['arquivo_material']
                : null;

            $materialService->createMaterial($data, $uploadedFile);
            $feedbackMessage = "Material didático '{$data['titulo']}' cadastrado com sucesso no repositório protegido da turma.";
            $feedbackType = 'success';
        } catch (Throwable $e) {
            $feedbackMessage = "Falha ao cadastrar material: " . $e->getMessage();
            $feedbackType = 'danger';
        }
    }
}

// -------------------------------------------------------------
// AÇÃO: Alternar Visibilidade do Material (Ativo <=> Oculto)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_material') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!AuthService::verifyCsrfToken($csrfToken)) {
        $feedbackMessage = 'Token de segurança inválido.';
        $feedbackType = 'danger';
    } else {
        $matId = (int)($_POST['material_id'] ?? 0);
        if ($matId > 0) {
            $materialService->toggleMaterialStatus($matId);
            $feedbackMessage = "Visibilidade do material didático alterada com sucesso.";
            $feedbackType = 'success';
        }
    }
}

// -------------------------------------------------------------
// AÇÃO: Excluir Material Didático
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_material') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!AuthService::verifyCsrfToken($csrfToken)) {
        $feedbackMessage = 'Token de segurança inválido.';
        $feedbackType = 'danger';
    } else {
        $matId = (int)($_POST['material_id'] ?? 0);
        if ($matId > 0) {
            $materialService->deleteMaterial($matId);
            $feedbackMessage = "Material didático e arquivo físico removidos com sucesso.";
            $feedbackType = 'success';
        }
    }
}

// -------------------------------------------------------------
// AÇÃO: Adiamento / Remarcação Atômica em Bloco
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reschedule_turma') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!AuthService::verifyCsrfToken($csrfToken)) {
        $feedbackMessage = 'Token de segurança inválido.';
        $feedbackType = 'danger';
    } else {
        try {
            $novaDataInicio = trim((string)($_POST['nova_data_inicio'] ?? ''));
            $permitirExcecao = !empty($_POST['confirmar_excecao_feriado']);

            $res = $calendarService->rescheduleTurma($turmaId, $novaDataInicio, null, $permitirExcecao);
            $feedbackMessage = "Turma remarcada com sucesso para o período de " . date('d/m/Y', strtotime($res['nova_data_inicio'])) . " a " . date('d/m/Y', strtotime($res['nova_data_conclusao'])) . " ({$res['total_encontros_movidos']} registros atualizados).";
            $feedbackType = 'success';

            $stmtTurma->execute([$turmaId]);
            $turma = $stmtTurma->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $feedbackMessage = "Falha ao remarcar turma em bloco: " . $e->getMessage();
            $feedbackType = 'danger';
        }
    }
}

// -------------------------------------------------------------
// AÇÃO: Agendamento Manual de Deslocamento Logístico (Viagem)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_deslocamento') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!AuthService::verifyCsrfToken($csrfToken)) {
        $feedbackMessage = 'Token de segurança inválido.';
        $feedbackType = 'danger';
    } else {
        try {
            $dataDesloc = trim((string)($_POST['data_deslocamento'] ?? ''));
            $turno = strtoupper(trim((string)($_POST['turno'] ?? 'V')));
            $direcao = trim((string)($_POST['direcao'] ?? 'ida'));
            $descricao = trim((string)($_POST['descricao'] ?? ''));
            $permitirExcecao = !empty($_POST['confirmar_excecao_feriado']);

            $calendarService->scheduleDeslocamento($turmaId, $dataDesloc, $turno, $direcao, $descricao ?: null, $permitirExcecao);
            $feedbackMessage = "Deslocamento logístico (✈) agendado com sucesso para o dia " . date('d/m/Y', strtotime($dataDesloc)) . " [{$turno}].";
            $feedbackType = 'success';
        } catch (Throwable $e) {
            $feedbackMessage = "Erro ao agendar deslocamento logístico: " . $e->getMessage();
            $feedbackType = 'danger';
        }
    }
}

// -------------------------------------------------------------
// AÇÃO: Geração Automática de Deslocamentos (Ida e Volta)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'gerar_deslocamentos_automaticos') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!AuthService::verifyCsrfToken($csrfToken)) {
        $feedbackMessage = 'Token de segurança inválido.';
        $feedbackType = 'danger';
    } else {
        try {
            $permitirExcecao = !empty($_POST['confirmar_excecao_feriado']);
            $criados = $calendarService->addDeslocamentosParaTurma($turmaId, true, true, null, null, null, $permitirExcecao);
            $feedbackMessage = sprintf("Gerados %d blocos de deslocamento logístico (✈) com sucesso para a viagem a %s.", count($criados), $turma['cidade'] ?? 'destino');
            $feedbackType = 'success';
        } catch (Throwable $e) {
            $feedbackMessage = "Falha ao gerar deslocamentos automáticos: " . $e->getMessage();
            $feedbackType = 'danger';
        }
    }
}

// -------------------------------------------------------------
// AÇÕES DA LIXEIRA: Mover para a Lixeira, Restaurar e Expurgo Definitivo
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (in_array($_POST['action'], ['trash', 'restore', 'expunge'], true)) {
        if (!AuthService::verifyCsrfToken($csrfToken)) {
            $feedbackMessage = 'Token de segurança CSRF inválido ou expirado.';
            $feedbackType = 'danger';
        } elseif ($_POST['action'] === 'trash') {
            try {
                $turmaService->moveToTrash($turmaId);
                $stmtTurma->execute([$turmaId]);
                $turma = $stmtTurma->fetch(PDO::FETCH_ASSOC);
                $feedbackMessage = 'Turma movida para a Lixeira com sucesso. Seus horários no calendário foram liberados.';
                $feedbackType = 'success';
            } catch (Throwable $e) {
                $feedbackMessage = 'Falha ao mover turma para a Lixeira: ' . $e->getMessage();
                $feedbackType = 'danger';
            }
        } elseif ($_POST['action'] === 'restore') {
            try {
                $res = $turmaService->restoreFromTrash($turmaId);
                if ($res['success']) {
                    $stmtTurma->execute([$turmaId]);
                    $turma = $stmtTurma->fetch(PDO::FETCH_ASSOC);
                    $feedbackMessage = $res['message'];
                    $feedbackType = 'success';
                } else {
                    $feedbackMessage = $res['message'];
                    $feedbackType = 'danger';
                }
            } catch (Throwable $e) {
                $feedbackMessage = 'Falha ao restaurar turma: ' . $e->getMessage();
                $feedbackType = 'danger';
            }
        } elseif ($_POST['action'] === 'expunge') {
            try {
                $res = $turmaService->expungeTurma($turmaId);
                if ($res['success']) {
                    header('Location: /diario/turmas?filtro=lixeira');
                    exit;
                } else {
                    $feedbackMessage = $res['message'];
                    $feedbackType = 'danger';
                }
            } catch (Throwable $e) {
                $feedbackMessage = 'Falha ao excluir definitivamente: ' . $e->getMessage();
                $feedbackType = 'danger';
            }
        }
    }
}

// Consulta todos os materiais da turma (ativos e ocultos para administração)
$materiaisTurma = $materialService->getMateriaisByTurma($turmaId, false);

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

$isTrashed = !empty($turma['deleted_at']);
$statusClass = $isTrashed ? 'status-lixeira' : ('status-' . ($turma['status'] === 'em_andamento' ? 'andamento' : ($turma['status'] === 'concluida' ? 'concluida' : 'prevista')));
$statusLabel = $isTrashed ? 'Na Lixeira' : ($turma['status'] === 'em_andamento' ? 'Em Andamento' : ($turma['status'] === 'concluida' ? 'Concluída' : 'Prevista'));

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
    .turma-status-badge {
        font-size: 0.75rem;
        font-weight: 700;
        padding: 4px 10px;
        border-radius: 4px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .status-andamento { background: #FEF3C7; color: #B45309; }
    .status-concluida { background: #ECFDF5; color: #065F46; }
    .status-prevista { background: #E0F2FE; color: #0369A1; }
    .status-lixeira { background: #FEE2E2; color: #991B1B; }
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

<?php if ($isTrashed): ?>
    <div style="background: #FEF2F2; border: 1px solid #FECACA; border-radius: 8px; padding: 1.25rem 1.5rem; margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <div style="display: flex; align-items: center; justify-content: center; width: 38px; height: 38px; border-radius: 50%; background: #FEE2E2; color: #DC2626; flex-shrink: 0;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
            </div>
            <div>
                <strong style="color: #991B1B; font-size: 1rem;">Esta turma está na Lixeira</strong>
                <p style="color: #B91C1C; font-size: 0.8125rem; margin: 0.25rem 0 0 0;">
                    Descartada em <?= date('d/m/Y \à\s H:i', strtotime($turma['deleted_at'])) ?>. Seus horários e turnos estão desocupados no calendário de capacidade.
                </p>
            </div>
        </div>
        <div style="display: flex; gap: 0.5rem; align-items: center;">
            <form method="POST" action="/diario/turma?turma_id=<?= $turmaId ?>" style="margin: 0;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="restore">
                <button type="submit" style="background: #0E7490; color: #FFFFFF; border: none; border-radius: 6px; padding: 0.55rem 0.95rem; font-weight: 700; font-size: 0.8125rem; cursor: pointer; display: inline-flex; align-items: center; gap: 0.35rem;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                    <span>Restaurar Turma</span>
                </button>
            </form>
            <form method="POST" action="/diario/turma?turma_id=<?= $turmaId ?>" style="margin: 0;" onsubmit="return confirm('ATENÇÃO: A exclusão física definitiva é permanente e irreversível. Se esta turma possuir certificados oficiais no Livro de Registros, a exclusão será bloqueada. Confirmar?');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="expunge">
                <button type="submit" style="background: #DC2626; color: #FFFFFF; border: none; border-radius: 6px; padding: 0.55rem 0.95rem; font-weight: 700; font-size: 0.8125rem; cursor: pointer; display: inline-flex; align-items: center; gap: 0.35rem;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                    <span>Excluir Definitivamente</span>
                </button>
            </form>
        </div>
    </div>
<?php endif; ?>

<!-- Cabeçalho da Turma -->
<div class="turma-detail-header">
    <div class="turma-badge-row">
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <span class="turma-status-badge <?= $statusClass ?>"><?= $statusLabel ?></span>
            <span style="font-size: 0.8125rem; color: var(--text-muted); font-weight: 600;">
                Código: <strong><?= htmlspecialchars($turma['codigo_turma'], ENT_QUOTES, 'UTF-8') ?></strong>
            </span>
        </div>
        <?php if (!$isTrashed): ?>
            <form method="POST" action="/diario/turma?turma_id=<?= $turmaId ?>" style="margin: 0;" onsubmit="return confirm('Deseja mover esta turma para a Lixeira? Os horários e turnos no calendário serão imediatamente desocupados.');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="trash">
                <button type="submit" style="background: var(--surface); color: #DC2626; border: 1px solid #FECACA; border-radius: 6px; padding: 0.45rem 0.75rem; font-size: 0.75rem; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 0.35rem;">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                    <span>Mover para Lixeira</span>
                </button>
            </form>
        <?php endif; ?>
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
            <span>
                <?php 
                $cidadeTurma = trim((string)($turma['cidade'] ?? 'Goiânia - GO'));
                $isForaDeGoiania = !preg_match('/goi[aâ]nia/i', $cidadeTurma);
                ?>
                <?= htmlspecialchars($turma['cidade'] ?? 'Goiânia - GO', ENT_QUOTES, 'UTF-8') ?>
                <?php if ($isForaDeGoiania): ?>
                    <span style="background: #EEF2FF; color: #4338CA; font-size: 0.6875rem; font-weight: 700; padding: 2px 6px; border-radius: 4px; margin-left: 4px;">✈ Viagem</span>
                <?php endif; ?>
            </span>
        </div>
        <div class="meta-item">
            <label>Chave de Acesso (/turmas)</label>
            <span style="font-family: monospace; color: var(--primary); font-size: 0.9375rem; font-weight: 700;"><?= htmlspecialchars($turma['chave_acesso'], ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    </div>
</div>

<!-- Card de Logística e Deslocamento (Viagens fora de Goiânia) & Remarcação em Bloco -->
<div class="sync-actions-card" style="background: linear-gradient(135deg, #FAF5FF 0%, #F3E8FF 100%); border-color: #E9D5FF;">
    <div class="sync-text">
        <h3 style="color: #6B21A8;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17.8 19.2 16 11l3.5-3.5C21 6 21.5 4 21 3c-1-.5-3 0-4.5 1.5L13 8 4.8 6.2c-.5-.1-.9.1-1.1.5l-.3.5c-.2.5-.1 1 .3 1.3L9 12l-2 3H4l-1 1 3 2 2 3 1-1v-3l3-2 3.5 5.3c.3.4.8.5 1.3.3l.5-.2c.4-.3.6-.7.5-1.2z"/></svg>
            Logística de Deslocamento & Cronograma da Turma
        </h3>
        <p style="color: #7E22CE;">
            <?php if ($isForaDeGoiania): ?>
                Treinamento fora de Goiânia em <strong><?= htmlspecialchars((string)($turma['cidade'] ?? 'Destino'), ENT_QUOTES, 'UTF-8') ?></strong>.
                Os blocos de deslocamento logístico (✈) reservam turnos no calendário e previnem conflitos de agenda sem impactar listas de presença nem a carga horária dos certificados.
            <?php else: ?>
                Turma com realização em <strong><?= htmlspecialchars((string)($turma['cidade'] ?? 'Goiânia - GO'), ENT_QUOTES, 'UTF-8') ?></strong>.
                Você pode registrar deslocamentos logísticos pontuais ou remarcar todos os encontros em bloco de forma atômica.
            <?php endif; ?>
        </p>
    </div>
    <div class="sync-buttons">
        <?php if ($isForaDeGoiania): ?>
            <form method="POST" action="/diario/turma?turma_id=<?= $turmaId ?>" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthService::getCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="gerar_deslocamentos_automaticos">
                <button type="submit" class="btn-export-excel" style="background: #7E22CE;" title="Gerar blocos de deslocamento automático (Ida na véspera e Volta no dia seguinte)">
                    <span>✈ Gerar Ida & Volta Automáticos</span>
                </button>
            </form>
        <?php endif; ?>
        <button type="button" class="btn-import-trigger" style="color: #6B21A8; border-color: #D8B4FE;" onclick="document.getElementById('deslocamentoManualBox').classList.toggle('active');">
            <span>✈ + Agendar Deslocamento</span>
        </button>
        <button type="button" class="btn-import-trigger" style="color: #0369A1; border-color: #7DD3FC;" onclick="document.getElementById('remarcarTurmaBox').classList.toggle('active');">
            <span>🔄 Remarcar em Bloco</span>
        </button>
    </div>

    <!-- Painel Retrátil: Agendar Deslocamento Manual -->
    <div id="deslocamentoManualBox" class="upload-box" style="border-color: #D8B4FE;">
        <form method="POST" action="/diario/turma?turma_id=<?= $turmaId ?>" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; align-items: flex-end;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthService::getCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="add_deslocamento">
            <div>
                <label style="display: block; font-size: 0.8125rem; font-weight: 700; margin-bottom: 0.25rem;">Data da Viagem *</label>
                <input type="date" name="data_deslocamento" required value="<?= $turma['data_inicio'] ?>" style="width: 100%; padding: 0.5rem; border: 1px solid var(--border); border-radius: 6px; font-size: 0.875rem;">
            </div>
            <div>
                <label style="display: block; font-size: 0.8125rem; font-weight: 700; margin-bottom: 0.25rem;">Turno *</label>
                <select name="turno" required style="width: 100%; padding: 0.5rem; border: 1px solid var(--border); border-radius: 6px; font-size: 0.875rem;">
                    <option value="M">[M] Matutino</option>
                    <option value="V" selected>[V] Vespertino</option>
                    <option value="N">[N] Noturno</option>
                    <option value="D">[D] Dia Todo</option>
                </select>
            </div>
            <div>
                <label style="display: block; font-size: 0.8125rem; font-weight: 700; margin-bottom: 0.25rem;">Direção *</label>
                <select name="direcao" required style="width: 100%; padding: 0.5rem; border: 1px solid var(--border); border-radius: 6px; font-size: 0.875rem;">
                    <option value="ida">✈ Ida (Goiânia -> <?= htmlspecialchars($turma['cidade'] ?? 'Destino', ENT_QUOTES, 'UTF-8') ?>)</option>
                    <option value="volta">✈ Volta (<?= htmlspecialchars($turma['cidade'] ?? 'Destino', ENT_QUOTES, 'UTF-8') ?> -> Goiânia)</option>
                </select>
            </div>
            <div>
                <label style="display: block; font-size: 0.8125rem; font-weight: 700; margin-bottom: 0.25rem;">Descrição Opcional</label>
                <input type="text" name="descricao" placeholder="Ex: Translado rodoviário" style="width: 100%; padding: 0.5rem; border: 1px solid var(--border); border-radius: 6px; font-size: 0.875rem;">
            </div>
            <div>
                <label style="display: flex; align-items: center; gap: 0.35rem; font-size: 0.775rem; margin-bottom: 0.5rem; cursor: pointer;">
                    <input type="checkbox" name="confirmar_excecao_feriado" value="1">
                    <span>Permitir em feriado</span>
                </label>
                <button type="submit" class="btn-export-excel" style="background: #7E22CE; width: 100%;">Salvar Deslocamento</button>
            </div>
        </form>
    </div>

    <!-- Painel Retrátil: Remarcação Atômica em Bloco -->
    <div id="remarcarTurmaBox" class="upload-box" style="border-color: #7DD3FC;">
        <form method="POST" action="/diario/turma?turma_id=<?= $turmaId ?>" style="display: flex; flex-direction: column; gap: 0.75rem;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthService::getCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="reschedule_turma">
            <div style="font-size: 0.8125rem; color: #0369A1;">
                <strong>Atenção:</strong> Esta ação recalcula as datas de todos os encontros e deslocamentos a partir da nova data de início informada abaixo, mantendo os intervalos originais. Se houver choque de horário na nova sequência, a operação será cancelada e revertida integralmente.
            </div>
            <div style="display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap;">
                <div style="flex: 1; min-width: 200px;">
                    <label style="display: block; font-size: 0.8125rem; font-weight: 700; margin-bottom: 0.25rem;">Nova Data de Início (1º Encontro):</label>
                    <input type="date" name="nova_data_inicio" required value="<?= $turma['data_inicio'] ?>" style="width: 100%; padding: 0.5rem; border: 1px solid var(--border); border-radius: 6px; font-size: 0.875rem;">
                </div>
                <div style="display: flex; align-items: center; gap: 0.5rem; padding-bottom: 0.5rem;">
                    <label style="display: flex; align-items: center; gap: 0.35rem; font-size: 0.8125rem; cursor: pointer;">
                        <input type="checkbox" name="confirmar_excecao_feriado" value="1">
                        <span>Permitir se coincidir com feriado</span>
                    </label>
                </div>
                <div>
                    <button type="submit" class="btn-export-excel" style="background: #0284C7;">Confirmar Remarcação em Bloco</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Card de Chave de Acesso e Proteção de Conteúdo da Turma -->
<div class="sync-actions-card" style="background: linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 100%); border-color: #BFDBFE;">
    <div class="sync-text">
        <h3 style="color: #1E40AF;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            Portal de Conteúdos da Turma (/turmas)
        </h3>
        <p style="color: #1D4ED8;">
            Chave atual: <strong style="font-family: monospace; background: #FFFFFF; padding: 2px 8px; border-radius: 6px; border: 1px solid #BFDBFE;"><?= htmlspecialchars($turma['chave_acesso'], ENT_QUOTES, 'UTF-8') ?></strong>
            &nbsp;|&nbsp; Modo Certificados: <strong><?= match($turma['portal_certificados_modo'] ?? 'nenhum') {
                'coordenacao' => 'Entregue à Coordenação',
                'download_direto' => 'Download por CPF',
                default => 'Oculto (Nenhum)',
            } ?></strong>
        </p>
    </div>
    <div class="sync-buttons">
        <button type="button" class="btn-export-excel" style="background: #25D366; box-shadow: 0 2px 4px rgba(37, 211, 102, 0.25);" onclick="copiarMensagemWhatsappTurma()">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
            <span>Copiar Mensagem WhatsApp</span>
        </button>
        <button type="button" class="btn-import-trigger" style="color: #1D4ED8; border-color: #93C5FD;" onclick="document.getElementById('configChaveBox').classList.toggle('active');">
            <span>⚙️ Gerenciar Chave & Certificados</span>
        </button>
    </div>

    <!-- Painel Retrátil de Gestão de Chave e Certificados -->
    <div id="configChaveBox" class="upload-box" style="border-color: #93C5FD;">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem;">
            <!-- Form Alterar Chave -->
            <form method="POST" action="/diario/turma?turma_id=<?= $turmaId ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthService::getCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="update_chave">
                <label style="display: block; font-size: 0.8125rem; font-weight: 700; color: var(--dark); margin-bottom: 0.25rem;">
                    Alterar Chave de Acesso da Turma:
                </label>
                <div style="display: flex; gap: 0.5rem;">
                    <input type="text" name="nova_chave" value="<?= htmlspecialchars($turma['chave_acesso'], ENT_QUOTES, 'UTF-8') ?>" required style="flex: 1; padding: 0.5rem; border: 1px solid var(--border); border-radius: 6px; font-family: monospace; font-size: 0.875rem;">
                    <button type="submit" class="btn-export-excel" style="background: var(--primary);">Salvar</button>
                </div>
            </form>

            <!-- Form Modo Certificados -->
            <form method="POST" action="/diario/turma?turma_id=<?= $turmaId ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthService::getCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="update_cert_modo">
                <label style="display: block; font-size: 0.8125rem; font-weight: 700; color: var(--dark); margin-bottom: 0.25rem;">
                    Aviso de Certificados no Portal do Aluno:
                </label>
                <div style="display: flex; gap: 0.5rem;">
                    <select name="cert_modo" style="flex: 1; padding: 0.5rem; border: 1px solid var(--border); border-radius: 6px; font-size: 0.875rem;">
                        <option value="nenhum" <?= ($turma['portal_certificados_modo'] ?? 'nenhum') === 'nenhum' ? 'selected' : '' ?>>Oculto (Nenhum aviso)</option>
                        <option value="coordenacao" <?= ($turma['portal_certificados_modo'] ?? 'nenhum') === 'coordenacao' ? 'selected' : '' ?>>Entregue à Coordenação (Link Validador)</option>
                        <option value="download_direto" <?= ($turma['portal_certificados_modo'] ?? 'nenhum') === 'download_direto' ? 'selected' : '' ?>>Download Direto por CPF</option>
                    </select>
                    <button type="submit" class="btn-export-excel" style="background: var(--primary);">Atualizar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Tabela de Materiais Didáticos da Turma -->
<div class="table-container">
    <div class="table-header-bar">
        <div class="table-header-title">Materiais Didáticos Protegidos (<?= count($materiaisTurma) ?>)</div>
        <button type="button" class="btn-import-trigger" style="font-size: 0.75rem; padding: 4px 10px;" onclick="var box = document.getElementById('addMaterialBox'); box.style.display = (box.style.display === 'none' ? 'block' : 'none');">
            <span>+ Adicionar Material</span>
        </button>
    </div>

    <!-- Caixa retrátil para cadastrar novo material -->
    <div id="addMaterialBox" class="upload-box" style="margin: 1rem 1.5rem; display: none;">
        <form method="POST" action="/diario/turma?turma_id=<?= $turmaId ?>" enctype="multipart/form-data" style="display: flex; flex-direction: column; gap: 1rem;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthService::getCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="add_material">

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem;">
                <div>
                    <label style="display: block; font-size: 0.8125rem; font-weight: 700; margin-bottom: 0.25rem;">Título do Material *</label>
                    <input type="text" name="titulo" required placeholder="ex: Apostila Oficial de Excel" style="width: 100%; padding: 0.5rem; border: 1px solid var(--border); border-radius: 6px; font-size: 0.875rem;">
                </div>
                <div>
                    <label style="display: block; font-size: 0.8125rem; font-weight: 700; margin-bottom: 0.25rem;">Tipo</label>
                    <select name="tipo" style="width: 100%; padding: 0.5rem; border: 1px solid var(--border); border-radius: 6px; font-size: 0.875rem;">
                        <option value="apostila">Apostila (PDF/Doc)</option>
                        <option value="exercicio">Exercício / Planilha (XLSX/ZIP)</option>
                        <option value="slide">Slides / Apresentação (PDF/PPTX)</option>
                        <option value="video">Vídeo (YouTube/Vimeo)</option>
                        <option value="formulario">Formulário (Google/MS Forms)</option>
                        <option value="link">Link Externo</option>
                        <option value="outro">Outro</option>
                    </select>
                </div>
                <div>
                    <label style="display: block; font-size: 0.8125rem; font-weight: 700; margin-bottom: 0.25rem;">Ordem de Exibição</label>
                    <input type="number" name="ordem" value="0" min="0" style="width: 100%; padding: 0.5rem; border: 1px solid var(--border); border-radius: 6px; font-size: 0.875rem;">
                </div>
            </div>

            <div>
                <label style="display: block; font-size: 0.8125rem; font-weight: 700; margin-bottom: 0.25rem;">Descrição Curta (Opcional)</label>
                <input type="text" name="descricao" placeholder="ex: Arquivo com exercícios práticos do encontro 1" style="width: 100%; padding: 0.5rem; border: 1px solid var(--border); border-radius: 6px; font-size: 0.875rem;">
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; background: var(--bg); padding: 1rem; border-radius: 8px;">
                <div>
                    <label style="display: block; font-size: 0.8125rem; font-weight: 700; margin-bottom: 0.25rem;">Opção A: Upload de Arquivo Protegido (.pdf, .xlsx, .pptx, .zip)</label>
                    <input type="file" name="arquivo_material" style="font-size: 0.8125rem;">
                </div>
                <div>
                    <label style="display: block; font-size: 0.8125rem; font-weight: 700; margin-bottom: 0.25rem;">Opção B: Ou URL Externa (YouTube, Forms, Link)</label>
                    <input type="url" name="url_externa" placeholder="https://..." style="width: 100%; padding: 0.4rem; border: 1px solid var(--border); border-radius: 6px; font-size: 0.8125rem;">
                </div>
            </div>

            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
                <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.8125rem; font-weight: 600; cursor: pointer;">
                    <input type="checkbox" name="ativo" value="1" checked>
                    <span>Publicar imediatamente (Visível para Alunos)</span>
                </label>
                <button type="submit" class="btn-export-excel" style="background: var(--primary);">
                    <span>Salvar Material Didático</span>
                </button>
            </div>
        </form>
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 50px;">#</th>
                <th>Tipo</th>
                <th>Título / Descrição</th>
                <th>Arquivo / URL</th>
                <th>Tamanho</th>
                <th style="text-align: center;">Visibilidade</th>
                <th style="text-align: right;">Ações</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($materiaisTurma)): ?>
                <tr>
                    <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 1.5rem;">
                        Nenhum material didático cadastrado para esta turma ainda. Clique em "+ Adicionar Material" acima.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($materiaisTurma as $mat): 
                    $matId = (int)$mat['id'];
                    $isAtivo = ((int)$mat['ativo'] === 1);
                ?>
                    <tr>
                        <td style="font-weight: 700; color: var(--text-muted);"><?= (int)$mat['ordem'] ?></td>
                        <td>
                            <span class="chip-tag" style="background: var(--paper-2); padding: 2px 6px; border-radius: 4px; font-size: 0.6875rem; font-weight: 700;">
                                <?= strtoupper($mat['tipo']) ?>
                            </span>
                        </td>
                        <td>
                            <strong><?= htmlspecialchars($mat['titulo'], ENT_QUOTES, 'UTF-8') ?></strong>
                            <?php if (!empty($mat['descricao'])): ?>
                                <div style="font-size: 0.75rem; color: var(--text-muted);"><?= htmlspecialchars($mat['descricao'], ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="font-size: 0.8125rem;">
                            <?php if (!empty($mat['caminho_arquivo'])): ?>
                                <span style="font-family: monospace; color: var(--primary);"><?= basename($mat['caminho_arquivo']) ?></span>
                            <?php elseif (!empty($mat['url_externa'])): ?>
                                <a href="<?= htmlspecialchars($mat['url_externa'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" style="color: var(--primary); text-decoration: underline;">
                                    <?= htmlspecialchars(substr($mat['url_externa'], 0, 35) . '...', ENT_QUOTES, 'UTF-8') ?>
                                </a>
                            <?php endif; ?>
                        </td>
                        <td style="font-size: 0.8125rem;"><?= $mat['tamanho_formatado'] ?: '—' ?></td>
                        <td style="text-align: center;">
                            <form method="POST" action="/diario/turma?turma_id=<?= $turmaId ?>" style="display: inline;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthService::getCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="toggle_material">
                                <input type="hidden" name="material_id" value="<?= $matId ?>">
                                <button type="submit" style="background: none; border: none; cursor: pointer; padding: 0;">
                                    <?php if ($isAtivo): ?>
                                        <span class="badge-safe" title="Clique para ocultar dos alunos">✓ Visível</span>
                                    <?php else: ?>
                                        <span class="badge-risk" style="background: #E2E8F0; color: #475569;" title="Clique para liberar aos alunos">Oculto</span>
                                    <?php endif; ?>
                                </button>
                            </form>
                        </td>
                        <td style="text-align: right; white-space: nowrap;">
                            <?php if (!empty($mat['caminho_arquivo'])): ?>
                                <a href="/turmas/download?id=<?= $matId ?>" target="_blank" class="btn-aula-action" style="padding: 3px 8px; font-size: 0.75rem; text-decoration: none;" title="Testar download como administrador">
                                    <span>Baixar</span>
                                </a>
                            <?php endif; ?>
                            <form method="POST" action="/diario/turma?turma_id=<?= $turmaId ?>" style="display: inline;" onsubmit="return confirm('Deseja realmente excluir este material didático?');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(AuthService::getCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="delete_material">
                                <input type="hidden" name="material_id" value="<?= $matId ?>">
                                <button type="submit" style="background: none; border: none; color: #DC2626; font-size: 0.75rem; font-weight: 700; cursor: pointer; margin-left: 0.4rem;" title="Excluir material">
                                    Excluir
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
function copiarMensagemWhatsappTurma() {
    var curso = <?= json_encode($turma['curso_nome']) ?>;
    var chave = <?= json_encode($turma['chave_acesso']) ?>;
    var link = "https://futurofacil.com.br/turmas?chave=" + encodeURIComponent(chave);
    var texto = "Olá pessoal! Os materiais didáticos, apostilas e exercícios do nosso curso de *" + curso + "* já estão disponíveis no portal da Futuro Fácil.\n\nAcessem diretamente pelo link com sua chave de acesso:\n" + link;
    
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(texto).then(function() {
            alert("Mensagem copiada com sucesso para a área de transferência!\n\nCole no grupo de WhatsApp da turma.");
        }).catch(function() {
            prompt("Copie a mensagem abaixo para o WhatsApp:", texto);
        });
    } else {
        prompt("Copie a mensagem abaixo para o WhatsApp:", texto);
    }
}
</script>

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
                $isDeslocamento = (($enc['tipo'] ?? 'aula') === 'deslocamento');
                $temChamada = ((int)$enc['total_freq'] > 0);
                $isAbonado = ((int)$enc['abonado'] === 1);
            ?>
                <tr style="<?= $isDeslocamento ? 'background: #FAF5FF;' : '' ?>">
                    <td style="font-weight: 700;">
                        <?= $isDeslocamento ? '✈ #' . (int)$enc['numero_encontro'] : '#' . (int)$enc['numero_encontro'] ?>
                    </td>
                    <td><?= date('d/m/Y', strtotime($enc['data_encontro'])) ?></td>
                    <td>
                        <strong>[<?= htmlspecialchars($enc['turno'], ENT_QUOTES, 'UTF-8') ?>]</strong>
                        <?php if ($isDeslocamento): ?>
                            <span style="color: #6B21A8; font-weight: 600; font-size: 0.8125rem;">✈ Deslocamento Logístico</span>
                        <?php else: ?>
                            <?= substr((string)$enc['horario_inicio'], 0, 5) ?> - <?= substr((string)$enc['horario_fim'], 0, 5) ?>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($enc['conteudo_previsto'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <?php if ($isDeslocamento): ?>
                            <span style="color: var(--text-muted); font-size: 0.775rem; font-style: italic;">Não computa horas de certificado</span>
                        <?php else: ?>
                            <?= htmlspecialchars($enc['conteudo_ministrado'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($isDeslocamento): ?>
                            <span class="badge-safe" style="background: #EEF2FF; color: #4338CA;">✈ Logística</span>
                        <?php elseif ($isAbonado): ?>
                            <span class="turma-status-badge status-prevista">Abonado</span>
                        <?php elseif ($temChamada): ?>
                            <span class="badge-safe">✓ Realizada</span>
                        <?php else: ?>
                            <span style="color: var(--text-muted); font-size: 0.75rem;">Pendente</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: right;">
                        <?php if (!$isDeslocamento): ?>
                            <a href="/diario/aula?encontro_id=<?= $encId ?>" class="btn-aula-action" style="padding: 4px 8px; font-size: 0.75rem;">
                                <span>Modo Aula</span>
                            </a>
                        <?php else: ?>
                            <span style="color: var(--text-muted); font-size: 0.75rem; font-weight: 600;">Sem chamada</span>
                        <?php endif; ?>
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
