<?php
/**
 * Aba Canônica 3: Certificados (O Passado) — Painel Administrativo (/diario/certificados)
 * Gestão do Livro de Registro Digital, auditoria de assentos e exportação oficial
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/Database.php';
require_once __DIR__ . '/../../src/Services/AuthService.php';
require_once __DIR__ . '/../../src/Services/CertificateService.php';
require_once __DIR__ . '/../../src/Views/layout_admin.php';

use FuturoFacil\Config\Database;
use FuturoFacil\Services\AuthService;
use FuturoFacil\Services\CertificateService;
use function FuturoFacil\Views\renderAdminLayout;

if (!headers_sent()) {
    header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
    header('Content-Type: text/html; charset=UTF-8');
}

$user = AuthService::requireAuth();
$pdo = Database::getConnection();

// Coleta registros recentes do Livro Digital
$totalCertificados = 0;
$registrosRecentes = [];

try {
    $stmtCount = $pdo->query("SELECT COUNT(*) FROM registros_certificados");
    $totalCertificados = (int)$stmtCount->fetchColumn();

    $stmt = $pdo->query("
        SELECT rc.*, t.codigo_turma, t.cliente_nome
        FROM registros_certificados rc
        LEFT JOIN turmas t ON t.id = rc.turma_id
        ORDER BY rc.id DESC
        LIMIT 50
    ");
    $registrosRecentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $registrosRecentes = [];
}

ob_start();
?>
<div style="margin-bottom: 2rem;">
    <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.5rem;">
        <span style="background: #F0FDF4; color: #166534; font-size: 0.75rem; font-weight: 700; padding: 2px 8px; border-radius: 4px; text-transform: uppercase;">O Passado</span>
        <h1 style="font-size: 1.875rem; font-weight: 700; color: var(--ff-ink); letter-spacing: -0.5px;">Certificados & Livro Digital</h1>
    </div>
    <p style="color: var(--ff-ink-muted); font-size: 0.95rem;">
        Assentos digitais sequenciais, auditoria pública e emissão de certificados oficiais com tecnologia duplex e guilloché.
    </p>
</div>

<!-- Métricas do Livro -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.25rem; margin-bottom: 2rem;">
    <div style="background: var(--ff-white); border: 1px solid var(--ff-line); border-radius: var(--ff-radius-md); padding: 1.25rem; box-shadow: var(--ff-shadow-sm);">
        <span style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--ff-ink-muted);">Total de Certificados</span>
        <div style="font-size: 2rem; font-weight: 700; color: var(--ff-cyan); margin: 0.35rem 0;"><?= $totalCertificados ?></div>
        <p style="font-size: 0.8125rem; color: var(--ff-ink-muted);">Registros com fé pública digital</p>
    </div>

    <div style="background: var(--ff-white); border: 1px solid var(--ff-line); border-radius: var(--ff-radius-md); padding: 1.25rem; box-shadow: var(--ff-shadow-sm);">
        <span style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--ff-ink-muted);">Validação Pública</span>
        <div style="font-size: 2rem; font-weight: 700; color: var(--ff-success); margin: 0.35rem 0;">Ativa</div>
        <p style="font-size: 0.8125rem; color: var(--ff-ink-muted);">QR Code e consulta instantânea</p>
    </div>

    <div style="background: var(--ff-white); border: 1px solid var(--ff-line); border-radius: var(--ff-radius-md); padding: 1.25rem; box-shadow: var(--ff-shadow-sm); display: flex; flex-direction: column; justify-content: center;">
        <a href="/validar" target="_blank" style="display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; background: var(--ff-paper); border: 1px solid var(--ff-line); color: var(--ff-cyan); padding: 0.65rem 1rem; border-radius: 8px; font-weight: 600; font-size: 0.875rem; text-decoration: none;">
            <span>Abrir Validador Público</span>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
        </a>
    </div>
</div>

<!-- Tabela de Assentos Oficiais -->
<div style="background: var(--ff-white); border: 1px solid var(--ff-line); border-radius: var(--ff-radius-lg); overflow: hidden; box-shadow: var(--ff-shadow-sm);">
    <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--ff-line); display: flex; justify-content: space-between; align-items: center;">
        <h3 style="font-size: 1.05rem; font-weight: 700; color: var(--ff-ink); margin: 0;">Últimos Assentos Registrados no Livro</h3>
        <span style="font-size: 0.8125rem; color: var(--ff-ink-muted);">Exibindo até 50 registros</span>
    </div>

    <?php if (empty($registrosRecentes)): ?>
        <div style="padding: 3rem 1.5rem; text-align: center; color: var(--ff-ink-muted);">
            Nenhum certificado emitido até o momento. Acesse a aba <strong>Diário</strong> e conclua o Fechamento Assistido de uma turma para gerar registros.
        </div>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem; text-align: left;">
                <thead>
                    <tr style="background: var(--ff-paper); border-bottom: 1px solid var(--ff-line); color: var(--ff-ink-muted); font-size: 0.75rem; text-transform: uppercase;">
                        <th style="padding: 0.75rem 1rem;">Assento</th>
                        <th style="padding: 0.75rem 1rem;">Aluno(a)</th>
                        <th style="padding: 0.75rem 1rem;">CPF</th>
                        <th style="padding: 0.75rem 1rem;">Curso / Turma</th>
                        <th style="padding: 0.75rem 1rem;">Emissão</th>
                        <th style="padding: 0.75rem 1rem;">Código SHA-256</th>
                        <th style="padding: 0.75rem 1rem; text-align: right;">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($registrosRecentes as $reg): ?>
                        <tr style="border-bottom: 1px solid var(--ff-line); transition: background 0.15s ease;">
                            <td style="padding: 0.85rem 1rem; font-family: var(--ff-font-mono); font-weight: 600; color: var(--ff-cyan);">
                                L.<?= (int)$reg['livro_numero'] ?> / F.<?= (int)$reg['folha_numero'] ?> / R.<?= (int)$reg['registro_numero'] ?>
                            </td>
                            <td style="padding: 0.85rem 1rem; font-weight: 600; color: var(--ff-ink);">
                                <?= htmlspecialchars($reg['aluno_nome'], ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td style="padding: 0.85rem 1rem; font-family: var(--ff-font-mono); color: var(--ff-ink-muted);">
                                <?= htmlspecialchars($reg['aluno_cpf'], ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td style="padding: 0.85rem 1rem; color: var(--ff-ink);">
                                <?= htmlspecialchars($reg['curso_nome'], ENT_QUOTES, 'UTF-8') ?>
                                <span style="font-size: 0.75rem; color: var(--ff-ink-muted); display: block;"><?= htmlspecialchars($reg['codigo_turma'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                            </td>
                            <td style="padding: 0.85rem 1rem; color: var(--ff-ink-muted);">
                                <?= htmlspecialchars($reg['data_emissao'], ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td style="padding: 0.85rem 1rem;">
                                <code style="font-family: var(--ff-font-mono); font-size: 0.75rem; background: var(--ff-paper); padding: 2px 6px; border-radius: 4px; color: var(--ff-ink);">
                                    <?= substr($reg['codigo_autenticidade'], 0, 12) ?>...
                                </code>
                            </td>
                            <td style="padding: 0.85rem 1rem; text-align: right;">
                                <a href="/validar?validar=<?= htmlspecialchars($reg['codigo_autenticidade'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" style="color: var(--ff-cyan); font-weight: 600; text-decoration: none; font-size: 0.8125rem;">
                                    Verificar
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php
$contentHtml = ob_get_clean();

renderAdminLayout(
    title: 'Certificados & Livro Digital',
    activeNav: 'certificados',
    contentHtml: $contentHtml,
    user: $user,
    breadcrumbs: ['Painel' => '/diario', 'Certificados' => null]
);
