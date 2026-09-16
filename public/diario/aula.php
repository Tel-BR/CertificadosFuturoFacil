<?php
/**
 * Modo Aula Mobile — Chamada em Tempo Real e Plano de Aula Dinâmico
 * Plataforma Integrada de Gestão Pedagógica, Diário e Certificados Futuro Fácil
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/Database.php';
require_once __DIR__ . '/../../src/Services/AuthService.php';
require_once __DIR__ . '/../../src/Services/CalendarService.php';
require_once __DIR__ . '/../../src/Services/AttendanceService.php';

use FuturoFacil\Config\Database;
use FuturoFacil\Services\AuthService;
use FuturoFacil\Services\AttendanceService;

// Autenticação obrigatória do operador
AuthService::requireAuth();

$service = new AttendanceService();
$user = AuthService::getCurrentUser();

$encontroId = isset($_GET['encontro_id']) ? (int)$_GET['encontro_id'] : (isset($_POST['encontro_id']) ? (int)$_POST['encontro_id'] : 0);

if ($encontroId <= 0) {
    header('Location: /diario/turmas');
    exit;
}

$flashSuccess = null;
$flashError = null;

// Processamento de submissão do formulário (Salvar Diário & Chamada)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!AuthService::validateCsrfToken($token)) {
        $flashError = 'Erro de segurança: Token CSRF inválido ou expirado. Recarregue a página.';
    } else {
        $conteudoMinistrado = trim((string)($_POST['conteudo_ministrado'] ?? ''));
        $presencas = $_POST['presencas'] ?? [];
        $abonado = isset($_POST['abonado']) ? (bool)$_POST['abonado'] : null;

        try {
            $saveResult = $service->saveAttendance($encontroId, $conteudoMinistrado, $presencas, $abonado);
            $flashSuccess = "Diário e chamada gravados com sucesso! ({$saveResult['total_presentes']} presentes, {$saveResult['total_faltas']} faltas).";
        } catch (Throwable $e) {
            $flashError = 'Erro ao gravar diário de classe: ' . $e->getMessage();
        }
    }
}

// Carrega os detalhes do encontro e a lista de alunos atualizada
$details = $service->getEncontroDetails($encontroId);
if (!$details) {
    die('Encontro não encontrado no sistema.');
}

$attendanceList = $service->getAttendanceList($encontroId);
$csrfToken = AuthService::getCsrfToken();

// Contadores para o placar da chamada
$totalAlunos = count($attendanceList);
$totalPresentes = 0;
$totalFaltas = 0;
$totalRisco = 0;

foreach ($attendanceList as $aluno) {
    if ((int)$aluno['presente'] === 1) {
        $totalPresentes++;
    } else {
        $totalFaltas++;
    }
    if ($aluno['is_risk']) {
        $totalRisco++;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    <title>Modo Aula: <?= htmlspecialchars($details['curso_nome'], ENT_QUOTES, 'UTF-8') ?> — Futuro Fácil</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Ubuntu:ital,wght@0,300;0,400;0,500;0,700;1,400&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #0E7490;
            --primary-hover: #155E75;
            --primary-light: #ECFEFF;
            --primary-border: #A5F3FC;
            --dark: #0F172A;
            --text: #1E293B;
            --text-muted: #64748B;
            --border: #E2E8F0;
            --bg: #F8FAFC;
            --surface: #FFFFFF;
            --surface-hover: #F1F5F9;
            --danger: #DC2626;
            --danger-bg: #FEF2F2;
            --danger-border: #FCA5A5;
            --success: #16A34A;
            --success-bg: #F0FDF4;
            --success-border: #86EFAC;
            --warning: #D97706;
            --warning-bg: #FFFBEB;
            --warning-border: #FDE68A;
            --font-main: 'Ubuntu', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            --font-mono: 'JetBrains Mono', monospace;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.08), 0 2px 4px -2px rgb(0 0 0 / 0.06);
            --radius-md: 10px;
            --radius-lg: 14px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            font-family: var(--font-main);
            background-color: var(--bg);
            color: var(--text);
            line-height: 1.5;
            min-height: 100vh;
            padding-bottom: 90px;
        }

        .aula-topbar {
            background-color: var(--surface);
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: var(--shadow-sm);
        }

        .aula-topbar-inner {
            max-width: 900px;
            margin: 0 auto;
            padding: 0.75rem 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .back-nav {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            color: var(--text-muted);
            font-size: 0.875rem;
            font-weight: 600;
            transition: color 0.15s;
        }

        .back-nav:hover {
            color: var(--primary);
        }

        .brand-badge {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            background: var(--primary-light);
            color: var(--primary);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .aula-container {
            max-width: 900px;
            margin: 0 auto;
            padding: 1.25rem 1rem;
        }

        .aula-header-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 1.25rem;
            margin-bottom: 1.25rem;
            box-shadow: var(--shadow-sm);
        }

        .aula-meta-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .shift-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            font-weight: 700;
            font-size: 0.8125rem;
            padding: 4px 10px;
            border-radius: 6px;
            border: 1px solid <?= htmlspecialchars($details['turno_config']['cor_borda'], ENT_QUOTES, 'UTF-8') ?>;
            background-color: <?= htmlspecialchars($details['turno_config']['cor_fundo'], ENT_QUOTES, 'UTF-8') ?>;
            color: <?= htmlspecialchars($details['turno_config']['cor_texto'], ENT_QUOTES, 'UTF-8') ?>;
        }

        .encontro-pill {
            background: #E2E8F0;
            color: #334155;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 4px 8px;
            border-radius: 6px;
        }

        .curso-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--dark);
            line-height: 1.3;
            margin-bottom: 0.25rem;
        }

        .cliente-info {
            font-size: 0.875rem;
            color: var(--text-muted);
            margin-bottom: 0.75rem;
        }

        .aula-info-chips {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
            font-size: 0.8125rem;
            color: var(--text-muted);
            padding-top: 0.5rem;
            border-top: 1px solid var(--border);
        }

        .aula-nav-buttons {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .btn-nav-enc {
            flex: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.35rem;
            background: var(--surface);
            border: 1px solid var(--border);
            color: var(--text);
            padding: 0.5rem;
            border-radius: 8px;
            font-size: 0.8125rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.15s ease;
        }

        .btn-nav-enc:hover:not(.disabled) {
            background: var(--surface-hover);
            border-color: #CBD5E1;
            color: var(--primary);
        }

        .btn-nav-enc.disabled {
            opacity: 0.4;
            pointer-events: none;
        }

        .alert {
            padding: 0.875rem 1rem;
            border-radius: var(--radius-md);
            margin-bottom: 1.25rem;
            font-size: 0.875rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert-success {
            background-color: var(--success-bg);
            border: 1px solid var(--success-border);
            color: var(--success);
        }

        .alert-danger {
            background-color: var(--danger-bg);
            border: 1px solid var(--danger-border);
            color: var(--danger);
        }

        .section-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 1.25rem;
            margin-bottom: 1.25rem;
            box-shadow: var(--shadow-sm);
        }

        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.75rem;
        }

        .section-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .section-tag {
            font-size: 0.6875rem;
            font-weight: 700;
            background: #FEF3C7;
            color: #B45309;
            padding: 2px 6px;
            border-radius: 4px;
            text-transform: uppercase;
        }

        .section-help {
            font-size: 0.8125rem;
            color: var(--text-muted);
            margin-bottom: 0.75rem;
            line-height: 1.4;
        }

        .textarea-plano {
            width: 100%;
            min-height: 90px;
            padding: 0.75rem;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-family: var(--font-main);
            font-size: 0.9375rem;
            color: var(--text);
            background: #FCFDFE;
            resize: vertical;
            transition: border-color 0.15s, box-shadow 0.15s;
        }

        .textarea-plano:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(14, 116, 144, 0.15);
        }

        .chamada-toolbar {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .btn-marcar-todos {
            width: 100%;
            min-height: 48px;
            background: #ECFDF5;
            border: 2px solid #10B981;
            color: #065F46;
            font-size: 0.9375rem;
            font-weight: 700;
            border-radius: 10px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.2s ease;
            box-shadow: var(--shadow-sm);
        }

        .btn-marcar-todos:hover, .btn-marcar-todos:active {
            background: #D1FAE5;
            transform: translateY(-1px);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0.5rem;
        }

        .stat-item {
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 0.5rem 0.25rem;
            text-align: center;
        }

        .stat-val {
            font-size: 1.125rem;
            font-weight: 700;
            color: var(--dark);
            font-family: var(--font-mono);
            line-height: 1.1;
        }

        .stat-label {
            font-size: 0.6875rem;
            color: var(--text-muted);
            text-transform: uppercase;
            font-weight: 600;
            margin-top: 2px;
        }

        .stat-val.presente { color: var(--success); }
        .stat-val.falta { color: var(--danger); }
        .stat-val.risco { color: var(--warning); }

        .alunos-list {
            display: flex;
            flex-direction: column;
            gap: 0.625rem;
        }

        .aluno-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            padding: 0.75rem 0.875rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            transition: border-color 0.15s, background-color 0.15s;
        }

        .aluno-card.is-falta {
            border-color: #FCA5A5;
            background-color: #FFFBFB;
        }

        .aluno-card.is-risco {
            border-left: 4px solid var(--warning);
        }

        .aluno-card.is-risco-severo {
            border-left: 4px solid var(--danger);
        }

        .aluno-info {
            flex: 1;
            min-width: 0;
        }

        .aluno-nome {
            font-size: 0.9375rem;
            font-weight: 600;
            color: var(--dark);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-bottom: 2px;
        }

        .aluno-meta {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.75rem;
            color: var(--text-muted);
            font-family: var(--font-mono);
            flex-wrap: wrap;
        }

        .freq-badge {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.6875rem;
            font-weight: 700;
            font-family: var(--font-mono);
        }

        .freq-badge.apto {
            background: #ECFDF5;
            color: #065F46;
            border: 1px solid #A7F3D0;
        }

        .freq-badge.risco {
            background: #FEF2F2;
            color: #991B1B;
            border: 1px solid #FECACA;
        }

        .presenca-controls {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            background: #F1F5F9;
            padding: 3px;
            border-radius: 8px;
        }

        .btn-toggle {
            min-width: 44px;
            min-height: 44px;
            padding: 0 0.75rem;
            border: none;
            border-radius: 6px;
            font-size: 0.8125rem;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.25rem;
            background: transparent;
            color: #64748B;
            transition: all 0.15s ease;
        }

        .btn-toggle.active.btn-presente {
            background: var(--success);
            color: #FFFFFF;
            box-shadow: 0 1px 3px rgba(22, 163, 74, 0.3);
        }

        .btn-toggle.active.btn-falta {
            background: var(--danger);
            color: #FFFFFF;
            box-shadow: 0 1px 3px rgba(220, 38, 38, 0.3);
        }

        .floating-save-bar {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(255, 255, 255, 0.96);
            backdrop-filter: blur(8px);
            border-top: 1px solid var(--border);
            padding: 0.75rem 1rem;
            z-index: 99;
            box-shadow: 0 -4px 10px rgba(0, 0, 0, 0.05);
        }

        .floating-save-inner {
            max-width: 900px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }

        .abono-check {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8125rem;
            color: var(--text-muted);
            cursor: pointer;
            user-select: none;
        }

        .abono-check input {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: var(--warning);
        }

        .btn-save-main {
            flex: 1;
            max-width: 320px;
            min-height: 48px;
            background: var(--primary);
            color: #FFFFFF;
            font-size: 1rem;
            font-weight: 700;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            box-shadow: 0 3px 10px rgba(14, 116, 144, 0.3);
            transition: all 0.2s ease;
        }

        .btn-save-main:hover, .btn-save-main:active {
            background: var(--primary-hover);
            transform: translateY(-1px);
        }

        @media (max-width: 600px) {
            .curso-title {
                font-size: 1.125rem;
            }
            .floating-save-inner {
                flex-direction: column;
                align-items: stretch;
                gap: 0.5rem;
            }
            .btn-save-main {
                max-width: 100%;
            }
            .abono-check {
                justify-content: center;
            }
        }
    </style>
</head>
<body>

<header class="aula-topbar">
    <div class="aula-topbar-inner">
        <a href="/diario/calendario" class="back-nav" title="Voltar para o Calendário">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
            <span>Calendário</span>
        </a>

        <div class="brand-badge">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            <span>Modo Aula</span>
        </div>

        <a href="/diario/turmas" class="back-nav" title="Listar Todas as Turmas">
            <span>Turmas</span>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
        </a>
    </div>
</header>

<main class="aula-container">
    <?php if ($flashSuccess): ?>
        <div class="alert alert-success" role="alert">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <span><?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    <?php endif; ?>

    <?php if ($flashError): ?>
        <div class="alert alert-danger" role="alert">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span><?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    <?php endif; ?>

    <!-- Card de Identificação da Aula -->
    <section class="aula-header-card">
        <div class="aula-meta-row">
            <span class="shift-badge">
                <strong>[<?= htmlspecialchars($details['turno_config']['letra'], ENT_QUOTES, 'UTF-8') ?>]</strong>
                <?= htmlspecialchars($details['turno_config']['nome'], ENT_QUOTES, 'UTF-8') ?>
            </span>
            <span class="encontro-pill">Encontro <?= (int)$details['numero_encontro'] ?> de <?= (int)$details['total_aulas'] ?></span>
        </div>

        <h1 class="curso-title"><?= htmlspecialchars($details['curso_nome'], ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="cliente-info">Cliente: <strong><?= htmlspecialchars($details['cliente_nome'] ?? 'Institucional', ENT_QUOTES, 'UTF-8') ?></strong></p>

        <div class="aula-info-chips">
            <div>
                <strong>Data:</strong> <?= htmlspecialchars($details['data_formatada'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($details['dia_semana'], ENT_QUOTES, 'UTF-8') ?>)
            </div>
            <div>
                <strong>Horário:</strong> <?= htmlspecialchars($details['horario'] ?: $details['turno_config']['horario_padrao'], ENT_QUOTES, 'UTF-8') ?>
            </div>
            <div>
                <strong>Instrutor:</strong> <?= htmlspecialchars($details['instrutor'] ?? 'Telmo Tropia', ENT_QUOTES, 'UTF-8') ?>
            </div>
        </div>

        <div class="aula-nav-buttons">
            <?php if ($details['prev_encontro_id']): ?>
                <a href="/diario/aula?encontro_id=<?= $details['prev_encontro_id'] ?>" class="btn-nav-enc">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                    <span>Encontro Anterior</span>
                </a>
            <?php else: ?>
                <span class="btn-nav-enc disabled">Primeiro Encontro</span>
            <?php endif; ?>

            <?php if ($details['next_encontro_id']): ?>
                <a href="/diario/aula?encontro_id=<?= $details['next_encontro_id'] ?>" class="btn-nav-enc">
                    <span>Próximo Encontro</span>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                </a>
            <?php else: ?>
                <span class="btn-nav-enc disabled">Último Encontro</span>
            <?php endif; ?>
        </div>
    </section>

    <!-- Formulário Principal: Plano Dinâmico & Chamada -->
    <form action="/diario/aula?encontro_id=<?= (int)$details['encontro_id'] ?>" method="POST" id="formChamada">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="encontro_id" value="<?= (int)$details['encontro_id'] ?>">

        <!-- Seção 1: Plano de Aula Dinâmico -->
        <section class="section-card">
            <div class="section-header">
                <h2 class="section-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                    <span>Plano de Aula & Diário Pedagógico</span>
                </h2>
                <span class="section-tag">Dinâmico</span>
            </div>
            <p class="section-help">
                Conteúdo ministrado neste encontro. Já vem pré-carregado com a previsão da ementa; adapte ou complete livremente conforme a dinâmica real da aula.
            </p>
            <textarea 
                name="conteudo_ministrado" 
                class="textarea-plano" 
                placeholder="Descreva o conteúdo que foi efetivamente trabalhado com os alunos..."><?= htmlspecialchars($details['conteudo_sugerido'], ENT_QUOTES, 'UTF-8') ?></textarea>
        </section>

        <!-- Seção 2: Chamada em Sala de Aula -->
        <section class="section-card">
            <div class="section-header">
                <h2 class="section-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    <span>Chamada dos Alunos</span>
                </h2>
                <span style="font-size: 0.8125rem; font-weight: 600; color: var(--text-muted);"><?= $totalAlunos ?> matriculados</span>
            </div>

            <div class="chamada-toolbar">
                <button type="button" class="btn-marcar-todos" id="btnMarcarTodos" title="Atribuir presença a todos os alunos com um único toque">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    <span>Marcar Todos Presentes</span>
                </button>

                <div class="stats-grid">
                    <div class="stat-item">
                        <div class="stat-val"><?= $totalAlunos ?></div>
                        <div class="stat-label">Total</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-val presente" id="statPresentes"><?= $totalPresentes ?></div>
                        <div class="stat-label">Presentes</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-val falta" id="statFaltas"><?= $totalFaltas ?></div>
                        <div class="stat-label">Faltas</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-val risco" id="statRisco"><?= $totalRisco ?></div>
                        <div class="stat-label">Em Risco (&lt;75%)</div>
                    </div>
                </div>
            </div>

            <!-- Lista Vertical de Alunos -->
            <div class="alunos-list">
                <?php foreach ($attendanceList as $aluno): 
                    $alunoId = (int)$aluno['aluno_id'];
                    $isPresente = ((int)$aluno['presente'] === 1);
                    $freq = (float)$aluno['frequencia_acumulada'];
                    $isRisco = (bool)$aluno['is_risk'];
                ?>
                    <div class="aluno-card <?= !$isPresente ? 'is-falta' : '' ?> <?= $isRisco ? 'is-risco' : '' ?>" id="card_aluno_<?= $alunoId ?>">
                        <div class="aluno-info">
                            <div class="aluno-nome"><?= htmlspecialchars($aluno['nome_completo'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="aluno-meta">
                                <span>CPF: <?= htmlspecialchars($aluno['cpf_mascarado'] ?? '***.***.***-**', ENT_QUOTES, 'UTF-8') ?></span>
                                <span>•</span>
                                <span class="freq-badge <?= $isRisco ? 'risco' : 'apto' ?>">
                                    <?= number_format($freq, 1, ',', '.') ?>% Freq
                                    <?php if ($isRisco): ?> (Atenção)<?php endif; ?>
                                </span>
                            </div>
                        </div>

                        <!-- Controle Binário: Presente / Falta -->
                        <div class="presenca-controls">
                            <input type="hidden" name="presencas[<?= $alunoId ?>]" id="input_presenca_<?= $alunoId ?>" value="<?= $isPresente ? '1' : '0' ?>">
                            
                            <button 
                                type="button" 
                                class="btn-toggle btn-presente <?= $isPresente ? 'active' : '' ?>" 
                                onclick="setPresenca(<?= $alunoId ?>, 1)"
                                title="Marcar Presente">
                                ✓ Presente
                            </button>
                            
                            <button 
                                type="button" 
                                class="btn-toggle btn-falta <?= !$isPresente ? 'active' : '' ?>" 
                                onclick="setPresenca(<?= $alunoId ?>, 0)"
                                title="Marcar Falta">
                                ✗ Falta
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Barra Flutuante de Salvamento -->
        <div class="floating-save-bar">
            <div class="floating-save-inner">
                <label class="abono-check" title="Se acionado, todos os alunos receberão 100% de presença neste encontro por motivo institucional ou feriado">
                    <input type="checkbox" name="abonado" value="1" <?= ((int)$details['abonado'] === 1) ? 'checked' : '' ?>>
                    <span>Abonar aula coletivamente para toda a turma</span>
                </label>

                <button type="submit" class="btn-save-main">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    <span>Salvar Diário & Chamada</span>
                </button>
            </div>
        </div>
    </form>
</main>

<script>
function setPresenca(alunoId, valor) {
    const input = document.getElementById('input_presenca_' + alunoId);
    const card = document.getElementById('card_aluno_' + alunoId);
    if (!input || !card) return;

    input.value = valor.toString();

    const btnPresente = card.querySelector('.btn-presente');
    const btnFalta = card.querySelector('.btn-falta');

    if (valor === 1) {
        btnPresente.classList.add('active');
        btnFalta.classList.remove('active');
        card.classList.remove('is-falta');
    } else {
        btnPresente.classList.remove('active');
        btnFalta.classList.add('active');
        card.classList.add('is-falta');
    }

    recalcularPlacar();
}

function recalcularPlacar() {
    const inputs = document.querySelectorAll('input[id^="input_presenca_"]');
    let presentes = 0;
    let faltas = 0;

    inputs.forEach(inp => {
        if (inp.value === '1') {
            presentes++;
        } else {
            faltas++;
        }
    });

    const statPres = document.getElementById('statPresentes');
    const statFal = document.getElementById('statFaltas');

    if (statPres) statPres.textContent = presentes.toString();
    if (statFal) statFal.textContent = faltas.toString();
}

document.getElementById('btnMarcarTodos')?.addEventListener('click', function() {
    const inputs = document.querySelectorAll('input[id^="input_presenca_"]');
    inputs.forEach(inp => {
        const alunoId = inp.id.replace('input_presenca_', '');
        setPresenca(parseInt(alunoId, 10), 1);
    });
});
</script>

</body>
</html>
