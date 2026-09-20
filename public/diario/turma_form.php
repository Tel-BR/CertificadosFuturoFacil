<?php
/**
 * Formulário Unificado de Cadastro e Edição de Turmas (/diario/turmas/novo e /diario/turma/editar)
 * Plataforma Integrada de Gestão Pedagógica, Diário e Certificados Futuro Fácil
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/Database.php';
require_once __DIR__ . '/../../src/Services/AuthService.php';
require_once __DIR__ . '/../../src/Services/CalendarService.php';
require_once __DIR__ . '/../../src/Services/TurmaService.php';
require_once __DIR__ . '/../../src/Views/layout_admin.php';

use FuturoFacil\Config\Database;
use FuturoFacil\Services\AuthService;
use FuturoFacil\Services\CalendarService;
use FuturoFacil\Services\TurmaService;
use function FuturoFacil\Views\renderAdminLayout;

AuthService::requireAuth();

$pdo = Database::getConnection();
$calendarService = new CalendarService($pdo);
$turmaService = new TurmaService($pdo, $calendarService);

$turmaId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isEditing = $turmaId > 0;
$turma = null;
$encontros = [];

if ($isEditing) {
    $turma = $turmaService->getTurmaById($turmaId);
    if (!$turma) {
        header('Location: /diario/turmas');
        exit;
    }

    $stmtEnc = $pdo->prepare("
        SELECT e.*, 
               (SELECT COUNT(*) FROM frequencias f WHERE f.encontro_id = e.id) as total_freq
        FROM encontros e 
        WHERE e.turma_id = ? AND e.deleted_at IS NULL
        ORDER BY e.numero_encontro ASC, e.data_encontro ASC
    ");
    $stmtEnc->execute([$turmaId]);
    $encontros = $stmtEnc->fetchAll(PDO::FETCH_ASSOC);

    $stmtAlunosCount = $pdo->prepare("SELECT COUNT(*) FROM alunos WHERE turma_id = ?");
    $stmtAlunosCount->execute([$turmaId]);
    $totalAlunos = (int)$stmtAlunosCount->fetchColumn();
} else {
    $totalAlunos = 0;
    // Modo Criação: verifica se vieram datas pré-selecionadas pelo Modo Seleção do Calendário
    $datasQuery = trim((string)($_GET['datas'] ?? ''));
    if (!empty($datasQuery)) {
        $datasArray = TurmaService::parseSelectedDates($datasQuery);
        $padraoV = TurmaService::getTurnoDefaultHorarios('V');
        $num = 1;
        foreach ($datasArray as $d) {
            $encontros[] = [
                'id'                => 0,
                'numero_encontro'   => $num++,
                'data_encontro'     => $d,
                'turno'             => 'V',
                'horario_inicio'    => $padraoV['horario_inicio'],
                'horario_fim'       => $padraoV['horario_fim'],
                'conteudo_previsto' => '',
                'tipo'              => 'aula',
                'total_freq'        => 0,
            ];
        }
    }
}

$feedbackError = null;
$feedbackSuccess = null;

// Processamento do Formulário (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!AuthService::verifyCsrfToken($csrfToken)) {
        $feedbackError = "Token de segurança CSRF inválido ou expirado. Atualize a página e tente novamente.";
    } else {
        $cursoNome = trim((string)($_POST['curso_nome'] ?? ''));
        $clienteNome = trim((string)($_POST['cliente_nome'] ?? ''));
        $modalidade = trim((string)($_POST['modalidade'] ?? 'Presencial'));
        $cidade = trim((string)($_POST['cidade'] ?? 'Goiânia - GO'));
        $cargaHoraria = (int)($_POST['carga_horaria'] ?? 0);
        $status = trim((string)($_POST['status'] ?? 'prevista'));
        $turnoPadrao = strtoupper(trim((string)($_POST['turno_padrao'] ?? 'V')));
        $chaveAcesso = trim((string)($_POST['chave_acesso'] ?? ''));
        $instrutor = trim((string)($_POST['instrutor'] ?? ''));
        $ordemServico = trim((string)($_POST['ordem_servico'] ?? ''));
        $ementa = trim((string)($_POST['ementa'] ?? ''));

        // Campos fiscais e de faturamento (Ticket 13)
        $razaoSocial = trim((string)($_POST['razao_social'] ?? '')) ?: null;
        $cnpjTomador = trim((string)($_POST['cnpj_tomador'] ?? '')) ?: null;
        $cidadeUf = trim((string)($_POST['cidade_uf'] ?? '')) ?: null;
        $emailFinanceiro = trim((string)($_POST['email_financeiro'] ?? '')) ?: null;
        $numeroOsContrato = trim((string)($_POST['numero_os_contrato'] ?? '')) ?: null;
        $tipoCobranca = trim((string)($_POST['tipo_cobranca'] ?? 'hora_aula')) ?: 'hora_aula';
        $valorUnitario = isset($_POST['valor_unitario']) && $_POST['valor_unitario'] !== '' ? (float)$_POST['valor_unitario'] : 0.0;
        $valorTotal = isset($_POST['valor_total']) && $_POST['valor_total'] !== '' ? (float)$_POST['valor_total'] : 0.0;

        if (empty($clienteNome) && $razaoSocial) {
            $clienteNome = $razaoSocial;
        }
        if (empty($ordemServico) && $numeroOsContrato) {
            $ordemServico = $numeroOsContrato;
        }

        // Processa grade de encontros submetida
        $postEncontros = $_POST['encontros'] ?? [];
        $gradeSubmetida = [];
        $seq = 1;
        foreach ($postEncontros as $eData) {
            $dEnc = trim((string)($eData['data_encontro'] ?? ''));
            if (empty($dEnc)) {
                continue;
            }
            $hIni = trim((string)($eData['horario_inicio'] ?? ''));
            $hFim = trim((string)($eData['horario_fim'] ?? ''));
            $intervaloMinutos = max(0, (int)($eData['intervalo_minutos'] ?? 0));
            $tEnc = !empty($eData['turno']) ? strtoupper(trim((string)$eData['turno'])) : TurmaService::inferTurnoFromHorarios($hIni, $hFim, $turnoPadrao);
            
            $gradeSubmetida[] = [
                'id'                => (int)($eData['id'] ?? 0),
                'numero_encontro'   => $seq++,
                'data_encontro'     => $dEnc,
                'turno'             => $tEnc,
                'horario_inicio'    => $hIni,
                'horario_fim'       => $hFim,
                'intervalo_minutos' => $intervaloMinutos,
                'conteudo_previsto' => trim((string)($eData['conteudo_previsto'] ?? '')),
                'tipo'              => trim((string)($eData['tipo'] ?? 'aula')) ?: 'aula',
            ];
        }

        try {
            if ($isEditing) {
                // Atualiza dados cadastrais
                $turmaService->updateTurma($turmaId, [
                    'curso_nome'         => $cursoNome,
                    'cliente_nome'       => $clienteNome ?: null,
                    'modalidade'         => $modalidade,
                    'cidade'             => $cidade ?: null,
                    'carga_horaria'      => $cargaHoraria,
                    'status'             => $status,
                    'turno_padrao'       => $turnoPadrao,
                    'chave_acesso'       => $chaveAcesso,
                    'instrutor'          => $instrutor ?: null,
                    'ordem_servico'      => $ordemServico ?: null,
                    'ementa'             => $ementa ?: null,
                    'razao_social'       => $razaoSocial,
                    'cnpj_tomador'       => $cnpjTomador,
                    'cidade_uf'          => $cidadeUf,
                    'email_financeiro'   => $emailFinanceiro,
                    'numero_os_contrato' => $numeroOsContrato,
                    'tipo_cobranca'      => $tipoCobranca,
                    'valor_unitario'     => $valorUnitario,
                    'valor_total'        => $valorTotal,
                ]);

                // Atualiza/Sincroniza encontros existentes e novos
                $idsMantidos = [];
                foreach ($gradeSubmetida as $g) {
                    if ($g['id'] > 0) {
                        $turmaService->updateEncontro($g['id'], $g);
                        $idsMantidos[] = $g['id'];
                    } else {
                        $novoId = $turmaService->addEncontro($turmaId, $g);
                        $idsMantidos[] = $novoId;
                    }
                }

                // Remove encontros que foram retirados da grade (se não tiverem chamadas)
                foreach ($encontros as $velhoEnc) {
                    $vId = (int)$velhoEnc['id'];
                    if (!in_array($vId, $idsMantidos, true)) {
                        $turmaService->deleteEncontro($vId);
                    }
                }

                $turmaService->recalculateTurmaDates($turmaId);
                header("Location: /diario/turma?turma_id={$turmaId}&msg=updated");
                exit;
            } else {
                // Criação de nova turma
                $novoTurmaId = $turmaService->createTurma([
                    'curso_nome'         => $cursoNome,
                    'cliente_nome'       => $clienteNome ?: null,
                    'modalidade'         => $modalidade,
                    'cidade'             => $cidade ?: null,
                    'carga_horaria'      => $cargaHoraria,
                    'status'             => $status,
                    'turno_padrao'       => $turnoPadrao,
                    'chave_acesso'       => $chaveAcesso ?: null,
                    'instrutor'          => $instrutor ?: null,
                    'ordem_servico'      => $ordemServico ?: null,
                    'ementa'             => $ementa ?: null,
                    'razao_social'       => $razaoSocial,
                    'cnpj_tomador'       => $cnpjTomador,
                    'cidade_uf'          => $cidadeUf,
                    'email_financeiro'   => $emailFinanceiro,
                    'numero_os_contrato' => $numeroOsContrato,
                    'tipo_cobranca'      => $tipoCobranca,
                    'valor_unitario'     => $valorUnitario,
                    'valor_total'        => $valorTotal,
                ], $gradeSubmetida);

                header("Location: /diario/turma?turma_id={$novoTurmaId}&msg=created");
                exit;
            }
        } catch (Throwable $e) {
            $feedbackError = "Falha ao salvar turma: " . $e->getMessage();
        }
    }
}

$csrfToken = AuthService::getCsrfToken();
$turnosConfig = CalendarService::getTurnosConfig();

$pageTitle = $isEditing ? "Editar Turma: " . ($turma['curso_nome'] ?? '') : "Nova Turma";

ob_start();
?>
<style>
    .form-turma-container {
        max-width: 1060px;
        margin: 0 auto;
        padding-bottom: 3rem;
    }

    .form-header-bar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 1.5rem;
        padding-bottom: 1rem;
        border-bottom: 1px solid var(--ff-line);
    }

    .form-header-title {
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .form-header-title h1 {
        font-size: 1.35rem;
        font-weight: 700;
        color: var(--ff-ink);
        margin: 0;
    }

    .form-header-title p {
        font-size: 0.875rem;
        color: var(--ff-ink-muted);
        margin: 0.15rem 0 0 0;
    }

    .card-section {
        background: var(--ff-white);
        border: 1px solid var(--ff-line);
        border-radius: var(--ff-radius-md);
        box-shadow: var(--ff-shadow-sm);
        padding: 1.5rem;
        margin-bottom: 1.5rem;
    }

    .section-title {
        font-size: 1rem;
        font-weight: 700;
        color: var(--ff-ink);
        margin: 0 0 1rem 0;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .grid-2 {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
    }

    .grid-3 {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 1rem;
    }

    .grid-4 {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 1rem;
    }

    .form-group {
        margin-bottom: 1rem;
    }

    .form-group label {
        display: block;
        font-size: 0.8125rem;
        font-weight: 600;
        color: var(--ff-ink-soft);
        margin-bottom: 0.35rem;
    }

    .form-control {
        width: 100%;
        padding: 0.55rem 0.75rem;
        font-size: 0.875rem;
        font-family: var(--ff-font-main);
        color: var(--ff-ink);
        background: #FFFFFF;
        border: 1px solid var(--ff-line);
        border-radius: var(--ff-radius-sm);
        transition: border-color 0.15s ease;
    }

    .form-control:focus {
        outline: none;
        border-color: var(--ff-cyan);
        box-shadow: 0 0 0 3px rgba(14, 116, 144, 0.1);
    }

    .shift-selector-group {
        display: flex;
        gap: 0.5rem;
    }

    .shift-opt {
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.4rem;
        padding: 0.5rem;
        border: 1px solid var(--ff-line);
        border-radius: var(--ff-radius-sm);
        cursor: pointer;
        font-weight: 600;
        font-size: 0.8125rem;
        transition: all 0.15s ease;
        background: var(--ff-paper);
    }

    .shift-opt.active {
        border-color: var(--ff-cyan);
        background: var(--ff-cyan-soft);
        color: var(--ff-cyan);
    }

    .shift-badge-pill {
        display: inline-block;
        padding: 1px 6px;
        border-radius: 4px;
        font-weight: 700;
        font-size: 0.75rem;
        font-family: var(--ff-font-mono);
    }

    /* Tabela de Encontros com Overrides */
    .tabela-encontros-wrap {
        overflow-x: auto;
        border: 1px solid var(--ff-line);
        border-radius: var(--ff-radius-sm);
        margin-top: 1rem;
    }

    .tabela-encontros {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.8125rem;
        text-align: left;
    }

    .tabela-encontros th {
        background: var(--ff-paper-2);
        color: var(--ff-ink-soft);
        font-weight: 600;
        padding: 0.65rem 0.75rem;
        border-bottom: 1px solid var(--ff-line);
        white-space: nowrap;
    }

    .tabela-encontros td {
        padding: 0.5rem 0.75rem;
        border-bottom: 1px solid var(--ff-line);
        vertical-align: middle;
        background: #FFFFFF;
    }

    .tabela-encontros tr:last-child td {
        border-bottom: none;
    }

    .tabela-encontros tr:hover td {
        background: var(--ff-paper);
    }

    .btn-action {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.55rem 1rem;
        border-radius: var(--ff-radius-sm);
        font-size: 0.875rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.15s ease;
        text-decoration: none;
        border: 1px solid transparent;
    }

    .btn-primary {
        background: var(--ff-cyan);
        color: #FFFFFF;
    }

    .btn-primary:hover {
        background: var(--ff-cyan-hover);
    }

    .btn-secondary {
        background: var(--ff-white);
        border-color: var(--ff-line);
        color: var(--ff-ink);
    }

    .btn-secondary:hover {
        background: var(--ff-paper);
    }

    .btn-danger-outline {
        background: transparent;
        color: var(--ff-danger);
        border-color: var(--ff-danger-line);
        padding: 0.3rem 0.6rem;
        font-size: 0.75rem;
    }

    .btn-danger-outline:hover {
        background: var(--ff-danger-bg);
    }

    .banner-info {
        background: var(--ff-cyan-soft);
        border: 1px solid var(--ff-cyan-border);
        border-radius: var(--ff-radius-sm);
        padding: 0.75rem 1rem;
        font-size: 0.8125rem;
        color: var(--ff-cyan);
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
        gap: 0.6rem;
    }
</style>

<div class="form-turma-container">

    <div class="form-header-bar">
        <div class="form-header-title">
            <a href="/diario/turmas" class="btn-action btn-secondary" title="Voltar para Turmas">← Voltar</a>
            <div>
                <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
                <p><?= $isEditing ? 'Edição completa de metadados, turnos e overrides da turma' : 'Cadastre turmas com ou sem alunos, flexibilidade total de datas e horários' ?></p>
            </div>
        </div>

        <div style="display: flex; gap: 0.5rem;">
            <button type="submit" form="form-turma" class="btn-action btn-primary">
                <?= $isEditing ? 'Salvar Alterações' : 'Criar Turma' ?>
            </button>
        </div>
    </div>

    <?php if ($feedbackError): ?>
        <div class="banner-info" style="background: var(--ff-danger-bg); border-color: var(--ff-danger-line); color: var(--ff-danger);">
            <strong>Erro:</strong> <?= htmlspecialchars($feedbackError, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <form id="form-turma" class="form-turma" method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

        <!-- Bloco 1: Informações Gerais -->
        <div class="card-section">
            <h2 class="section-title">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                <span>Informações Principais</span>
            </h2>

            <div class="grid-2">
                <div class="form-group">
                    <label for="curso_nome">Nome do Curso / Treinamento *</label>
                    <input type="text" id="curso_nome" name="curso_nome" class="form-control" required
                           value="<?= htmlspecialchars($turma['curso_nome'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="Ex: Excel Avançado & Dashboards Corporativos">
                </div>

                <div class="form-group">
                    <label for="cliente_nome">Cliente / Entidade Contratante</label>
                    <input type="text" id="cliente_nome" name="cliente_nome" class="form-control"
                           value="<?= htmlspecialchars($turma['cliente_nome'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="Ex: Banco Sicoob Cerrado">
                </div>
            </div>

            <div class="grid-4">
                <div class="form-group">
                    <label for="modalidade">Modalidade</label>
                    <select id="modalidade" name="modalidade" class="form-control">
                        <?php 
                        $mod = $turma['modalidade'] ?? 'Presencial';
                        foreach (['Presencial', 'Remoto / Ao Vivo', 'Híbrido'] as $mOpt): ?>
                            <option value="<?= $mOpt ?>" <?= $mod === $mOpt ? 'selected' : '' ?>><?= $mOpt ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="cidade">Cidade / UF</label>
                    <input type="text" id="cidade" name="cidade" class="form-control"
                           value="<?= htmlspecialchars($turma['cidade'] ?? 'Goiânia - GO', ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="form-group">
                    <label for="carga_horaria">Carga Horária (Horas) *</label>
                    <input type="number" id="carga_horaria" name="carga_horaria" class="form-control" min="1" required
                           value="<?= (int)($turma['carga_horaria'] ?? 8) ?>">
                </div>

                <div class="form-group">
                    <label for="status">Status do Ciclo de Vida</label>
                    <select id="status" name="status" class="form-control">
                        <?php 
                        $st = $turma['status'] ?? 'prevista';
                        $statusOptions = [
                            'prevista'     => 'Prevista (Futura)',
                            'em_andamento' => 'Em Andamento',
                            'concluida'    => 'Concluída',
                            'cancelada'    => 'Cancelada',
                        ];
                        foreach ($statusOptions as $val => $lbl): ?>
                            <option value="<?= $val ?>" <?= $st === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid-2">
                <div class="form-group">
                    <label for="chave_acesso">Chave de Acesso do Portal do Aluno</label>
                    <input type="text" id="chave_acesso" name="chave_acesso" class="form-control"
                           value="<?= htmlspecialchars($turma['chave_acesso'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="Deixe em branco para gerar automaticamente (ex: excel-sicoob-123)">
                </div>

                <div class="form-group">
                    <label for="instrutor">Instrutor Responsável</label>
                    <input type="text" id="instrutor" name="instrutor" class="form-control"
                           value="<?= htmlspecialchars($turma['instrutor'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="Ex: Instrutor Futuro Fácil">
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 0;">
                <label for="ementa">Ementa / Conteúdo Programático</label>
                <textarea id="ementa" name="ementa" class="form-control" rows="2"
                          placeholder="Tópicos que serão ministrados na turma..."><?= htmlspecialchars($turma['ementa'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>
        </div>

        <!-- Bloco Faturamento & Dados Financeiros (Ticket 13) -->
        <div class="card-section">
            <h2 class="section-title">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                <span>Faturamento &amp; Dados Financeiros</span>
            </h2>
            <p style="font-size: 0.8125rem; color: var(--ff-ink-muted); margin-top: -0.5rem; margin-bottom: 1rem;">
                Preenchimento flexível para apoio à emissão de NFS-e (pode ser preenchido agora ou no encerramento da turma).
            </p>

            <div class="grid-2">
                <div class="form-group">
                    <label for="razao_social">Razão Social do Tomador (NFS-e)</label>
                    <input type="text" id="razao_social" name="razao_social" class="form-control"
                           value="<?= htmlspecialchars($turma['razao_social'] ?? $turma['cliente_nome'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="Ex: Cooperativa de Crédito Central S/A">
                </div>

                <div class="form-group">
                    <label for="cnpj_tomador">CNPJ / CPF do Tomador</label>
                    <input type="text" id="cnpj_tomador" name="cnpj_tomador" class="form-control"
                           value="<?= htmlspecialchars($turma['cnpj_tomador'] ?? $turma['cliente_cnpj'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="Ex: 00.000.000/0001-00">
                </div>
            </div>

            <div class="grid-3">
                <div class="form-group">
                    <label for="cidade_uf">Município e UF de Faturamento</label>
                    <input type="text" id="cidade_uf" name="cidade_uf" class="form-control"
                           value="<?= htmlspecialchars($turma['cidade_uf'] ?? $turma['cidade'] ?? 'Goiânia - GO', ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="Ex: Goiânia - GO">
                </div>

                <div class="form-group">
                    <label for="email_financeiro">E-mail Financeiro (Envio NFS-e)</label>
                    <input type="email" id="email_financeiro" name="email_financeiro" class="form-control"
                           value="<?= htmlspecialchars($turma['email_financeiro'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="Ex: financeiro@empresa.com.br">
                </div>

                <div class="form-group">
                    <label for="numero_os_contrato">Nº da Ordem de Serviço ou Contrato</label>
                    <input type="text" id="numero_os_contrato" name="numero_os_contrato" class="form-control"
                           value="<?= htmlspecialchars($turma['numero_os_contrato'] ?? $turma['ordem_servico'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="Ex: OS-2026-042 ou CT-8819">
                </div>
            </div>

            <div class="grid-3" style="background: var(--ff-paper); padding: 1rem; border-radius: var(--ff-radius-sm); border: 1px solid var(--ff-line);">
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="tipo_cobranca">Tipo de Cobrança *</label>
                    <select id="tipo_cobranca" name="tipo_cobranca" class="form-control" onchange="recalcularTotalFinanceiro()">
                        <?php 
                        $tc = $turma['tipo_cobranca'] ?? 'hora_aula';
                        ?>
                        <option value="hora_aula" <?= $tc === 'hora_aula' ? 'selected' : '' ?>>Hora-Aula (Horas Reais × Valor Hora)</option>
                        <option value="por_aluno" <?= $tc === 'por_aluno' ? 'selected' : '' ?>>Por Aluno (Alunos Matriculados × Valor Unitário)</option>
                        <option value="valor_fechado" <?= $tc === 'valor_fechado' ? 'selected' : '' ?>>Valor Fechado (Preço Fixo da Turma)</option>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label for="valor_unitario" id="label_valor_unitario">Valor Unitário (R$)</label>
                    <input type="number" step="0.01" min="0" id="valor_unitario" name="valor_unitario" class="form-control"
                           value="<?= htmlspecialchars((string)($turma['valor_unitario'] ?? $turma['valor_hora_aula'] ?? '0.00'), ENT_QUOTES, 'UTF-8') ?>"
                           oninput="recalcularTotalFinanceiro()">
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label for="valor_total">Valor Total Previsto / Faturado (R$)</label>
                    <input type="number" step="0.01" min="0" id="valor_total" name="valor_total" class="form-control"
                           style="font-weight: 700; color: var(--ff-cyan);"
                           value="<?= htmlspecialchars((string)($turma['valor_total'] ?? '0.00'), ENT_QUOTES, 'UTF-8') ?>">
                </div>
            </div>
        </div>

        <!-- Bloco 2: Bidirecionalidade Inteligente de Horários e Turnos Padrão -->
        <div class="card-section">
            <h2 class="section-title">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                <span>Turno e Horários Padrão (Bidirecionalidade Inteligente)</span>
            </h2>

            <p style="font-size: 0.8125rem; color: var(--ff-ink-muted); margin-top: -0.5rem; margin-bottom: 1rem;">
                Escolher um turno pré-carrega o horário padrão. Digitar um horário livre infere e marca automaticamente o turno correspondente.
            </p>

            <div class="grid-2">
                <div class="form-group">
                    <label>Turno Padrão da Turma</label>
                    <input type="hidden" id="turno_padrao" name="turno_padrao" value="<?= htmlspecialchars($turma['turno_padrao'] ?? 'V', ENT_QUOTES, 'UTF-8') ?>">
                    <div class="shift-selector-group">
                        <?php 
                        $curTurno = strtoupper(trim((string)($turma['turno_padrao'] ?? 'V')));
                        foreach ($turnosConfig as $sigla => $t): 
                            $isActive = ($curTurno === $sigla);
                        ?>
                            <div class="shift-opt <?= $isActive ? 'active' : '' ?>" data-shift="<?= $sigla ?>" onclick="selectDefaultShift('<?= $sigla ?>')">
                                <span class="shift-badge-pill" style="background: <?= $t['cor_fundo'] ?>; color: <?= $t['cor_texto'] ?>; border: 1px solid <?= $t['cor_borda'] ?>;">
                                    <?= $sigla ?>
                                </span>
                                <span><?= $t['nome'] ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label for="horario_padrao_inicio">Horário Padrão de Início</label>
                        <input type="time" id="horario_padrao_inicio" class="form-control"
                               value="14:00" onchange="onTimeChange()">
                    </div>
                    <div class="form-group">
                        <label for="horario_padrao_fim">Horário Padrão de Fim</label>
                        <input type="time" id="horario_padrao_fim" class="form-control"
                               value="18:00" onchange="onTimeChange()">
                    </div>
                </div>
            </div>
        </div>

        <!-- Bloco 3: Grade Individual de Encontros com Override -->
        <div class="card-section">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.5rem;">
                <h2 class="section-title" style="margin-bottom: 0;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <span>Grade Individual de Encontros e Overrides</span>
                </h2>

                <button type="button" class="btn-action btn-secondary" onclick="addEncontroRow()">
                    + Adicionar Encontro / Reposição
                </button>
            </div>

            <p style="font-size: 0.8125rem; color: var(--ff-ink-muted); margin-bottom: 0.75rem;">
                Personalize datas, turnos e horários individualmente para cada encontro (ex: encontro de sábado pela manhã em turma vespertina).
            </p>

            <div class="banner-info" style="margin-bottom: 0.75rem;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                <span><strong>Desacoplamento e Alunos Opcionais:</strong> Esta turma pode ser salva e operada com 0 alunos cadastrados, permitindo agendamento antecipado e controle de capacidade imediato.</span>
            </div>

            <div class="tabela-encontros-wrap">
                <table class="tabela-encontros" id="tabela-encontros">
                    <thead>
                        <tr>
                            <th style="width: 50px;">Nº</th>
                            <th style="width: 140px;">Data do Encontro</th>
                            <th style="width: 110px;">Turno</th>
                            <th style="width: 100px;">Início</th>
                            <th style="width: 100px;">Fim</th>
                            <th style="width: 95px;">Intervalo</th>
                            <th style="width: 110px;">Tipo</th>
                            <th>Conteúdo Previsto</th>
                            <th style="width: 80px; text-align: center;">Ações</th>
                        </tr>
                    </thead>
                    <tbody id="grid-encontros-body">
                        <?php if (!empty($encontros)): ?>
                            <?php foreach ($encontros as $idx => $enc): 
                                $encTurno = strtoupper(trim((string)($enc['turno'] ?? 'V')));
                                $hIniEnc = !empty($enc['horario_inicio']) ? substr($enc['horario_inicio'], 0, 5) : '14:00';
                                $hFimEnc = !empty($enc['horario_fim']) ? substr($enc['horario_fim'], 0, 5) : '18:00';
                                $intervaloMinutosEnc = max(0, (int)($enc['intervalo_minutos'] ?? 0));
                                $totalFreq = (int)($enc['total_freq'] ?? 0);
                            ?>
                                <tr data-row-idx="<?= $idx ?>">
                                    <td style="font-weight: 700; color: var(--ff-ink-muted);">
                                        <input type="hidden" name="encontros[<?= $idx ?>][id]" value="<?= (int)$enc['id'] ?>">
                                        <?= (int)($enc['numero_encontro'] ?? ($idx + 1)) ?>
                                    </td>
                                    <td>
                                        <input type="date" name="encontros[<?= $idx ?>][data_encontro]" class="form-control" style="padding: 0.35rem 0.5rem;" required
                                               value="<?= htmlspecialchars($enc['data_encontro'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                    </td>
                                    <td>
                                        <select name="encontros[<?= $idx ?>][turno]" class="form-control row-turno" style="padding: 0.35rem 0.5rem;" onchange="onRowTurnoChange(this)">
                                            <option value="M" <?= $encTurno === 'M' ? 'selected' : '' ?>>[M] Manhã</option>
                                            <option value="V" <?= $encTurno === 'V' ? 'selected' : '' ?>>[V] Tarde</option>
                                            <option value="N" <?= $encTurno === 'N' ? 'selected' : '' ?>>[N] Noite</option>
                                            <option value="D" <?= $encTurno === 'D' ? 'selected' : '' ?>>[D] Integral</option>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="time" name="encontros[<?= $idx ?>][horario_inicio]" class="form-control row-hini" style="padding: 0.35rem 0.5rem;"
                                               value="<?= htmlspecialchars($hIniEnc, ENT_QUOTES, 'UTF-8') ?>" onchange="onRowTimeChange(this)">
                                    </td>
                                    <td>
                                        <input type="time" name="encontros[<?= $idx ?>][horario_fim]" class="form-control row-hfim" style="padding: 0.35rem 0.5rem;"
                                               value="<?= htmlspecialchars($hFimEnc, ENT_QUOTES, 'UTF-8') ?>" onchange="onRowTimeChange(this)">
                                    </td>
                                    <td>
                                        <input type="number" name="encontros[<?= $idx ?>][intervalo_minutos]" class="form-control" min="0" step="1" style="padding: 0.35rem 0.5rem;"
                                               value="<?= $intervaloMinutosEnc ?>" aria-label="Intervalo em minutos">
                                    </td>
                                    <td>
                                        <select name="encontros[<?= $idx ?>][tipo]" class="form-control" style="padding: 0.35rem 0.5rem;">
                                            <option value="aula" <?= ($enc['tipo'] ?? 'aula') === 'aula' ? 'selected' : '' ?>>Aula</option>
                                            <option value="reposicao" <?= ($enc['tipo'] ?? '') === 'reposicao' ? 'selected' : '' ?>>Reposição</option>
                                            <option value="deslocamento" <?= ($enc['tipo'] ?? '') === 'deslocamento' ? 'selected' : '' ?>>✈ Viagem</option>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="text" name="encontros[<?= $idx ?>][conteudo_previsto]" class="form-control" style="padding: 0.35rem 0.5rem;"
                                               value="<?= htmlspecialchars($enc['conteudo_previsto'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                               placeholder="Conteúdo / Plano da aula">
                                    </td>
                                    <td style="text-align: center;">
                                        <?php if ($totalFreq > 0): ?>
                                            <span title="Este encontro possui <?= $totalFreq ?> chamada(s) registradas. Exclusão bloqueada." style="font-size: 0.75rem; color: var(--ff-slate); font-weight: 600;">Gravado</span>
                                        <?php else: ?>
                                            <button type="button" class="btn-danger-outline" onclick="removeEncontroRow(this)">Remover</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div id="grid-empty-msg" style="display: <?= empty($encontros) ? 'block' : 'none' ?>; text-align: center; padding: 2rem; color: var(--ff-ink-muted); font-size: 0.875rem;">
                Nenhum encontro adicionado ainda. Clique em <strong>+ Adicionar Encontro / Reposição</strong> para montar a grade.
            </div>
        </div>

        <div style="display: flex; justify-content: flex-end; gap: 1rem; margin-top: 1.5rem;">
            <a href="/diario/turmas" class="btn-action btn-secondary">Cancelar</a>
            <button type="submit" class="btn-action btn-primary" style="padding: 0.65rem 1.5rem; font-size: 0.9375rem;">
                <?= $isEditing ? 'Salvar Alterações da Turma' : 'Cadastrar Turma' ?>
            </button>
        </div>
    </form>
</div>

<script>
    // Configurações padrão de turnos para o cliente frontend
    const DEFAULT_SHIFTS = {
        'M': { start: '08:00', end: '12:00' },
        'V': { start: '14:00', end: '18:00' },
        'N': { start: '18:30', end: '22:30' },
        'D': { start: '08:00', end: '17:00' }
    };

    /**
     * Inferência Inteligente de Turno no Frontend (JavaScript puro)
     */
    function inferTurno(hIni, hFim, fallback = 'V') {
        if (!hIni || !hFim) return fallback;
        const [h1, m1] = hIni.split(':').map(Number);
        const [h2, m2] = hFim.split(':').map(Number);
        if (isNaN(h1) || isNaN(h2)) return fallback;

        const min1 = h1 * 60 + (m1 || 0);
        const min2 = h2 * 60 + (m2 || 0);
        if (min2 <= min1) return fallback;

        const duracao = min2 - min1;

        // D: >= 6h (360min) ou atravessa almoço
        if (duracao >= 360 || (min1 < 720 && min2 > 840)) return 'D';
        // N: inicia >= 18:00
        if (min1 >= 1080) return 'N';
        // V: inicia entre 12:00 e 17:59 (ex: 13:00-17:00 ou 14:00-16:00)
        if (min1 >= 720 && min1 < 1080) return 'V';
        // M: inicia antes das 12:00
        if (min1 < 720) return 'M';

        return fallback;
    }

    function selectDefaultShift(shift) {
        document.getElementById('turno_padrao').value = shift;
        document.querySelectorAll('.shift-opt').forEach(el => {
            el.classList.toggle('active', el.getAttribute('data-shift') === shift);
        });

        if (DEFAULT_SHIFTS[shift]) {
            document.getElementById('horario_padrao_inicio').value = DEFAULT_SHIFTS[shift].start;
            document.getElementById('horario_padrao_fim').value = DEFAULT_SHIFTS[shift].end;
        }
    }

    function onTimeChange() {
        const hIni = document.getElementById('horario_padrao_inicio').value;
        const hFim = document.getElementById('horario_padrao_fim').value;
        const turnoInferido = inferTurno(hIni, hFim);

        document.getElementById('turno_padrao').value = turnoInferido;
        document.querySelectorAll('.shift-opt').forEach(el => {
            el.classList.toggle('active', el.getAttribute('data-shift') === turnoInferido);
        });
    }

    function onRowTimeChange(inputEl) {
        const tr = inputEl.closest('tr');
        const hIni = tr.querySelector('.row-hini').value;
        const hFim = tr.querySelector('.row-hfim').value;
        const turnoSelect = tr.querySelector('.row-turno');
        const inferido = inferTurno(hIni, hFim, turnoSelect.value);
        turnoSelect.value = inferido;
    }

    function onRowTurnoChange(selectEl) {
        const tr = selectEl.closest('tr');
        const shift = selectEl.value;
        if (DEFAULT_SHIFTS[shift]) {
            tr.querySelector('.row-hini').value = DEFAULT_SHIFTS[shift].start;
            tr.querySelector('.row-hfim').value = DEFAULT_SHIFTS[shift].end;
        }
    }

    let nextRowIndex = <?= count($encontros) ?>;

    function addEncontroRow() {
        const tbody = document.getElementById('grid-encontros-body');
        const emptyMsg = document.getElementById('grid-empty-msg');
        emptyMsg.style.display = 'none';

        const rowNum = tbody.querySelectorAll('tr').length + 1;
        const defaultTurno = document.getElementById('turno_padrao').value || 'V';
        const defaultStart = document.getElementById('horario_padrao_inicio').value || (DEFAULT_SHIFTS[defaultTurno]?.start || '14:00');
        const defaultEnd = document.getElementById('horario_padrao_fim').value || (DEFAULT_SHIFTS[defaultTurno]?.end || '18:00');

        // Calcula data sugerida (+1 dia do último encontro ou hoje)
        let suggestedDate = new Date().toISOString().split('T')[0];
        const lastDateInput = tbody.querySelector('tr:last-child input[type="date"]');
        if (lastDateInput && lastDateInput.value) {
            const d = new Date(lastDateInput.value + 'T12:00:00');
            d.setDate(d.getDate() + 1);
            suggestedDate = d.toISOString().split('T')[0];
        }

        const idx = nextRowIndex++;
        const tr = document.createElement('tr');
        tr.setAttribute('data-row-idx', idx);
        tr.innerHTML = `
            <td style="font-weight: 700; color: var(--ff-ink-muted);">
                <input type="hidden" name="encontros[\${idx}][id]" value="0">
                <span class="row-seq">\${rowNum}</span>
            </td>
            <td>
                <input type="date" name="encontros[\${idx}][data_encontro]" class="form-control" style="padding: 0.35rem 0.5rem;" required value="\${suggestedDate}">
            </td>
            <td>
                <select name="encontros[\${idx}][turno]" class="form-control row-turno" style="padding: 0.35rem 0.5rem;" onchange="onRowTurnoChange(this)">
                    <option value="M" \${defaultTurno === 'M' ? 'selected' : ''}>[M] Manhã</option>
                    <option value="V" \${defaultTurno === 'V' ? 'selected' : ''}>[V] Tarde</option>
                    <option value="N" \${defaultTurno === 'N' ? 'selected' : ''}>[N] Noite</option>
                    <option value="D" \${defaultTurno === 'D' ? 'selected' : ''}>[D] Integral</option>
                </select>
            </td>
            <td>
                <input type="time" name="encontros[\${idx}][horario_inicio]" class="form-control row-hini" style="padding: 0.35rem 0.5rem;" value="\${defaultStart}" onchange="onRowTimeChange(this)">
            </td>
            <td>
                <input type="time" name="encontros[\${idx}][horario_fim]" class="form-control row-hfim" style="padding: 0.35rem 0.5rem;" value="\${defaultEnd}" onchange="onRowTimeChange(this)">
            </td>
            <td>
                <input type="number" name="encontros[\${idx}][intervalo_minutos]" class="form-control" min="0" step="1" style="padding: 0.35rem 0.5rem;" value="0" aria-label="Intervalo em minutos">
            </td>
            <td>
                <select name="encontros[\${idx}][tipo]" class="form-control" style="padding: 0.35rem 0.5rem;">
                    <option value="aula" selected>Aula</option>
                    <option value="reposicao">Reposição</option>
                    <option value="deslocamento">✈ Viagem</option>
                </select>
            </td>
            <td>
                <input type="text" name="encontros[\${idx}][conteudo_previsto]" class="form-control" style="padding: 0.35rem 0.5rem;" placeholder="Conteúdo / Plano da aula">
            </td>
            <td style="text-align: center;">
                <button type="button" class="btn-danger-outline" onclick="removeEncontroRow(this)">Remover</button>
            </td>
        `;

        tbody.appendChild(tr);
    }

    function removeEncontroRow(btn) {
        const tr = btn.closest('tr');
        tr.remove();

        const tbody = document.getElementById('grid-encontros-body');
        const rows = tbody.querySelectorAll('tr');
        if (rows.length === 0) {
            document.getElementById('grid-empty-msg').style.display = 'block';
        } else {
            rows.forEach((r, i) => {
                const seqSpan = r.querySelector('.row-seq');
                if (seqSpan) seqSpan.textContent = i + 1;
            });
        }
    }

    // Cálculo dinâmico reativo de faturamento (Ticket 13)
    function recalcularTotalFinanceiro() {
        var tipoEl = document.getElementById('tipo_cobranca');
        if (!tipoEl) return;
        var tipo = tipoEl.value;
        var vUnit = parseFloat(document.getElementById('valor_unitario').value) || 0;
        var totalEl = document.getElementById('valor_total');
        var labelUnit = document.getElementById('label_valor_unitario');

        if (tipo === 'hora_aula') {
            if (labelUnit) labelUnit.innerText = 'Valor da Hora-Aula (R$)';
            var ch = parseFloat(document.getElementById('carga_horaria').value) || 0;
            totalEl.value = (ch * vUnit).toFixed(2);
        } else if (tipo === 'por_aluno') {
            if (labelUnit) labelUnit.innerText = 'Valor por Aluno (R$)';
            var totalAlunos = <?= (int)($totalAlunos ?? 0) ?>;
            if (totalAlunos > 0) {
                totalEl.value = (totalAlunos * vUnit).toFixed(2);
            }
        } else if (tipo === 'valor_fechado') {
            if (labelUnit) labelUnit.innerText = 'Valor Fechado Global (R$)';
            if (vUnit > 0 && (!parseFloat(totalEl.value) || parseFloat(totalEl.value) === 0)) {
                totalEl.value = vUnit.toFixed(2);
            }
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        var chEl = document.getElementById('carga_horaria');
        if (chEl) {
            chEl.addEventListener('input', function() {
                if (document.getElementById('tipo_cobranca')?.value === 'hora_aula') {
                    recalcularTotalFinanceiro();
                }
            });
        }
    });
</script>
<?php
$contentHtml = ob_get_clean();
renderAdminLayout($pageTitle, 'turmas', $contentHtml);
