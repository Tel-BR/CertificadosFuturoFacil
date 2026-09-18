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
require_once __DIR__ . '/../../src/Utils/QRCodeGenerator.php';

use FuturoFacil\Config\Database;
use FuturoFacil\Services\AuthService;
use FuturoFacil\Services\AttendanceService;
use FuturoFacil\Utils\QRCodeGenerator;

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

// Configuração inicial do Modo Telão (Ticket 12)
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'] ?? 'futurofacil.com.br';
$baseUrl = $protocol . $host;
$turmaSlug = (string)($details['codigo_turma'] ?? '');
$initialQrUrl = "{$baseUrl}/turmas/entrar?turma=" . urlencode($turmaSlug) . "&cpf=1&wpp=1&encontro_id=" . (int)$details['encontro_id'];
$initialQrSvg = QRCodeGenerator::generateSvg($initialQrUrl, 360);
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
            /* Tokens Oficiais da Marca Futuro Fácil (ADR-0006) */
            --ff-paper: #FAF7F1;
            --ff-paper-2: #F1ECE3;
            --ff-paper-dark: #E8E2D6;
            --ff-ink: #1B1918;
            --ff-ink-muted: #64748B;
            --ff-ink-soft: #475569;
            --ff-cyan: #0E7490;
            --ff-cyan-hover: #155E75;
            --ff-cyan-soft: #ECFEFF;
            --ff-cyan-border: #A5F3FC;
            --ff-orange: #EA580C;
            --ff-orange-hover: #C2410C;
            --ff-orange-soft: #FFF7ED;
            --ff-line: #E2DFDA;
            --ff-white: #FFFFFF;
            --ff-danger: #DC2626;
            --ff-danger-bg: #FEF2F2;
            --ff-danger-line: #FCA5A5;
            --ff-success: #16A34A;
            --ff-success-bg: #F0FDF4;
            --ff-warning: #D97706;
            --ff-warning-bg: #FFFBEB;

            /* Modo Aula: tons marfim de alto contraste tátil (Ticket UI 04) */
            --ff-presente-bg: #EDF3EC;
            --ff-presente-ink: #235339;
            --ff-presente-line: #BFDCC7;
            --ff-falta-bg: #FDEBEC;
            --ff-falta-ink: #8A2432;
            --ff-falta-line: #F4C2C7;
            --ff-amber-soft: #FBF3DB;
            --ff-amber-ink: #6B4A15;
            --ff-amber-line: #ECD79B;

            /* Aliases de compatibilidade com a folha de estilos existente */
            --primary: var(--ff-cyan);
            --primary-hover: var(--ff-cyan-hover);
            --primary-light: var(--ff-cyan-soft);
            --primary-border: var(--ff-cyan-border);
            --dark: var(--ff-ink);
            --text: var(--ff-ink-soft);
            --text-muted: var(--ff-ink-muted);
            --border: var(--ff-line);
            --bg: var(--ff-paper);
            --surface: var(--ff-white);
            --surface-hover: var(--ff-paper-2);
            --danger: var(--ff-danger);
            --danger-bg: var(--ff-danger-bg);
            --danger-border: var(--ff-danger-line);
            --success: var(--ff-success);
            --success-bg: var(--ff-success-bg);
            --success-border: #A7F3D0;
            --warning: var(--ff-warning);
            --warning-bg: var(--ff-warning-bg);
            --warning-border: #FDE68A;
            --font-main: 'Ubuntu', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            --font-mono: 'JetBrains Mono', monospace;
            --shadow-sm: 0 1px 2px 0 rgba(27, 25, 24, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(27, 25, 24, 0.08), 0 2px 4px -2px rgba(27, 25, 24, 0.06);
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
            padding-bottom: 128px;
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
            min-height: 52px;
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
            gap: 0.5rem;
            background: var(--ff-paper-2);
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
            background: var(--ff-presente-bg);
            color: var(--ff-presente-ink);
            border: 1px solid var(--ff-presente-line);
            box-shadow: 0 1px 2px rgba(35, 83, 57, 0.12);
        }

        .btn-toggle.active.btn-falta {
            background: var(--ff-falta-bg);
            color: var(--ff-falta-ink);
            border: 1px solid var(--ff-falta-line);
            box-shadow: 0 1px 2px rgba(138, 36, 50, 0.12);
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

        .btn-save-main.is-secondary {
            background: var(--surface);
            color: var(--primary);
            border: 1px solid var(--border);
            box-shadow: none;
        }

        .btn-save-main.is-secondary:hover, .btn-save-main.is-secondary:active {
            background: var(--surface-hover);
        }

        /* Pílula de status do Auto-Save (Ticket UI 04) */
        .autosave-status {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 5px 12px;
            border-radius: 20px;
            margin: 0 auto 0.625rem auto;
            max-width: 100%;
            width: fit-content;
            text-align: center;
            background: var(--ff-paper-2);
            color: var(--text-muted);
            border: 1px solid var(--border);
            transition: background-color 0.2s ease, color 0.2s ease, border-color 0.2s ease;
        }

        .autosave-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: currentColor;
            flex-shrink: 0;
        }

        .autosave-status.state-saving {
            background: var(--ff-cyan-soft);
            color: var(--ff-cyan-hover);
            border-color: var(--ff-cyan-border);
        }

        .autosave-status.state-saved {
            background: var(--ff-presente-bg);
            color: var(--ff-presente-ink);
            border-color: var(--ff-presente-line);
        }

        .autosave-status.state-offline {
            background: var(--ff-amber-soft);
            color: var(--ff-amber-ink);
            border-color: var(--ff-amber-line);
        }

        /* Card Colapsável do Plano de Aula Dinâmico (Ticket UI 04) */
        .plano-toggle {
            cursor: pointer;
            user-select: none;
            border-radius: 8px;
        }

        .plano-toggle:focus-visible {
            outline: 2px solid var(--primary);
            outline-offset: 2px;
        }

        .plano-chevron {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            min-width: 32px;
            border: none;
            background: var(--ff-paper-2);
            border-radius: 50%;
            color: var(--text-muted);
            transition: transform 0.25s ease, background-color 0.15s ease;
            pointer-events: none;
        }

        .plano-toggle[aria-expanded="true"] .plano-chevron {
            transform: rotate(180deg);
            background: var(--primary-light);
            color: var(--primary);
        }

        .plano-resumo {
            font-size: 0.875rem;
            color: var(--text-muted);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-bottom: 0.25rem;
        }

        .plano-body {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }

        .plano-body.expanded {
            max-height: 480px;
        }

        .plano-body-inner {
            padding-top: 0.5rem;
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
        /* Estilos do Modo Telão (Ticket 12) */
        .btn-telao-trigger {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            background-color: var(--dark);
            color: #FFFFFF;
            border: 1px solid rgba(255, 255, 255, 0.15);
            padding: 5px 12px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 700;
            cursor: pointer;
            transition: background-color 0.15s ease;
            text-decoration: none;
        }
        .btn-telao-trigger:hover {
            background-color: #1E293B;
        }
        .telao-overlay {
            position: fixed;
            inset: 0;
            background-color: #090D16;
            color: #F8FAFC;
            z-index: 99999;
            display: none;
            flex-direction: column;
            overflow-y: auto;
            padding: 1.5rem;
        }
        .telao-overlay.is-active {
            display: flex;
        }
        .telao-top-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-bottom: 1.25rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            flex-wrap: wrap;
            gap: 1rem;
        }
        .telao-brand {
            font-size: 1.125rem;
            font-weight: 800;
            color: #38BDF8;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .telao-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .btn-telao-action {
            background: rgba(255, 255, 255, 0.08);
            color: #FFFFFF;
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 7px 14px;
            border-radius: 6px;
            font-size: 0.8125rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }
        .btn-telao-action:hover {
            background: rgba(255, 255, 255, 0.15);
        }
        .telao-toggles-bar {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1.75rem;
            margin: 1.25rem 0;
            padding: 0.75rem 1.25rem;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 8px;
            flex-wrap: wrap;
        }
        .telao-toggle-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8125rem;
            font-weight: 600;
            color: #E2E8F0;
            cursor: pointer;
            user-select: none;
        }
        .telao-toggle-label input[type="checkbox"] {
            width: 17px;
            height: 17px;
            accent-color: #0E7490;
            cursor: pointer;
        }
        .telao-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 0.5rem 0 2rem 0;
            max-width: 680px;
            margin: 0 auto;
        }
        .telao-course-title {
            font-size: 1.875rem;
            font-weight: 800;
            color: #FFFFFF;
            line-height: 1.2;
            margin-bottom: 0.35rem;
        }
        .telao-course-meta {
            font-size: 1rem;
            color: #94A3B8;
            margin-bottom: 1.25rem;
        }
        .telao-qr-card {
            background: #FFFFFF;
            padding: 1.25rem;
            border-radius: 14px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.25rem;
        }
        .telao-instructions {
            font-size: 0.9375rem;
            color: #CBD5E1;
            line-height: 1.5;
            margin-bottom: 1rem;
            max-width: 520px;
        }
        .telao-url-box {
            font-family: 'JetBrains Mono', Consolas, monospace;
            font-size: 0.8125rem;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.15);
            padding: 0.4rem 0.875rem;
            border-radius: 6px;
            color: #38BDF8;
            word-break: break-all;
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
            <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                <span class="shift-badge">
                    <strong>[<?= htmlspecialchars($details['turno_config']['letra'], ENT_QUOTES, 'UTF-8') ?>]</strong>
                    <?= htmlspecialchars($details['turno_config']['nome'], ENT_QUOTES, 'UTF-8') ?>
                </span>
                <span class="encontro-pill">Encontro <?= (int)$details['numero_encontro'] ?> de <?= (int)$details['total_aulas'] ?></span>
            </div>
            <button type="button" class="btn-telao-trigger" onclick="abrirModoTelao()" title="Projetar QR Code no Telão">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="3" rx="2"/><line x1="8" x2="16" y1="21" y2="21"/><line x1="12" x2="12" y1="17" y2="21"/></svg>
                <span>Projetar no Telão (QR Code)</span>
            </button>
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

        <!-- Seção 1: Plano de Aula Dinâmico (card colapsável) -->
        <section class="section-card plano-card">
            <div class="section-header plano-toggle" id="planoToggle" role="button" tabindex="0" aria-expanded="false" aria-controls="planoBody">
                <h2 class="section-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                    <span>Plano de Aula & Diário Pedagógico</span>
                </h2>
                <span class="plano-chevron" aria-hidden="true">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                </span>
            </div>
            <p class="plano-resumo" id="planoResumo"></p>
            <div class="plano-body" id="planoBody">
                <div class="plano-body-inner">
                    <p class="section-help">
                        Conteúdo ministrado neste encontro. Já vem pré-carregado com a previsão da ementa; adapte ou complete livremente conforme a dinâmica real da aula.
                    </p>
                    <textarea
                        name="conteudo_ministrado"
                        id="conteudoMinistrado"
                        class="textarea-plano"
                        placeholder="Descreva o conteúdo que foi efetivamente trabalhado com os alunos..."><?= htmlspecialchars($details['conteudo_sugerido'], ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>
            </div>
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
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px; vertical-align: -2px;"><polyline points="20 6 9 17 4 12"></polyline></svg>Presente
                            </button>
                            
                            <button 
                                type="button" 
                                class="btn-toggle btn-falta <?= !$isPresente ? 'active' : '' ?>" 
                                onclick="setPresenca(<?= $alunoId ?>, 0)"
                                title="Marcar Falta">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px; vertical-align: -2px;"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>Falta
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Barra Flutuante de Salvamento -->
        <div class="floating-save-bar">
            <div class="autosave-status state-idle" id="autosaveStatus" role="status" aria-live="polite">
                <span class="autosave-dot" aria-hidden="true"></span>
                <span id="autosaveText">Alterações são salvas automaticamente</span>
            </div>
            <div class="floating-save-inner">
                <label class="abono-check" title="Se acionado, todos os alunos receberão 100% de presença neste encontro por motivo institucional ou feriado">
                    <input type="checkbox" name="abonado" value="1" <?= ((int)$details['abonado'] === 1) ? 'checked' : '' ?>>
                    <span>Abonar aula coletivamente para toda a turma</span>
                </label>

                <noscript>
                    <button type="submit" class="btn-save-main">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                        <span>Salvar Diário & Chamada</span>
                    </button>
                </noscript>
                <button type="button" class="btn-save-main" id="btnSalvarAgora">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    <span>Salvar Agora</span>
                </button>
            </div>
        </div>
    </form>
</main>

<script>
(function () {
    'use strict';

    var encontroId = <?= (int)$details['encontro_id'] ?>;
    var csrfToken = <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE) ?>;
    var STORAGE_KEY = 'ff_aula_pending_' + encontroId;
    var DEBOUNCE_MS = 600;
    var RETRY_MS = 6000;

    var saveTimer = null;
    var retryTimer = null;
    var isSaving = false;
    var dirtyWhileSaving = false;

    var statusEl = document.getElementById('autosaveStatus');
    var statusText = document.getElementById('autosaveText');
    var planoToggle = document.getElementById('planoToggle');
    var planoBody = document.getElementById('planoBody');
    var planoResumo = document.getElementById('planoResumo');
    var textareaPlano = document.getElementById('conteudoMinistrado');
    var abonadoCheckbox = document.querySelector('input[name="abonado"]');
    var formChamada = document.getElementById('formChamada');
    var btnMarcarTodos = document.getElementById('btnMarcarTodos');
    var btnSalvarAgora = document.getElementById('btnSalvarAgora');

    // ---------------------------------------------------------------
    // Chamada: alternância Presente / Falta
    // ---------------------------------------------------------------
    window.setPresenca = function (alunoId, valor) {
        var input = document.getElementById('input_presenca_' + alunoId);
        var card = document.getElementById('card_aluno_' + alunoId);
        if (!input || !card) return;

        input.value = valor.toString();

        var btnPresente = card.querySelector('.btn-presente');
        var btnFalta = card.querySelector('.btn-falta');

        if (valor === 1) {
            btnPresente.classList.add('active');
            btnFalta.classList.remove('active');
            card.classList.remove('is-falta');
        } else {
            btnPresente.classList.remove('active');
            btnFalta.classList.add('active');
            card.classList.add('is-falta');
        }

        recalcularPlacarLocal();
        scheduleAutoSave();
    };

    function recalcularPlacarLocal() {
        var inputs = document.querySelectorAll('input[id^="input_presenca_"]');
        var presentes = 0;
        var faltas = 0;

        inputs.forEach(function (inp) {
            if (inp.value === '1') {
                presentes++;
            } else {
                faltas++;
            }
        });

        var statPres = document.getElementById('statPresentes');
        var statFal = document.getElementById('statFaltas');

        if (statPres) statPres.textContent = presentes.toString();
        if (statFal) statFal.textContent = faltas.toString();
    }

    if (btnMarcarTodos) {
        btnMarcarTodos.addEventListener('click', function () {
            document.querySelectorAll('input[id^="input_presenca_"]').forEach(function (inp) {
                var alunoId = inp.id.replace('input_presenca_', '');
                window.setPresenca(parseInt(alunoId, 10), 1);
            });
        });
    }

    // ---------------------------------------------------------------
    // Plano de Aula: card colapsável com resumo de 1 linha
    // ---------------------------------------------------------------
    function updateResumo() {
        if (!textareaPlano || !planoResumo) return;
        var val = textareaPlano.value.trim();
        planoResumo.textContent = val
            ? val.split('\n')[0]
            : 'Nenhum conteúdo registrado ainda — toque para preencher.';
    }

    function setPlanoExpanded(expanded) {
        if (!planoToggle || !planoBody) return;
        planoToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        planoBody.classList.toggle('expanded', expanded);
        if (planoResumo) planoResumo.style.display = expanded ? 'none' : '';
        if (expanded && textareaPlano) textareaPlano.focus();
    }

    if (planoToggle) {
        planoToggle.addEventListener('click', function () {
            var expanded = planoToggle.getAttribute('aria-expanded') === 'true';
            setPlanoExpanded(!expanded);
        });
        planoToggle.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                planoToggle.click();
            }
        });
    }

    updateResumo();

    if (textareaPlano) {
        textareaPlano.addEventListener('input', function () {
            updateResumo();
            scheduleAutoSave();
        });
    }

    if (abonadoCheckbox) {
        abonadoCheckbox.addEventListener('change', function () {
            scheduleAutoSave();
        });
    }

    // ---------------------------------------------------------------
    // Auto-Save assíncrono (debounce 600ms) com retenção offline
    // ---------------------------------------------------------------
    function setStatus(state, text) {
        if (!statusEl) return;
        statusEl.classList.remove('state-idle', 'state-saving', 'state-saved', 'state-offline');
        statusEl.classList.add('state-' + state);
        if (statusText && text) statusText.textContent = text;
    }

    function readPending() {
        try {
            var raw = window.localStorage.getItem(STORAGE_KEY);
            return raw ? JSON.parse(raw) : null;
        } catch (e) {
            return null;
        }
    }

    function writePending(payload) {
        try {
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify({ payload: payload, ts: Date.now() }));
        } catch (e) {
            /* Armazenamento local indisponível: segue sem cache preventivo. */
        }
    }

    function clearPending() {
        try {
            window.localStorage.removeItem(STORAGE_KEY);
        } catch (e) {
            /* ignorar */
        }
    }

    function buildPayload() {
        var presencas = {};
        document.querySelectorAll('input[id^="input_presenca_"]').forEach(function (inp) {
            var id = inp.id.replace('input_presenca_', '');
            presencas[id] = inp.value;
        });
        return {
            csrf_token: csrfToken,
            encontro_id: String(encontroId),
            conteudo_ministrado: textareaPlano ? textareaPlano.value : '',
            abonado: (abonadoCheckbox && abonadoCheckbox.checked) ? '1' : '0',
            presencas: presencas
        };
    }

    function toFormBody(payload) {
        var params = new URLSearchParams();
        params.append('csrf_token', payload.csrf_token);
        params.append('encontro_id', payload.encontro_id);
        params.append('conteudo_ministrado', payload.conteudo_ministrado);
        params.append('abonado', payload.abonado);
        Object.keys(payload.presencas).forEach(function (id) {
            params.append('presencas[' + id + ']', payload.presencas[id]);
        });
        return params;
    }

    function applyServerUpdate(data) {
        if (!data || !data.students) return;

        data.students.forEach(function (aluno) {
            var card = document.getElementById('card_aluno_' + aluno.aluno_id);
            if (!card) return;

            var badge = card.querySelector('.freq-badge');
            if (badge) {
                badge.classList.toggle('risco', !!aluno.is_risk);
                badge.classList.toggle('apto', !aluno.is_risk);
                var freqTxt = Number(aluno.frequencia_acumulada).toLocaleString('pt-BR', {
                    minimumFractionDigits: 1,
                    maximumFractionDigits: 1
                });
                badge.textContent = freqTxt + '% Freq' + (aluno.is_risk ? ' (Atenção)' : '');
            }
            card.classList.toggle('is-risco', !!aluno.is_risk);
        });

        var statPres = document.getElementById('statPresentes');
        var statFal = document.getElementById('statFaltas');
        var statRisco = document.getElementById('statRisco');
        if (statPres && typeof data.total_presentes !== 'undefined') statPres.textContent = data.total_presentes;
        if (statFal && typeof data.total_faltas !== 'undefined') statFal.textContent = data.total_faltas;
        if (statRisco && typeof data.total_risco !== 'undefined') statRisco.textContent = data.total_risco;
    }

    function scheduleRetry() {
        if (retryTimer) return;
        retryTimer = setTimeout(function () {
            retryTimer = null;
            var pending = readPending();
            if (pending) {
                doSave(pending.payload);
            }
        }, RETRY_MS);
    }

    function doSave(explicitPayload) {
        var payload = explicitPayload || buildPayload();

        if (isSaving) {
            dirtyWhileSaving = true;
            return;
        }

        isSaving = true;
        setStatus('saving', 'Salvando...');

        fetch('/diario/aula_autosave.php', {
            method: 'POST',
            body: toFormBody(payload),
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json().then(function (data) {
                return { ok: response.ok, data: data };
            }).catch(function () {
                return { ok: false, data: null };
            });
        }).then(function (result) {
            isSaving = false;

            if (result.ok && result.data && result.data.success) {
                clearPending();
                applyServerUpdate(result.data);
                setStatus('saved', 'Salvo no banco às ' + result.data.saved_at);

                if (dirtyWhileSaving) {
                    dirtyWhileSaving = false;
                    scheduleAutoSave();
                }
                return;
            }

            if (result.data && (result.data.error === 'auth_required' || result.data.error === 'csrf_invalid')) {
                setStatus('offline', 'Sessão expirada. Recarregue a página para continuar salvando.');
                return;
            }

            writePending(payload);
            setStatus('offline', 'Não foi possível salvar agora. Alterações retidas localmente.');
            scheduleRetry();
        }).catch(function () {
            isSaving = false;
            writePending(payload);
            setStatus('offline', 'Sem conexão — alterações retidas localmente. Tentando reconectar...');
            scheduleRetry();
        });
    }

    function scheduleAutoSave() {
        if (saveTimer) clearTimeout(saveTimer);
        saveTimer = setTimeout(function () {
            saveTimer = null;
            doSave();
        }, DEBOUNCE_MS);
    }

    if (btnSalvarAgora) {
        btnSalvarAgora.addEventListener('click', function () {
            if (saveTimer) {
                clearTimeout(saveTimer);
                saveTimer = null;
            }
            doSave();
        });
    }

    if (formChamada) {
        formChamada.addEventListener('submit', function (e) {
            e.preventDefault();
            if (saveTimer) {
                clearTimeout(saveTimer);
                saveTimer = null;
            }
            doSave();
        });
    }

    window.addEventListener('online', function () {
        var pending = readPending();
        if (pending) doSave(pending.payload);
    });

    var initialPending = readPending();
    if (initialPending && initialPending.payload) {
        setStatus('offline', 'Restaurando alterações não sincronizadas...');
        doSave(initialPending.payload);
    }
})();
</script>

<!-- Modal / Overlay de Apresentação no Telão (Ticket 12) -->
<div id="modalTelao" class="telao-overlay" role="dialog" aria-modal="true" aria-label="Modo Telão">
    <div class="telao-top-bar">
        <div class="telao-brand">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="3" rx="2"/><line x1="8" x2="16" y1="21" y2="21"/><line x1="12" x2="12" y1="17" y2="21"/></svg>
            <span>FUTURO FÁCIL &bull; MODO TELÃO</span>
        </div>
        <div class="telao-actions">
            <button type="button" class="btn-telao-action" onclick="toggleFullscreenTelao()" id="btnFullscreenTelao">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/></svg>
                <span>Tela Cheia</span>
            </button>
            <button type="button" class="btn-telao-action" onclick="fecharModoTelao()" title="Fechar Telão (Esc)">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                <span>Fechar [Esc]</span>
            </button>
        </div>
    </div>

    <!-- Toggles Rápidos para o Instrutor -->
    <div class="telao-toggles-bar">
        <label class="telao-toggle-label">
            <input type="checkbox" id="telaoChkCpf" checked onchange="atualizarTelaoQr()">
            <span>Coletar CPF</span>
        </label>
        <label class="telao-toggle-label">
            <input type="checkbox" id="telaoChkWpp" checked onchange="atualizarTelaoQr()">
            <span>Coletar WhatsApp</span>
        </label>
        <label class="telao-toggle-label">
            <input type="checkbox" id="telaoChkPresenca" checked onchange="atualizarTelaoQr()">
            <span>Registrar Presença Automática</span>
        </label>
    </div>

    <!-- Conteúdo Central Projetável -->
    <div class="telao-content">
        <h2 class="telao-course-title"><?= htmlspecialchars($details['curso_nome'], ENT_QUOTES, 'UTF-8') ?></h2>
        <div class="telao-course-meta">
            <?= !empty($details['cliente_nome']) ? htmlspecialchars($details['cliente_nome'], ENT_QUOTES, 'UTF-8') . ' &bull; ' : '' ?>
            Encontro <?= (int)$details['numero_encontro'] ?> &bull; <?= htmlspecialchars($details['turno_config']['nome'], ENT_QUOTES, 'UTF-8') ?>
        </div>

        <div class="telao-qr-card" id="telaoQrCard">
            <div id="telaoQrContainer">
                <?= $initialQrSvg ?>
            </div>
        </div>

        <div class="telao-instructions">
            <strong>Como acessar os materiais didáticos e registrar presença:</strong><br>
            1. Aponte a câmera do seu smartphone para o QR Code na tela.<br>
            2. Digite seu <strong>Nome Completo</strong> e <strong>E-mail</strong> para receber a chave e liberar o acesso.
        </div>

        <div style="margin-top: 0.5rem;">
            <span style="font-size: 0.8125rem; color: #94A3B8; margin-right: 0.5rem;">Link de acesso direto:</span>
            <a href="<?= htmlspecialchars($initialQrUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" class="telao-url-box" id="telaoUrlLink">
                <span id="telaoUrlText"><?= htmlspecialchars($initialQrUrl, ENT_QUOTES, 'UTF-8') ?></span>
            </a>
        </div>
    </div>
</div>

<script>
(function() {
    const baseUrl = <?= json_encode($baseUrl) ?>;
    const turmaSlug = <?= json_encode($turmaSlug) ?>;
    const encontroId = <?= (int)$details['encontro_id'] ?>;

    window.abrirModoTelao = function() {
        const modal = document.getElementById('modalTelao');
        if (modal) {
            modal.classList.add('is-active');
            document.body.style.overflow = 'hidden';
            atualizarTelaoQr();
        }
    };

    window.fecharModoTelao = function() {
        const modal = document.getElementById('modalTelao');
        if (modal) {
            modal.classList.remove('is-active');
            document.body.style.overflow = '';
            if (document.fullscreenElement) {
                document.exitFullscreen().catch(function() {});
            }
        }
    };

    window.toggleFullscreenTelao = function() {
        const modal = document.getElementById('modalTelao');
        if (!document.fullscreenElement) {
            if (modal.requestFullscreen) {
                modal.requestFullscreen().catch(function() {});
            }
        } else {
            if (document.exitFullscreen) {
                document.exitFullscreen().catch(function() {});
            }
        }
    };

    window.atualizarTelaoQr = function() {
        const chkCpf = document.getElementById('telaoChkCpf');
        const chkWpp = document.getElementById('telaoChkWpp');
        const chkPresenca = document.getElementById('telaoChkPresenca');

        const params = new URLSearchParams();
        params.set('turma', turmaSlug);
        if (chkCpf && chkCpf.checked) params.set('cpf', '1');
        if (chkWpp && chkWpp.checked) params.set('wpp', '1');
        if (chkPresenca && chkPresenca.checked && encontroId > 0) {
            params.set('encontro_id', String(encontroId));
        }

        const fullUrl = baseUrl + '/turmas/entrar?' + params.toString();

        const urlText = document.getElementById('telaoUrlText');
        const urlLink = document.getElementById('telaoUrlLink');
        if (urlText) urlText.textContent = fullUrl;
        if (urlLink) urlLink.href = fullUrl;

        // Atualiza a imagem do QR Code dinamicamente via endpoint vetorial
        const container = document.getElementById('telaoQrContainer');
        if (container) {
            container.innerHTML = '<img src="/diario/qrcode?text=' + encodeURIComponent(fullUrl) + '&size=360" width="360" height="360" alt="QR Code" style="display: block; max-width: 100%; height: auto;">';
        }
    };

    // Fechamento com a tecla Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            fecharModoTelao();
        }
    });
})();
</script>

</body>
</html>
