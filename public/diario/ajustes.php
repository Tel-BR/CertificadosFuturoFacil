<?php
/**
 * Aba Canônica 4: Ajustes — Painel Administrativo (/diario/ajustes)
 * Configurações da plataforma, gestão de turmas, materiais e apoio à NFS-e
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/Database.php';
require_once __DIR__ . '/../../src/Services/AuthService.php';
require_once __DIR__ . '/../../src/Views/layout_admin.php';

use FuturoFacil\Config\Database;
use FuturoFacil\Services\AuthService;
use function FuturoFacil\Views\renderAdminLayout;

if (!headers_sent()) {
    header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
    header('Content-Type: text/html; charset=UTF-8');
}

$user = AuthService::requireAuth();
$pdo = Database::getConnection();

ob_start();
?>
<div style="margin-bottom: 2rem;">
    <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.5rem;">
        <span style="background: #E2E8F0; color: #334155; font-size: 0.75rem; font-weight: 700; padding: 2px 8px; border-radius: 4px; text-transform: uppercase;">Ajustes & Operações</span>
        <h1 style="font-size: 1.875rem; font-weight: 700; color: var(--ff-ink); letter-spacing: -0.5px;">Configurações da Plataforma</h1>
    </div>
    <p style="color: var(--ff-ink-muted); font-size: 0.95rem;">
        Controles operacionais de turmas, sincronização de dados e segurança de infraestrutura.
    </p>
</div>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.5rem;">
    <!-- 1. Gestão de Turmas e Materiais -->
    <div style="background: var(--ff-white); border: 1px solid var(--ff-line); border-radius: var(--ff-radius-lg); padding: 1.5rem; box-shadow: var(--ff-shadow-sm);">
        <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--ff-ink); margin-bottom: 0.5rem;">Gestão de Turmas & Materiais</h3>
        <p style="font-size: 0.875rem; color: var(--ff-ink-muted); line-height: 1.5; margin-bottom: 1.25rem;">
            Acesse o gerenciamento completo de turmas cadastradas, materiais didáticos protegidos e chaves de acesso dos alunos.
        </p>
        <a href="/diario/turmas" style="display: inline-flex; align-items: center; gap: 0.5rem; background: var(--ff-paper); border: 1px solid var(--ff-line); color: var(--ff-cyan); font-weight: 600; font-size: 0.875rem; padding: 0.6rem 1rem; border-radius: 8px; text-decoration: none;">
            <span>Gerenciar Turmas</span>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
        </a>
    </div>

    <!-- 2. Apoio à NFS-e e Fechamento -->
    <div style="background: var(--ff-white); border: 1px solid var(--ff-line); border-radius: var(--ff-radius-lg); padding: 1.5rem; box-shadow: var(--ff-shadow-sm);">
        <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--ff-ink); margin-bottom: 0.5rem;">Faturamento & Apoio à NFS-e</h3>
        <p style="font-size: 0.875rem; color: var(--ff-ink-muted); line-height: 1.5; margin-bottom: 1.25rem;">
            O fechamento assistido de turmas gera automaticamente a discriminação exata de datas e horas para emissão de Notas Fiscais de Serviço.
        </p>
        <a href="/diario/turmas" style="display: inline-flex; align-items: center; gap: 0.5rem; background: var(--ff-paper); border: 1px solid var(--ff-line); color: var(--ff-cyan); font-weight: 600; font-size: 0.875rem; padding: 0.6rem 1rem; border-radius: 8px; text-decoration: none;">
            <span>Abrir Fechamentos</span>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
        </a>
    </div>

    <!-- 3. Status de Infraestrutura e Blindagem -->
    <div style="background: var(--ff-white); border: 1px solid var(--ff-line); border-radius: var(--ff-radius-lg); padding: 1.5rem; box-shadow: var(--ff-shadow-sm);">
        <h3 style="font-size: 1.1rem; font-weight: 700; color: var(--ff-ink); margin-bottom: 0.5rem;">Blindagem & Segurança</h3>
        <p style="font-size: 0.875rem; color: var(--ff-ink-muted); line-height: 1.5; margin-bottom: 1rem;">
            Status das proteções ativas do sistema conforme as diretrizes do ADR-0005:
        </p>
        <ul style="list-style: none; font-size: 0.8125rem; color: var(--ff-ink); line-height: 1.8;">
            <li>✓ <strong>Cloudflare Turnstile:</strong> Integrado para alunos e validador</li>
            <li>✓ <strong>Rate-Limiting:</strong> 30 requisições/minuto no validador público</li>
            <li>✓ <strong>Desafio de Sobrenome:</strong> CAIXA ALTA na consulta de certificado</li>
            <li>✓ <strong>HTTPS Forçado:</strong> Redirecionamento 301 ativo no .htaccess</li>
            <li>✓ <strong>Isolamento de Arquivos:</strong> Proteção física HTTP 403 em storage</li>
        </ul>
    </div>
</div>
<?php
$contentHtml = ob_get_clean();

renderAdminLayout(
    title: 'Ajustes da Plataforma',
    activeNav: 'ajustes',
    contentHtml: $contentHtml,
    user: $user,
    breadcrumbs: ['Painel' => '/diario', 'Ajustes' => null]
);
