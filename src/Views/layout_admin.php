<?php
/**
 * Layout Base Administrativo da Plataforma Futuro Fácil
 * Design System Editorial e Experiência de Interface (ADR-0006 / Ticket UI 01)
 * 
 * Regras de Arquitetura e UX:
 * - Identidade Editorial: fundo papel marfim (#FAF7F1), texto carvão (#1B1918),
 *   Petróleo Tech (#0E7490), Coral Solar (#EA580C) e bordas nítidas de 1px (#E2DFDA).
 * - Tipografia: Ubuntu institucional (300, 400, 500, 700) e Monospace com font-display: swap (CLS < 0.1).
 * - Logotipo Oficial: vetor oficial logo.svg sem símbolos legados, com dimensões explícitas.
 * - Desktop (>= 768px): cabeçalho superior minimalista com as 4 abas canônicas em linha (100% largura útil).
 * - Mobile (< 768px): Bottom Navigation Bar fixa de 56px de altura na zona do polegar,
 *   com alvos táteis mínimos de 44x44px para as 4 abas canônicas: Calendário, Diário, Certificados e Ajustes.
 * - Sessão protegida: CSRF em logout, cookies HttpOnly e SameSite.
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

    // Normaliza a aba ativa entre os 4 termos canônicos
    $canonicalTab = match ($activeNav) {
        'calendario'              => 'calendario',
        'diario', 'turmas', 'aula'=> 'diario',
        'certificados', 'fechamento' => 'certificados',
        'ajustes'                 => 'ajustes',
        default                   => 'diario',
    };
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
            --ff-danger: #DC2626;
            --ff-danger-bg: #FEF2F2;
            --ff-danger-line: #FCA5A5;
            --ff-success: #16A34A;
            --ff-success-bg: #F0FDF4;
            --ff-warning: #D97706;
            --ff-warning-bg: #FFFBEB;

            /* Tipografia Oficial (CLS < 0.1) */
            --ff-font-main: 'Ubuntu', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            --ff-font-mono: 'JetBrains Mono', monospace;

            /* Disciplina Minimalista: bordas de 1px, sombras sutis */
            --ff-radius-sm: 6px;
            --ff-radius-md: 10px;
            --ff-radius-lg: 14px;
            --ff-shadow-sm: 0 1px 2px 0 rgba(27, 25, 24, 0.04);
            --ff-shadow-md: 0 4px 6px -1px rgba(27, 25, 24, 0.05), 0 2px 4px -2px rgba(27, 25, 24, 0.03);
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
            line-height: 1.5;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            letter-spacing: -0.01em;
            -webkit-font-smoothing: antialiased;
        }

        /* Topbar Desktop Institucional */
        .topbar {
            background-color: var(--ff-white);
            border-bottom: 1px solid var(--ff-line);
            height: 64px;
            position: sticky;
            top: 0;
            z-index: 100;
            display: flex;
            align-items: center;
            box-shadow: var(--ff-shadow-sm);
        }

        .topbar-inner {
            max-width: 1320px;
            width: 100%;
            margin: 0 auto;
            padding: 0 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1.5rem;
        }

        .brand-area {
            display: flex;
            align-items: center;
            gap: 1rem;
            text-decoration: none;
            color: var(--ff-ink);
            flex-shrink: 0;
        }

        .brand-logo-img {
            display: block;
            width: 150px;
            height: 30px;
            object-fit: contain;
            aspect-ratio: 320 / 64;
        }

        /* Navegação Horizontal Desktop (>= 768px) */
        .desktop-nav {
            display: flex;
            align-items: center;
            gap: 0.35rem;
            list-style: none;
        }

        .desktop-nav-item a {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 0.9rem;
            border-radius: var(--ff-radius-md);
            font-size: 0.9375rem;
            font-weight: 500;
            color: var(--ff-ink-muted);
            text-decoration: none;
            transition: all 0.15s ease;
        }

        .desktop-nav-item a:hover {
            color: var(--ff-cyan);
            background-color: var(--ff-paper);
        }

        .desktop-nav-item a.active {
            color: var(--ff-cyan);
            background-color: var(--ff-cyan-soft);
            font-weight: 700;
        }

        .desktop-nav-item .tab-tag {
            font-size: 0.6875rem;
            padding: 1px 5px;
            border-radius: 4px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .tag-future {
            background-color: #E0F2FE;
            color: #0369A1;
        }

        .tag-present {
            background-color: #FEF3C7;
            color: #B45309;
        }

        .tag-past {
            background-color: #F0FDF4;
            color: #166534;
        }

        /* Ações e Usuário no Topbar */
        .topbar-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-shrink: 0;
        }

        .btn-quick-action {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            background-color: var(--ff-cyan);
            color: #FFFFFF;
            font-size: 0.8125rem;
            font-weight: 600;
            padding: 0.45rem 0.85rem;
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.15s ease;
        }

        .btn-quick-action:hover {
            background-color: var(--ff-cyan-hover);
        }

        .user-block {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding-left: 0.75rem;
            border-left: 1px solid var(--ff-line);
        }

        .user-avatar {
            width: 34px;
            height: 34px;
            background-color: var(--ff-paper-2);
            color: var(--ff-cyan);
            border: 1px solid var(--ff-line);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.875rem;
        }

        .user-details {
            display: flex;
            flex-direction: column;
            text-align: left;
        }

        .user-details-name {
            font-size: 0.8125rem;
            font-weight: 700;
            color: var(--ff-ink);
            line-height: 1.2;
        }

        .user-details-role {
            font-size: 0.6875rem;
            color: var(--ff-ink-muted);
        }

        .logout-form {
            display: inline;
            margin: 0;
        }

        .btn-logout {
            background: var(--ff-white);
            border: 1px solid var(--ff-line);
            color: var(--ff-ink-muted);
            padding: 0.35rem 0.7rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
            font-family: var(--ff-font-main);
        }

        .btn-logout:hover {
            background-color: var(--ff-danger-bg);
            border-color: var(--ff-danger-line);
            color: var(--ff-danger);
        }

        /* Área Central de Conteúdo */
        .main-container {
            max-width: 1320px;
            width: 100%;
            margin: 0 auto;
            padding: 2rem 1.5rem;
            flex: 1;
        }

        /* Breadcrumbs Estruturais */
        .breadcrumbs {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            font-size: 0.8125rem;
            color: var(--ff-ink-muted);
            margin-bottom: 1.5rem;
        }

        .breadcrumbs a {
            color: var(--ff-ink-muted);
            text-decoration: none;
        }

        .breadcrumbs a:hover {
            color: var(--ff-cyan);
        }

        .breadcrumbs span.current {
            color: var(--ff-ink);
            font-weight: 700;
        }

        /* Rodapé Institucional */
        .admin-footer {
            background-color: var(--ff-white);
            border-top: 1px solid var(--ff-line);
            padding: 1.25rem 1.5rem;
            font-size: 0.8125rem;
            color: var(--ff-ink-muted);
            text-align: center;
        }

        .admin-footer strong {
            color: var(--ff-ink);
        }

        /* ===================================================================== */
        /* MOBILE BOTTOM NAVIGATION BAR (< 768px)                                */
        /* ===================================================================== */
        .bottom-nav {
            display: none;
        }

        @media (max-width: 767px) {
            /* No smartphone, o cabeçalho superior é ultra-enxuto */
            .desktop-nav, .user-details, .btn-quick-action {
                display: none;
            }

            .topbar {
                height: 56px;
            }

            .topbar-inner {
                padding: 0 1rem;
            }

            .brand-logo-img {
                width: 130px;
                height: 26px;
            }

            .main-container {
                padding: 1.25rem 1rem calc(56px + 1.5rem);
            }

            .admin-footer {
                padding-bottom: calc(56px + 1.25rem);
            }

            /* Barra de Navegação Inferior Fixa na Zona do Polegar */
            .bottom-nav {
                display: flex;
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                height: 56px;
                background-color: var(--ff-white);
                border-top: 1px solid var(--ff-line);
                z-index: 1000;
                align-items: center;
                justify-content: space-around;
                box-shadow: 0 -2px 10px rgba(27, 25, 24, 0.05);
            }

            .bottom-nav-item {
                flex: 1;
                height: 100%;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 2px;
                text-decoration: none;
                color: var(--ff-ink-muted);
                min-width: 44px;
                min-height: 44px;
                font-size: 0.6875rem;
                font-weight: 500;
                transition: all 0.15s ease;
                user-select: none;
                -webkit-tap-highlight-color: transparent;
            }

            .bottom-nav-item svg {
                width: 20px;
                height: 20px;
                transition: transform 0.15s ease;
            }

            .bottom-nav-item.active {
                color: var(--ff-cyan);
                font-weight: 700;
            }

            .bottom-nav-item.active svg {
                stroke-width: 2.5;
                transform: scale(1.08);
            }

            .bottom-nav-item:active {
                background-color: var(--ff-paper);
            }
        }
    </style>
</head>
<body>

<!-- Cabeçalho Superior Desktop & Mobile -->
<header class="topbar">
    <div class="topbar-inner">
        <a href="/diario" class="brand-area" title="Futuro Fácil — Painel de Gestão Pedagógica">
            <img src="/assets/logo.svg" alt="Futuro Fácil" width="150" height="30" class="brand-logo-img">
        </a>

        <!-- Navegação Principal Desktop (As 4 Abas Canônicas) -->
        <nav aria-label="Navegação Principal Desktop">
            <ul class="desktop-nav">
                <li class="desktop-nav-item">
                    <a href="/diario/calendario" class="<?= $canonicalTab === 'calendario' ? 'active' : '' ?>">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                        <span>Calendário</span>
                        <span class="tab-tag tag-future">Futuro</span>
                    </a>
                </li>
                <li class="desktop-nav-item">
                    <a href="/diario/turmas" class="<?= $canonicalTab === 'diario' ? 'active' : '' ?>">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
                        <span>Diário</span>
                        <span class="tab-tag tag-present">Presente</span>
                    </a>
                </li>
                <li class="desktop-nav-item">
                    <a href="/diario/certificados" class="<?= $canonicalTab === 'certificados' ? 'active' : '' ?>">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="7"></circle><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"></polyline></svg>
                        <span>Certificados</span>
                        <span class="tab-tag tag-past">Passado</span>
                    </a>
                </li>
                <li class="desktop-nav-item">
                    <a href="/diario/ajustes" class="<?= $canonicalTab === 'ajustes' ? 'active' : '' ?>">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                        <span>Ajustes</span>
                    </a>
                </li>
            </ul>
        </nav>

        <!-- Ações do Topbar & Perfil -->
        <div class="topbar-actions">
            <a href="/diario/turmas/novo" class="btn-quick-action" title="Agendar nova turma">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                <span>Novo Agendamento</span>
            </a>

            <div class="user-block">
                <div class="user-avatar" title="<?= $userEmail ?>"><?= $userInitial ?></div>
                <div class="user-details">
                    <span class="user-details-name"><?= $userName ?></span>
                    <span class="user-details-role">Operador</span>
                </div>
                <form action="/diario/logout" method="POST" class="logout-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" class="btn-logout" title="Encerrar sessão de forma segura">Sair</button>
                </form>
            </div>
        </div>
    </div>
</header>

<!-- Conteúdo Principal da Página -->
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

<!-- Rodapé do Sistema -->
<footer class="admin-footer">
    <p><strong>Futuro Fácil</strong> — Plataforma Integrada de Gestão Pedagógica, Diário de Classe e Certificados. Ambiente Local-First Protegido.</p>
</footer>

<!-- ===================================================================== -->
<!-- BARRA DE NAVEGAÇÃO INFERIOR FIXA NO SMARTPHONE (< 768px)               -->
<!-- ===================================================================== -->
<nav class="bottom-nav" aria-label="Navegação Móvel do Polegar">
    <!-- 1. Calendário (O Futuro) -->
    <a href="/diario/calendario" class="bottom-nav-item <?= $canonicalTab === 'calendario' ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
            <line x1="16" y1="2" x2="16" y2="6"></line>
            <line x1="8" y1="2" x2="8" y2="6"></line>
            <line x1="3" y1="10" x2="21" y2="10"></line>
        </svg>
        <span>Calendário</span>
    </a>

    <!-- 2. Diário (O Presente) -->
    <a href="/diario/turmas" class="bottom-nav-item <?= $canonicalTab === 'diario' ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path>
            <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>
        </svg>
        <span>Diário</span>
    </a>

    <!-- 3. Certificados (O Passado) -->
    <a href="/diario/certificados" class="bottom-nav-item <?= $canonicalTab === 'certificados' ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="8" r="7"></circle>
            <polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"></polyline>
        </svg>
        <span>Certificados</span>
    </a>

    <!-- 4. Ajustes -->
    <a href="/diario/ajustes" class="bottom-nav-item <?= $canonicalTab === 'ajustes' ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="3"></circle>
            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
        </svg>
        <span>Ajustes</span>
    </a>
</nav>

</body>
</html>
    <?php
}
