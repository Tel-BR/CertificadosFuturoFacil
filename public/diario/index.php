<?php
/**
 * Painel Administrativo Base (/diario)
 * Casca de navegação unificada para as três dimensões pedagógicas da Futuro Fácil
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/Database.php';
require_once __DIR__ . '/../../src/Services/AuthService.php';
require_once __DIR__ . '/../../src/Views/layout_admin.php';

use FuturoFacil\Config\Database;
use FuturoFacil\Services\AuthService;
use function FuturoFacil\Views\renderAdminLayout;

// Garante cabeçalho anti-indexação
if (!headers_sent()) {
    header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
    header('Content-Type: text/html; charset=UTF-8');
}

// Despacho inteligente para servidor de desenvolvimento (php -S sem mod_rewrite)
$reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
if (preg_match('#^/diario/([a-zA-Z0-9_-]+)/?$#', $reqPath, $matches)) {
    $targetSlug = $matches[1];
    if ($targetSlug !== 'index') {
        $targetFile = __DIR__ . '/' . $targetSlug . '.php';
        if (file_exists($targetFile)) {
            require $targetFile;
            exit;
        }
    }
}

// Exige autenticação estrita
$user = AuthService::requireAuth();

// Coleta métricas operacionais reais do banco
$totalCertificados = 0;
$totalTurmas = 0;
$totalAlunos = 0;

try {
    $pdo = Database::getConnection();

    $stmtReg = $pdo->query("SELECT COUNT(*) FROM registros_certificados");
    $totalCertificados = (int)$stmtReg->fetchColumn();

    $stmtTurmas = $pdo->query("SELECT COUNT(*) FROM turmas");
    $totalTurmas = (int)$stmtTurmas->fetchColumn();

    $stmtAlunos = $pdo->query("SELECT COUNT(*) FROM alunos");
    $totalAlunos = (int)$stmtAlunos->fetchColumn();
} catch (Exception $e) {
    // Continua com zeros caso banco ainda não tenha registros
}

// Monta o conteúdo HTML da página inicial
ob_start();
?>
<div class="dashboard-header" style="margin-bottom: 2rem;">
    <h1 style="font-size: 1.875rem; font-weight: 700; color: var(--dark); letter-spacing: -0.5px;">Painel de Gestão Pedagógica</h1>
    <p style="color: var(--text-muted); font-size: 1rem; margin-top: 0.25rem;">
        Bem-vindo(a), <strong><?= htmlspecialchars($user['nome'], ENT_QUOTES, 'UTF-8') ?></strong>. Controle as 3 dimensões temporais da rotina da Futuro Fácil.
    </p>
</div>

<!-- Grade de Métricas Rápidas -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1.25rem; margin-bottom: 2.5rem;">
    <div style="background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 1.5rem; box-shadow: var(--shadow-sm);">
        <div style="display: flex; align-items: center; justify-content: space-between;">
            <span style="font-size: 0.8125rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted);">Certificados Emitidos</span>
            <span style="background: #ECFEFF; color: #0E7490; padding: 4px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 700;">Livro Digital</span>
        </div>
        <div style="font-size: 2.25rem; font-weight: 700; color: var(--dark); margin: 0.5rem 0 0.25rem;"><?= $totalCertificados ?></div>
        <p style="font-size: 0.8125rem; color: var(--text-muted);">Assentos registrados e auditáveis</p>
    </div>

    <div style="background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 1.5rem; box-shadow: var(--shadow-sm);">
        <div style="display: flex; align-items: center; justify-content: space-between;">
            <span style="font-size: 0.8125rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted);">Turmas Cadastradas</span>
            <span style="background: #FEF3C7; color: #B45309; padding: 4px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 700;">Capacidade</span>
        </div>
        <div style="font-size: 2.25rem; font-weight: 700; color: var(--dark); margin: 0.5rem 0 0.25rem;"><?= $totalTurmas ?></div>
        <p style="font-size: 0.8125rem; color: var(--text-muted);">Histórico, em andamento e previstas</p>
    </div>

    <div style="background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 1.5rem; box-shadow: var(--shadow-sm);">
        <div style="display: flex; align-items: center; justify-content: space-between;">
            <span style="font-size: 0.8125rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted);">Alunos Registrados</span>
            <span style="background: #F0FDF4; color: #16A34A; padding: 4px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 700;">LGPD Ok</span>
        </div>
        <div style="font-size: 2.25rem; font-weight: 700; color: var(--dark); margin: 0.5rem 0 0.25rem;"><?= $totalAlunos ?></div>
        <p style="font-size: 0.8125rem; color: var(--text-muted);">Integridade cadastral e presenças</p>
    </div>
</div>

<!-- As 3 Dimensões Temporais -->
<h2 style="font-size: 1.25rem; font-weight: 700; color: var(--dark); margin-bottom: 1rem;">Módulos Operacionais</h2>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.5rem;">
    <!-- 1. Futuro -->
    <div style="background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 1.75rem; display: flex; flex-direction: column; justify-content: space-between; box-shadow: var(--shadow-sm);">
        <div>
            <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1rem;">
                <span style="background: #E0F2FE; color: #0369A1; font-weight: 700; font-size: 0.75rem; padding: 4px 8px; border-radius: 6px; text-transform: uppercase;">O Futuro</span>
                <h3 style="font-size: 1.125rem; font-weight: 700; color: var(--dark);">Calendário de Capacidade</h3>
            </div>
            <p style="color: var(--text-muted); font-size: 0.9375rem; line-height: 1.5; margin-bottom: 1.5rem;">
                Visão anual e mensal com turnos independentes (<strong>[M]</strong>, <strong>[V]</strong>, <strong>[N]</strong>, <strong>[D]</strong>), alto contraste para daltônicos, prevenção de choques de horário e rollovers informativos.
            </p>
        </div>
        <a href="/diario/calendario" style="display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; background: var(--bg); border: 1px solid var(--border); color: var(--primary); font-weight: 600; font-size: 0.875rem; padding: 0.75rem; border-radius: 8px; text-decoration: none; transition: all 0.2s ease;">
            <span>Acessar Calendário</span>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
        </a>
    </div>

    <!-- 2. Presente -->
    <div style="background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 1.75rem; display: flex; flex-direction: column; justify-content: space-between; box-shadow: var(--shadow-sm);">
        <div>
            <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1rem;">
                <span style="background: #FEF3C7; color: #B45309; font-weight: 700; font-size: 0.75rem; padding: 4px 8px; border-radius: 6px; text-transform: uppercase;">O Presente</span>
                <h3 style="font-size: 1.125rem; font-weight: 700; color: var(--dark);">Diário de Classe Mobile</h3>
            </div>
            <p style="color: var(--text-muted); font-size: 0.9375rem; line-height: 1.5; margin-bottom: 1.5rem;">
                Modo Aula otimizado para smartphone em sala de aula, chamada ágil com 1 toque, plano dinâmico editável em tempo real e sincronização bidirecional em Excel.
            </p>
        </div>
        <a href="/diario/turmas" style="display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; background: var(--bg); border: 1px solid var(--border); color: var(--primary); font-weight: 600; font-size: 0.875rem; padding: 0.75rem; border-radius: 8px; text-decoration: none; transition: all 0.2s ease;">
            <span>Abrir Modo Aula & Chamada</span>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
        </a>
    </div>

    <!-- 3. Passado -->
    <div style="background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 1.75rem; display: flex; flex-direction: column; justify-content: space-between; box-shadow: var(--shadow-sm);">
        <div>
            <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1rem;">
                <span style="background: #F0FDF4; color: #16A34A; font-weight: 700; font-size: 0.75rem; padding: 4px 8px; border-radius: 6px; text-transform: uppercase;">O Passado</span>
                <h3 style="font-size: 1.125rem; font-weight: 700; color: var(--dark);">Fechamento & Certificação</h3>
            </div>
            <p style="color: var(--text-muted); font-size: 0.9375rem; line-height: 1.5; margin-bottom: 1.5rem;">
                Auditoria assistida com abono coletivo, emissão em lote de certificados duplex com guilloché e QR Code, Livro de Registro sequencial e apoio a faturamento NFS-e.
            </p>
        </div>
        <a href="/diario/turmas" style="display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; background: var(--bg); border: 1px solid var(--border); color: var(--primary); font-weight: 600; font-size: 0.875rem; padding: 0.75rem; border-radius: 8px; text-decoration: none; transition: all 0.2s ease;">
            <span>Consultar Fechamentos</span>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
        </a>
    </div>
</div>
<?php
$contentHtml = ob_get_clean();

// Renderiza layout base
renderAdminLayout(
    title: 'Painel Geral',
    activeNav: 'inicio',
    contentHtml: $contentHtml,
    user: $user,
    breadcrumbs: ['Painel' => '/diario', 'Visão Geral' => null]
);

