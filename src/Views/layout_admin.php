<?php
/**
 * Layout Base Administrativo da Plataforma Futuro Fácil
 * 
 * Fornece a casca de navegação unificada para:
 * - Calendário (Futuro)
 * - Diário de Classe & Turmas (Presente & Passado)
 * - Novo Agendamento (Atalho operacional)
 * - Perfil do Operador e Logout com proteção anti-CSRF
 */

declare(strict_types=1);

namespace FuturoFacil\Views;

use FuturoFacil\Services\AuthService;

function renderAdminLayout(
    string $title,
    string $activeNav,
    string $contentHtml,
    ?array $user = null,
    array $breadcrumbs = []
): void {
    $user = $user ?? AuthService::getCurrentUser() ?? [
        'nome'     => 'Operador',
        'username' => 'admin',
        'email'    => 'contato@futurofacil.com.br',
    ];
    $csrfToken = AuthService::getCsrfToken();
    $userName = htmlspecialchars($user['nome'] ?? 'Operador', ENT_QUOTES, 'UTF-8');
    $userEmail = htmlspecialchars($user['email'] ?? '', ENT_QUOTES, 'UTF-8');
    $userInitial = strtoupper(mb_substr($user['nome'] ?? 'O', 0, 1, 'UTF-8'));
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> — Futuro Fácil</title>
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
            --success: #16A34A;
            --success-bg: #F0FDF4;
            --warning: #D97706;
            --warning-bg: #FFFBEB;
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
        }

        body {
            font-family: var(--font-main);
            background-color: var(--bg);
            color: var(--text);
            line-height: 1.5;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Topbar Institucional */
        .topbar {
            background-color: var(--surface);
            border-bottom: 1px solid var(--border);
            height: 68px;
            position: sticky;
            top: 0;
            z-index: 100;
            display: flex;
            align-items: center;
            box-shadow: var(--shadow-sm);
        }

        .topbar-inner {
            max-width: 1280px;
            width: 100%;
            margin: 0 auto;
            padding: 0 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .brand-area {
            display: flex;
            align-items: center;
            gap: 1.25rem;
            text-decoration: none;
            color: var(--dark);
        }

        .brand-logo {
            display: flex;
            align-items: center;
            gap: 0.625rem;
        }

        .brand-icon {
            width: 38px;
            height: 38px;
            background: linear-gradient(135deg, #0E7490, #0891B2);
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #FFFFFF;
            font-weight: 700;
            font-size: 1.25rem;
            box-shadow: 0 3px 10px rgba(14, 116, 144, 0.25);
        }

        .brand-text {
            display: flex;
            flex-direction: column;
        }

        .brand-name {
            font-size: 1.2rem;
            font-weight: 700;
            letter-spacing: -0.5px;
            color: var(--dark);
            line-height: 1.1;
        }

        .brand-name span {
            color: var(--primary);
        }

        .brand-tag {
            font-size: 0.6875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
        }

        /* Navegação Principal */
        .nav-menu {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            list-style: none;
        }

        .nav-item a {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.55rem 1rem;
            border-radius: var(--radius-md);
            font-size: 0.9375rem;
            font-weight: 500;
            color: var(--text-muted);
            text-decoration: none;
            transition: all 0.15s ease;
        }

        .nav-item a:hover {
            color: var(--primary);
            background-color: var(--surface-hover);
        }

        .nav-item a.active {
            color: var(--primary);
            background-color: var(--primary-light);
            font-weight: 600;
        }

        .nav-badge-future {
            background-color: #E0F2FE;
            color: #0369A1;
            font-size: 0.6875rem;
            padding: 2px 6px;
            border-radius: 4px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .nav-badge-present {
            background-color: #FEF3C7;
            color: #B45309;
            font-size: 0.6875rem;
            padding: 2px 6px;
            border-radius: 4px;
            font-weight: 700;
            text-transform: uppercase;
        }

        /* Ações e Usuário */
        .user-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .btn-new-schedule {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            background-color: var(--primary);
            color: #FFFFFF;
            font-size: 0.875rem;
            font-weight: 600;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.2s ease;
            box-shadow: 0 2px 6px rgba(14, 116, 144, 0.25);
        }

        .btn-new-schedule:hover {
            background-color: var(--primary-hover);
            transform: translateY(-1px);
        }

        .user-dropdown {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding-left: 0.5rem;
            border-left: 1px solid var(--border);
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            background-color: #E2E8F0;
            color: #475569;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.9375rem;
        }

        .user-info {
            display: flex;
            flex-direction: column;
            text-align: left;
        }

        .user-name {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--dark);
            line-height: 1.2;
        }

        .user-role {
            font-size: 0.75rem;
            color: var(--text-muted);
        }

        .logout-form {
            display: inline;
            margin: 0;
        }

        .btn-logout {
            background: none;
            border: 1px solid var(--border);
            color: var(--text-muted);
            padding: 0.375rem 0.75rem;
            border-radius: 7px;
            font-size: 0.8125rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.15s ease;
            font-family: var(--font-main);
        }

        .btn-logout:hover {
            background-color: var(--danger-bg);
            border-color: #FCA5A5;
            color: var(--danger);
        }

        /* Área de Conteúdo */
        .main-container {
            max-width: 1280px;
            width: 100%;
            margin: 0 auto;
            padding: 2rem 1.5rem;
            flex: 1;
        }

        /* Breadcrumbs */
        .breadcrumbs {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
            color: var(--text-muted);
            margin-bottom: 1.5rem;
        }

        .breadcrumbs a {
            color: var(--text-muted);
            text-decoration: none;
        }

        .breadcrumbs a:hover {
            color: var(--primary);
        }

        .breadcrumbs span.current {
            color: var(--dark);
            font-weight: 600;
        }

        /* Rodapé Interno */
        .admin-footer {
            background-color: var(--surface);
            border-top: 1px solid var(--border);
            padding: 1.25rem 1.5rem;
            font-size: 0.8125rem;
            color: var(--text-muted);
            text-align: center;
        }

        .admin-footer strong {
            color: var(--dark);
        }

        /* Responsividade Mobile */
        .mobile-nav-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--dark);
            cursor: pointer;
        }

        @media (max-width: 900px) {
            .nav-menu {
                display: none;
            }
            .mobile-nav-toggle {
                display: block;
            }
            .user-info {
                display: none;
            }
            .topbar-inner {
                padding: 0 1rem;
            }
            .main-container {
                padding: 1.25rem 1rem;
            }
        }
    </style>
</head>
<body>

<header class="topbar">
    <div class="topbar-inner">
        <a href="/diario" class="brand-area" title="Painel Operacional Futuro Fácil">
            <div class="brand-logo">
                <div class="brand-icon">F</div>
                <div class="brand-text">
                    <span class="brand-name">Futuro<span>Fácil</span></span>
                    <span class="brand-tag">Gestão Pedagógica</span>
                </div>
            </div>
        </a>

        <nav>
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="/diario" class="<?= $activeNav === 'inicio' ? 'active' : '' ?>">
                        <span>Visão Geral</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/diario/calendario" class="<?= $activeNav === 'calendario' ? 'active' : '' ?>">
                        <span>Calendário</span>
                        <span class="nav-badge-future">Futuro</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/diario/turmas" class="<?= $activeNav === 'turmas' ? 'active' : '' ?>">
                        <span>Diário & Turmas</span>
                        <span class="nav-badge-present">Ativo</span>
                    </a>
                </li>
            </ul>
        </nav>

        <div class="user-actions">
            <a href="/diario/turmas/novo" class="btn-new-schedule" title="Criar nova turma ou aula">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                <span>Novo Agendamento</span>
            </a>

            <div class="user-dropdown">
                <div class="user-avatar" title="<?= $userEmail ?>"><?= $userInitial ?></div>
                <div class="user-info">
                    <span class="user-name"><?= $userName ?></span>
                    <span class="user-role">Operador Institucional</span>
                </div>
                <form action="/diario/logout" method="POST" class="logout-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" class="btn-logout" title="Encerrar sessão de forma segura">Sair</button>
                </form>
            </div>
        </div>
    </div>
</header>

<main class="main-container">
    <?php if (!empty($breadcrumbs)): ?>
        <nav class="breadcrumbs" aria-label="Navegação estrutural">
            <?php foreach ($breadcrumbs as $label => $url): ?>
                <?php if ($url): ?>
                    <a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
                    <span>/</span>
                <?php else: ?>
                    <span class="current"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <?= $contentHtml ?>
</main>

<footer class="admin-footer">
    <p><strong>Futuro Fácil</strong> — Plataforma Integrada de Gestão Pedagógica, Diário de Classe e Certificados. Ambiente Local-First Protegido.</p>
</footer>

</body>
</html>
    <?php
}
