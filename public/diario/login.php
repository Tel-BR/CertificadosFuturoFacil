<?php
/**
 * Tela de Autenticação Administrativa (/diario/login)
 * Plataforma Integrada de Gestão Pedagógica Futuro Fácil
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/Database.php';
require_once __DIR__ . '/../../src/Services/AuthService.php';

use FuturoFacil\Services\AuthService;

// Garante cabeçalho anti-indexação
if (!headers_sent()) {
    header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
    header('Content-Type: text/html; charset=UTF-8');
}

// Inicia sessão segura
AuthService::startSecureSession();

// Se o operador já estiver logado, redireciona para o painel principal
if (AuthService::isAuthenticated()) {
    if (!headers_sent()) {
        header('Location: /diario');
    }
    exit;
}

$authService = new AuthService();
$errorMessage = null;
$isBlocked = false;
$remainingMinutes = 0;
$usernameInput = '';

// Processamento do formulário de Login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usernameInput = trim((string)($_POST['username'] ?? ''));
    $passwordInput = (string)($_POST['password'] ?? '');
    $csrfToken = (string)($_POST['csrf_token'] ?? '');

    if (!AuthService::validateCsrfToken($csrfToken)) {
        $errorMessage = 'Sessão expirada ou requisição inválida (falha no token CSRF). Por favor, recarregue a página e tente novamente.';
    } else {
        $authResult = $authService->authenticate($usernameInput, $passwordInput);

        if ($authResult['success']) {
            // Redireciona com sucesso para a área restrita
            header('Location: /diario');
            exit;
        }

        $errorMessage = $authResult['error'] ?? 'Credenciais inválidas.';
        if (!empty($authResult['blocked'])) {
            $isBlocked = true;
            $remainingMinutes = (int)ceil(($authResult['remaining_seconds'] ?? 900) / 60);
        }
    }
}

// Token CSRF da sessão atual
$csrfToken = AuthService::getCsrfToken();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    <title>Acesso Administrativo — Futuro Fácil</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Ubuntu:ital,wght@0,300;0,400;0,500;0,700;1,400&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #0E7490;
            --primary-hover: #155E75;
            --primary-light: #ECFEFF;
            --primary-border: #A5F3FC;
            --dark: #1B1918;
            --text: #1B1918;
            --text-muted: #64748B;
            --border: #E2DFDA;
            --bg: #FAF7F1;
            --surface: #FFFFFF;
            --danger: #DC2626;
            --danger-bg: #FEF2F2;
            --danger-border: #FCA5A5;
            --font-main: 'Ubuntu', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            --radius-md: 10px;
            --radius-lg: 16px;
            --shadow-card: 0 4px 14px rgba(27, 25, 24, 0.05);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: var(--font-main);
            background-color: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }

        .login-card {
            background-color: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            width: 100%;
            max-width: 420px;
            padding: 2.5rem 2rem;
            box-shadow: var(--shadow-card);
        }

        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .login-logo-img {
            width: 170px;
            height: 34px;
            margin-bottom: 0.75rem;
            display: inline-block;
        }

        .brand-subtitle {
            font-size: 0.875rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
        }

        .alert-error {
            background-color: var(--danger-bg);
            border: 1px solid var(--danger-border);
            color: var(--danger);
            padding: 0.875rem 1rem;
            border-radius: var(--radius-md);
            font-size: 0.875rem;
            margin-bottom: 1.5rem;
            line-height: 1.4;
            display: flex;
            align-items: flex-start;
            gap: 0.625rem;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-label {
            display: block;
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 0.375rem;
        }

        .form-input {
            width: 100%;
            padding: 0.75rem 0.875rem;
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            font-size: 0.9375rem;
            font-family: var(--font-main);
            color: var(--dark);
            background-color: #FFFFFF;
            transition: all 0.2s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(14, 116, 144, 0.15);
        }

        .form-input:disabled {
            background-color: #F1F5F9;
            cursor: not-allowed;
        }

        .btn-submit {
            width: 100%;
            background-color: var(--primary);
            color: #FFFFFF;
            border: none;
            border-radius: var(--radius-md);
            padding: 0.8125rem;
            font-size: 0.9375rem;
            font-weight: 600;
            font-family: var(--font-main);
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 4px 10px rgba(14, 116, 144, 0.25);
            margin-top: 0.5rem;
        }

        .btn-submit:hover:not(:disabled) {
            background-color: var(--primary-hover);
            transform: translateY(-1px);
        }

        .btn-submit:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .security-badge {
            margin-top: 2rem;
            padding-top: 1.25rem;
            border-top: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-size: 0.75rem;
            color: var(--text-muted);
        }

        .security-badge svg {
            color: #10B981;
        }
    </style>
</head>
<body>

<div class="login-card">
    <div class="login-header">
        <a href="/diario" title="Futuro Fácil" style="display: inline-block; text-decoration: none;">
            <img src="/assets/logo.svg" alt="Futuro Fácil" width="180" height="36" class="login-logo-img">
        </a>
        <p class="brand-subtitle">Acesso ao Painel Pedagógico e Diário</p>
    </div>

    <?php if ($errorMessage): ?>
        <div class="alert-error" role="alert">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0; margin-top: 1px;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
            <div><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
    <?php endif; ?>

    <form method="POST" action="/diario/login" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

        <div class="form-group">
            <label for="username" class="form-label">Usuário Institucional</label>
            <input 
                type="text" 
                id="username" 
                name="username" 
                class="form-input" 
                value="<?= htmlspecialchars($usernameInput, ENT_QUOTES, 'UTF-8') ?>" 
                required 
                autofocus
                <?= $isBlocked ? 'disabled' : '' ?>
                placeholder="Ex: admin"
            >
        </div>

        <div class="form-group">
            <label for="password" class="form-label">Senha</label>
            <input 
                type="password" 
                id="password" 
                name="password" 
                class="form-input" 
                required 
                <?= $isBlocked ? 'disabled' : '' ?>
                placeholder="••••••••••••"
            >
        </div>

        <button type="submit" class="btn-submit" <?= $isBlocked ? 'disabled' : '' ?>>
            <?= $isBlocked ? 'Acesso Temporariamente Bloqueado' : 'Entrar no Painel' ?>
        </button>
    </form>

    <div class="security-badge">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
        <span>Área Restrita do Operador Único Institucional</span>
    </div>
</div>

</body>
</html>
