<?php
/**
 * Validador Público de Autenticidade de Certificados
 * Plataforma Integrada de Gestão Pedagógica, Diário e Certificados Futuro Fácil
 * 
 * Design System Editorial e Experiência de Interface (ADR-0006 / Ticket UI 02)
 * Conformidade Estrita com LGPD (ADR-0002) e Segurança (ADR-0005)
 *
 * Suporta parâmetro via GET (?validar=<HASH> ou ?codigo=<HASH>) ou submissão via POST
 */

declare(strict_types=1);

// Cabeçalhos de Segurança e CSP com suporte ao Turnstile (Ticket 07c / ADR-0005)
if (!headers_sent()) {
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: SAMEORIGIN");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://challenges.cloudflare.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https://challenges.cloudflare.com; frame-src 'self' https://challenges.cloudflare.com; connect-src 'self' https://challenges.cloudflare.com;");
}

require_once __DIR__ . '/../../src/Config/Database.php';
require_once __DIR__ . '/../../src/Services/ValidatorService.php';
require_once __DIR__ . '/../../src/Services/AuthService.php';
require_once __DIR__ . '/../../src/Services/TurnstileService.php';

use FuturoFacil\Config\Database;
use FuturoFacil\Services\ValidatorService;
use FuturoFacil\Services\AuthService;
use FuturoFacil\Services\TurnstileService;

// Rate-limiting estrito: máximo de 30 requisições por minuto por IP (O3 & O4)
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$authService = new AuthService();
if (!$authService->checkValidatorRateLimit($clientIp, 30)) {
    http_response_code(429);
    if (!headers_sent()) {
        header('Retry-After: 60');
    }
    echo "<!DOCTYPE html><html lang='pt-BR'><head><meta charset='UTF-8'><title>Limite Excedido | Futuro Fácil</title></head><body style='font-family: sans-serif; text-align: center; padding: 4rem 1rem;'><h1>429 - Limite de Consultas Excedido</h1><p>Muitas consultas a partir deste endereço IP. Por favor, aguarde 1 minuto antes de tentar novamente.</p></body></html>";
    exit;
}

$turnstileService = new TurnstileService();

// Captura do código enviado
$rawCode = $_GET['validar'] ?? $_GET['codigo'] ?? $_POST['codigo'] ?? null;
$cleanCode = ValidatorService::cleanAuthCode($rawCode);

$service = new ValidatorService();
$resultado = null;

if (!empty($cleanCode)) {
    // Se for submissão via POST com Turnstile, verifica o token se fornecido
    $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($requestMethod === 'POST' && isset($_POST['cf-turnstile-response'])) {
        $turnstileService->verify((string)$_POST['cf-turnstile-response'], $clientIp);
    }
    $resultado = $service->validarCodigo($cleanCode);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Validação de Autenticidade de Certificados — Futuro Fácil</title>
    <meta name="description" content="Consulta pública e validação digital de autenticidade de certificados emitidos pela Futuro Fácil Capacitação Profissional.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Ubuntu:ital,wght@0,300;0,400;0,500;0,700;1,400&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
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
            --ff-slate: #64748B;
            --ff-slate-light: #CBD5E1;

            /* Card Austero em Vermelho Marfim Suave (ADR-0006) */
            --ff-error-paper: #FDEBEC;
            --ff-error-border: #F8C4C6;
            --ff-error-line: #F4A7A9;
            --ff-error-ink: #842029;
            --ff-error-soft: #FCF4F5;

            /* Feedback de Sucesso Institucional */
            --ff-success: #16A34A;
            --ff-success-bg: #F0FDF4;
            --ff-success-border: #BBF7D0;
            --ff-success-ink: #166534;

            /* Tipografia Oficial (CLS < 0.1) */
            --ff-font-main: 'Ubuntu', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            --ff-font-mono: 'JetBrains Mono', monospace;

            /* Disciplina Minimalista: bordas de 1px, sombras sutis */
            --ff-radius-sm: 6px;
            --ff-radius-md: 10px;
            --ff-radius-lg: 14px;
            --ff-shadow-sm: 0 1px 2px 0 rgba(27, 25, 24, 0.04);
            --ff-shadow-md: 0 4px 6px -1px rgba(27, 25, 24, 0.05), 0 2px 4px -2px rgba(27, 25, 24, 0.03);
            --ff-shadow-lg: 0 10px 15px -3px rgba(27, 25, 24, 0.06), 0 4px 6px -4px rgba(27, 25, 24, 0.02);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: var(--ff-font-main);
            background-color: var(--ff-paper);
            color: var(--ff-ink);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            line-height: 1.5;
            letter-spacing: -0.01em;
            -webkit-font-smoothing: antialiased;
        }

        /* Topbar Desktop & Mobile Institucional */
        .topbar {
            background-color: var(--ff-white);
            border-bottom: 1px solid var(--ff-line);
            height: 64px;
            position: sticky;
            top: 0;
            z-index: 50;
            display: flex;
            align-items: center;
            box-shadow: var(--ff-shadow-sm);
        }

        .topbar-inner {
            max-width: 900px;
            width: 100%;
            margin: 0 auto;
            padding: 0 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }

        .brand-area {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            text-decoration: none;
            color: inherit;
        }

        .brand-logo-img {
            display: block;
            width: 150px;
            height: 30px;
            object-fit: contain;
            aspect-ratio: 320 / 64;
        }

        .header-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--ff-cyan);
            background-color: var(--ff-cyan-soft);
            padding: 0.3rem 0.75rem;
            border-radius: 999px;
            border: 1px solid var(--ff-cyan-border);
            letter-spacing: 0.02em;
        }

        .header-badge svg {
            width: 13px;
            height: 13px;
        }

        main {
            flex: 1;
            max-width: 820px;
            width: 100%;
            margin: 2.25rem auto 3.5rem;
            padding: 0 1.25rem;
        }

        /* Card de Pesquisa */
        .search-card {
            background-color: var(--ff-white);
            border-radius: var(--ff-radius-lg);
            border: 1px solid var(--ff-line);
            padding: 2rem;
            box-shadow: var(--ff-shadow-md);
            margin-bottom: 2rem;
        }

        .search-header {
            margin-bottom: 1.5rem;
        }

        .search-header h2 {
            font-size: 1.35rem;
            font-weight: 700;
            color: var(--ff-ink);
            margin-bottom: 0.35rem;
            letter-spacing: -0.02em;
        }

        .search-header p {
            color: var(--ff-ink-muted);
            font-size: 0.925rem;
        }

        .search-form {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .search-input-wrapper {
            flex: 1;
            min-width: 260px;
            position: relative;
        }

        .search-input {
            width: 100%;
            padding: 0.85rem 1rem;
            border: 1px solid var(--ff-line);
            border-radius: var(--ff-radius-md);
            font-size: 0.9375rem;
            font-family: var(--ff-font-mono);
            color: var(--ff-ink);
            transition: all 0.15s ease;
            background-color: var(--ff-paper);
        }

        .search-input:focus {
            outline: none;
            border-color: var(--ff-cyan);
            background-color: var(--ff-white);
            box-shadow: 0 0 0 3px rgba(14, 116, 144, 0.12);
        }

        .search-input::placeholder {
            color: var(--ff-slate-light);
            font-family: var(--ff-font-mono);
        }

        .btn-submit {
            background-color: var(--ff-cyan);
            color: var(--ff-white);
            border: none;
            border-radius: var(--ff-radius-md);
            padding: 0.85rem 1.6rem;
            font-family: var(--ff-font-main);
            font-weight: 600;
            font-size: 0.9375rem;
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: var(--ff-shadow-sm);
        }

        .btn-submit:hover {
            background-color: var(--ff-cyan-hover);
            transform: translateY(-1px);
            box-shadow: var(--ff-shadow-md);
        }

        .btn-submit:active {
            transform: translateY(0);
        }

        /* Cartões de Resultado */
        .result-card {
            background-color: var(--ff-white);
            border-radius: var(--ff-radius-lg);
            border: 1px solid var(--ff-line);
            box-shadow: var(--ff-shadow-md);
            overflow: hidden;
            animation: fadeIn 0.25s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(6px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .result-header {
            padding: 1.5rem 1.75rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            border-bottom: 1px solid var(--ff-line);
        }

        .result-header.success {
            background-color: var(--ff-success-bg);
            border-bottom-color: var(--ff-success-border);
        }

        .result-header.not-found {
            background-color: var(--ff-error-paper);
            border-bottom-color: var(--ff-error-border);
        }

        .status-icon {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .status-icon.success {
            background-color: var(--ff-success);
            color: var(--ff-white);
        }

        .status-icon.not-found {
            background-color: var(--ff-error-ink);
            color: var(--ff-white);
        }

        .status-text h3 {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--ff-ink);
            letter-spacing: -0.01em;
            margin-bottom: 0.2rem;
        }

        .result-header.not-found .status-text h3 {
            color: var(--ff-error-ink);
        }

        .status-text p {
            font-size: 0.875rem;
            color: var(--ff-ink-muted);
        }

        .result-body {
            padding: 1.85rem 1.75rem;
        }

        /* Grade de Dados do Certificado */
        .data-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.25rem;
            margin-bottom: 1.5rem;
        }

        .data-item {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .data-label {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--ff-ink-muted);
        }

        .data-value {
            font-size: 1.05rem;
            font-weight: 600;
            color: var(--ff-ink);
        }

        .data-value.masked {
            font-family: var(--ff-font-mono);
            color: var(--ff-cyan-hover);
            background-color: var(--ff-paper);
            padding: 0.25rem 0.6rem;
            border-radius: var(--ff-radius-sm);
            border: 1px solid var(--ff-line);
            display: inline-block;
            width: fit-content;
            font-size: 0.95rem;
        }

        .auth-hash-box {
            background-color: var(--ff-paper);
            border: 1px dashed var(--ff-line);
            border-radius: var(--ff-radius-md);
            padding: 1rem 1.25rem;
            margin-top: 1.25rem;
        }

        .auth-hash-box.austere {
            background-color: var(--ff-error-soft);
            border: 1px dashed var(--ff-error-line);
        }

        .auth-hash-box .label {
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--ff-ink-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.35rem;
        }

        .auth-hash-box.austere .label {
            color: var(--ff-error-ink);
        }

        .auth-hash-code {
            font-family: var(--ff-font-mono);
            font-size: 0.875rem;
            color: var(--ff-ink);
            word-break: break-all;
            line-height: 1.45;
        }

        .lgpd-notice {
            margin-top: 1.5rem;
            padding: 0.85rem 1.15rem;
            background-color: var(--ff-paper);
            border-left: 3px solid var(--ff-cyan);
            border-radius: var(--ff-radius-sm);
            font-size: 0.8125rem;
            color: var(--ff-ink-muted);
            line-height: 1.5;
        }

        /* Estilização Austera do Card Não Localizado */
        .austere-notice {
            color: var(--ff-ink-soft);
            font-size: 0.9375rem;
            line-height: 1.6;
            margin-bottom: 1.25rem;
        }

        .austere-actions {
            margin-top: 1.5rem;
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .btn-austere {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.65rem 1.15rem;
            border-radius: var(--ff-radius-md);
            font-size: 0.875rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.15s ease;
        }

        .btn-austere.primary {
            background-color: var(--ff-paper-2);
            color: var(--ff-ink);
            border: 1px solid var(--ff-line);
        }

        .btn-austere.primary:hover {
            background-color: var(--ff-paper-dark);
        }

        .btn-austere.support {
            background-color: var(--ff-white);
            color: var(--ff-cyan);
            border: 1px solid var(--ff-cyan-border);
        }

        .btn-austere.support:hover {
            background-color: var(--ff-cyan-soft);
        }

        /* Estado Inicial (Empty State) */
        .empty-state {
            text-align: center;
            padding: 3.5rem 1.5rem;
            background-color: var(--ff-white);
            border-radius: var(--ff-radius-lg);
            border: 1px solid var(--ff-line);
            box-shadow: var(--ff-shadow-sm);
        }

        .empty-state-icon-wrap {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background-color: var(--ff-cyan-soft);
            border: 1px solid var(--ff-cyan-border);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.25rem;
            color: var(--ff-cyan);
        }

        .empty-state h3 {
            font-weight: 700;
            font-size: 1.2rem;
            color: var(--ff-ink);
            margin-bottom: 0.5rem;
            letter-spacing: -0.01em;
        }

        .empty-state p {
            max-width: 520px;
            margin: 0 auto;
            font-size: 0.925rem;
            color: var(--ff-ink-muted);
            line-height: 1.55;
        }

        footer {
            background-color: var(--ff-white);
            border-top: 1px solid var(--ff-line);
            padding: 1.5rem;
            text-align: center;
            font-size: 0.8125rem;
            color: var(--ff-ink-muted);
            margin-top: auto;
            line-height: 1.6;
        }

        footer strong {
            color: var(--ff-ink);
        }

        @media (max-width: 640px) {
            .search-card {
                padding: 1.5rem;
            }
            .result-header {
                padding: 1.25rem;
            }
            .result-body {
                padding: 1.25rem;
            }
            .btn-submit {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>

    <!-- Topbar Institucional com Logo Vetorial Oficial -->
    <header class="topbar">
        <div class="topbar-inner">
            <a href="/validar" class="brand-area" title="Futuro Fácil — Validação Pública de Autenticidade">
                <img src="/assets/logo.svg" alt="Futuro Fácil" width="150" height="30" class="brand-logo-img">
            </a>
            <div class="header-badge">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                    <path d="M9 12l2 2 4-4"/>
                </svg>
                <span>Registro Digital Oficial</span>
            </div>
        </div>
    </header>

    <main>
        <section class="search-card">
            <div class="search-header">
                <h2>Conferência de Autenticidade de Certificado</h2>
                <p>Digite ou escaneie o código SHA-256 impresso no verso do certificado para atestar sua veracidade.</p>
            </div>
            <form action="/validar" method="GET" class="search-form">
                <div class="search-input-wrapper">
                    <input 
                        type="text" 
                        name="validar" 
                        class="search-input" 
                        placeholder="Ex: 6FB1ED64F19C8BBC4DA014D0AEAB6E78..." 
                        value="<?= htmlspecialchars($cleanCode) ?>" 
                        required 
                        autocomplete="off" 
                        spellcheck="false"
                        maxlength="100"
                        oninput="this.value = this.value.toUpperCase().replace(/[^A-F0-9]/g, '')"
                    >
                </div>
                <?= $turnstileService->renderWidget('invisible') ?>
                <?= TurnstileService::renderScriptTag() ?>
                <button type="submit" class="btn-submit">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="11" cy="11" r="8"></circle>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                    </svg>
                    Verificar Autenticidade
                </button>
            </form>
        </section>

        <?php if ($resultado !== null): ?>
            <?php if ($resultado['autentico']): ?>
                <article class="result-card">
                    <div class="result-header success">
                        <div class="status-icon success">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <polyline points="20 6 9 17 4 12"></polyline>
                            </svg>
                        </div>
                        <div class="status-text">
                            <h3>Certificado Autêntico e Válido</h3>
                            <p><?= htmlspecialchars($resultado['mensagem']) ?></p>
                        </div>
                    </div>
                    <div class="result-body">
                        <div class="data-grid">
                            <div class="data-item">
                                <span class="data-label">Titular do Certificado (LGPD)</span>
                                <span class="data-value masked"><?= htmlspecialchars($resultado['aluno_nome_mascarado']) ?></span>
                            </div>
                            <div class="data-item">
                                <span class="data-label">CPF (Mascarado)</span>
                                <span class="data-value masked"><?= htmlspecialchars($resultado['aluno_cpf_mascarado']) ?></span>
                            </div>
                            <div class="data-item" style="grid-column: 1 / -1;">
                                <span class="data-label">Curso Concluído</span>
                                <span class="data-value" style="font-size: 1.25rem; color: var(--ff-cyan);"><?= htmlspecialchars($resultado['curso_nome']) ?></span>
                            </div>
                            <div class="data-item">
                                <span class="data-label">Carga Horária</span>
                                <span class="data-value"><?= (int)$resultado['carga_horaria'] ?> horas (<?= htmlspecialchars($resultado['carga_horaria_extenso']) ?>)</span>
                            </div>
                            <div class="data-item">
                                <span class="data-label">Período de Realização</span>
                                <span class="data-value">
                                    <?= !empty($resultado['data_inicio']) ? htmlspecialchars($resultado['data_inicio']) . ' a ' : '' ?>
                                    <?= htmlspecialchars($resultado['data_conclusao']) ?>
                                </span>
                            </div>
                            <div class="data-item">
                                <span class="data-label">Data de Emissão</span>
                                <span class="data-value"><?= htmlspecialchars($resultado['data_emissao']) ?></span>
                            </div>
                            <div class="data-item">
                                <span class="data-label">Modalidade</span>
                                <span class="data-value"><?= htmlspecialchars($resultado['modalidade']) ?></span>
                            </div>
                            <div class="data-item">
                                <span class="data-label">Instrutor Responsável</span>
                                <span class="data-value"><?= htmlspecialchars($resultado['instrutor']) ?></span>
                            </div>
                            <div class="data-item">
                                <span class="data-label">Local de Concessão</span>
                                <span class="data-value"><?= htmlspecialchars($resultado['cidade']) ?></span>
                            </div>
                            <div class="data-item" style="grid-column: 1 / -1;">
                                <span class="data-label">Assento no Livro de Registro Digital</span>
                                <span class="data-value" style="font-weight: 700; color: var(--ff-ink);">
                                    Livro nº <?= (int)$resultado['livro_numero'] ?> &nbsp;•&nbsp; 
                                    Folha nº <?= (int)$resultado['folha_numero'] ?> &nbsp;•&nbsp; 
                                    Registro nº <?= (int)$resultado['registro_numero'] ?>
                                </span>
                            </div>
                        </div>

                        <div class="auth-hash-box">
                            <div class="label">Código SHA-256 de Autenticidade Registrado</div>
                            <div class="auth-hash-code"><?= htmlspecialchars($resultado['codigo_autenticidade']) ?></div>
                        </div>

                        <div class="lgpd-notice">
                            <strong>Garantia de Privacidade e LGPD:</strong> Em conformidade com a Lei Geral de Proteção de Dados (Lei nº 13.709/2018), os dados cadastrais do titular são exibidos de forma mascarada para assegurar a inviolabilidade de informações pessoais sensíveis.
                        </div>
                    </div>
                </article>
            <?php else: ?>
                <!-- Card Austero de Consulta Não Localizada / Inválida (ADR-0006) -->
                <article class="result-card" style="border-color: var(--ff-error-border);">
                    <div class="result-header not-found">
                        <div class="status-icon not-found">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <circle cx="12" cy="12" r="10"></circle>
                                <line x1="12" y1="8" x2="12" y2="12"></line>
                                <line x1="12" y1="16" x2="12.01" y2="16"></line>
                            </svg>
                        </div>
                        <div class="status-text">
                            <h3>Certificado não localizado</h3>
                            <p><?= htmlspecialchars($resultado['mensagem'] ?? 'Registro não localizado na base oficial de certificação.') ?></p>
                        </div>
                    </div>
                    <div class="result-body">
                        <p class="austere-notice">
                            O código de autenticidade pesquisado não consta nos registros oficiais da Futuro Fácil. Por favor, <strong>conferir a digitação</strong> de todos os 64 caracteres hexadecimais como impressos no verso do documento. Caso a inconsistência persista, orientamos <strong>contatar a instituição</strong> ou a coordenação pedagógica para averiguação formal do registro.
                        </p>
                        <?php if (!empty($resultado['codigo'])): ?>
                            <div class="auth-hash-box austere">
                                <div class="label">Código Pesquisado</div>
                                <div class="auth-hash-code"><?= htmlspecialchars($resultado['codigo']) ?></div>
                            </div>
                        <?php endif; ?>
                        <div class="austere-actions">
                            <a href="/validar" class="btn-austere primary">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <polyline points="1 4 1 10 7 10"></polyline>
                                    <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path>
                                </svg>
                                Limpar e Nova Consulta
                            </a>
                            <a href="https://wa.me/5562981298351?text=Ol%C3%A1%2C+preciso+de+ajuda+com+a+valida%C3%A7%C3%A3o+de+um+certificado." target="_blank" rel="noopener noreferrer" class="btn-austere support">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                                </svg>
                                Contatar Coordenação Institucional
                            </a>
                        </div>
                    </div>
                </article>
            <?php endif; ?>
        <?php else: ?>
            <div class="empty-state">
                <div class="empty-state-icon-wrap">
                    <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                        <path d="M9 12l2 2 4-4"/>
                    </svg>
                </div>
                <h3>Pronto para Validação</h3>
                <p>
                    Aponte a câmera do seu smartphone para o QR Code impresso no verso do certificado ou insira o código alfanumérico no campo de busca acima.
                </p>
            </div>
        <?php endif; ?>
    </main>

    <footer>
        <p>
            Plataforma Oficial de Certificação — <strong>Futuro Fácil Capacitação Profissional</strong><br>
            A autenticidade digital deste registro possui fé pública institucional conforme as normas de cursos livres (Lei nº 9.394/1996 e Decreto nº 5.154/2004).
        </p>
    </footer>

</body>
</html>
