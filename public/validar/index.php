<?php
/**
 * Validador Público de Autenticidade de Certificados
 * Plataforma Integrada de Gestão Pedagógica, Diário e Certificados Futuro Fácil
 * 
 * Hostinger Web Root: /validar/index.php
 * Suporta parâmetro via GET (?validar=<HASH> ou ?codigo=<HASH>) ou submissão via POST
 */

declare(strict_types=1);

// Cabeçalhos de Segurança
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:;");

require_once __DIR__ . '/../../src/Config/Database.php';
require_once __DIR__ . '/../../src/Services/ValidatorService.php';

use FuturoFacil\Config\Database;
use FuturoFacil\Services\ValidatorService;

// Captura do código enviado
$rawCode = $_GET['validar'] ?? $_GET['codigo'] ?? $_POST['codigo'] ?? null;
$cleanCode = ValidatorService::cleanAuthCode($rawCode);

$service = new ValidatorService();
$resultado = null;

if (!empty($cleanCode)) {
    $resultado = $service->validarCodigo($cleanCode);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Validador de Autenticidade de Certificados | Futuro Fácil</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --color-primary: #0284C7;
            --color-primary-dark: #0369A1;
            --color-primary-deep: #075985;
            --color-secondary: #0F172A;
            --color-accent: #0D9488;
            --color-success: #10B981;
            --color-success-bg: #ECFDF5;
            --color-success-border: #A7F3D0;
            --color-error: #EF4444;
            --color-error-bg: #FEF2F2;
            --color-error-border: #FECACA;
            --color-warning: #F59E0B;
            --color-bg: #F8FAFC;
            --color-card-bg: #FFFFFF;
            --color-text-main: #1E293B;
            --color-text-muted: #64748B;
            --color-border: #E2E8F0;
            --radius-md: 10px;
            --radius-lg: 16px;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.07), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.08), 0 4px 6px -4px rgba(0, 0, 0, 0.04);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: var(--color-bg);
            color: var(--color-text-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            line-height: 1.5;
        }

        header {
            background-color: #FFFFFF;
            border-bottom: 1px solid var(--color-border);
            padding: 1rem 1.5rem;
            box-shadow: var(--shadow-sm);
        }

        .header-container {
            max-width: 900px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            text-decoration: none;
            color: inherit;
        }

        .brand-logo {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, var(--color-primary), var(--color-accent));
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #FFFFFF;
            font-weight: 800;
            font-size: 1.25rem;
        }

        .brand-text h1 {
            font-size: 1.15rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            color: var(--color-secondary);
        }

        .brand-text p {
            font-size: 0.75rem;
            color: var(--color-primary);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        main {
            flex: 1;
            max-width: 800px;
            width: 100%;
            margin: 2.5rem auto;
            padding: 0 1rem;
        }

        .search-card {
            background-color: var(--color-card-bg);
            border-radius: var(--radius-lg);
            border: 1px solid var(--color-border);
            padding: 1.75rem;
            box-shadow: var(--shadow-md);
            margin-bottom: 2rem;
        }

        .search-header {
            margin-bottom: 1.25rem;
        }

        .search-header h2 {
            font-size: 1.35rem;
            font-weight: 700;
            color: var(--color-secondary);
            margin-bottom: 0.35rem;
        }

        .search-header p {
            color: var(--color-text-muted);
            font-size: 0.92rem;
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
            border: 1.5px solid var(--color-border);
            border-radius: var(--radius-md);
            font-size: 0.95rem;
            font-family: 'JetBrains Mono', monospace;
            color: var(--color-secondary);
            transition: all 0.2s ease;
            background-color: #F8FAFC;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--color-primary);
            background-color: #FFFFFF;
            box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.15);
        }

        .btn-submit {
            background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
            color: #FFFFFF;
            border: none;
            border-radius: var(--radius-md);
            padding: 0.85rem 1.75rem;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: var(--shadow-sm);
        }

        .btn-submit:hover {
            opacity: 0.95;
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-submit:active {
            transform: translateY(0);
        }

        /* Card de Resultado */
        .result-card {
            background-color: var(--color-card-bg);
            border-radius: var(--radius-lg);
            border: 1px solid var(--color-border);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            animation: fadeIn 0.3s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .result-header {
            padding: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .result-header.success {
            background-color: var(--color-success-bg);
            border-bottom: 1px solid var(--color-success-border);
        }

        .result-header.error {
            background-color: var(--color-error-bg);
            border-bottom: 1px solid var(--color-error-border);
        }

        .status-icon {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            flex-shrink: 0;
        }

        .status-icon.success {
            background-color: var(--color-success);
            color: #FFFFFF;
        }

        .status-icon.error {
            background-color: var(--color-error);
            color: #FFFFFF;
        }

        .status-text h3 {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--color-secondary);
        }

        .status-text p {
            font-size: 0.88rem;
            color: var(--color-text-muted);
        }

        .result-body {
            padding: 1.75rem;
        }

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
            font-size: 0.78rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--color-text-muted);
        }

        .data-value {
            font-size: 1.05rem;
            font-weight: 600;
            color: var(--color-secondary);
        }

        .data-value.masked {
            font-family: 'JetBrains Mono', monospace;
            color: var(--color-primary-deep);
            background-color: #F1F5F9;
            padding: 0.2rem 0.5rem;
            border-radius: 6px;
            display: inline-block;
            width: fit-content;
            font-size: 0.95rem;
        }

        .auth-hash-box {
            background-color: #F8FAFC;
            border: 1px dashed var(--color-border);
            border-radius: var(--radius-md);
            padding: 1rem;
            margin-top: 1rem;
        }

        .auth-hash-box .label {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--color-text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.35rem;
        }

        .auth-hash-code {
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.85rem;
            color: var(--color-secondary);
            word-break: break-all;
            line-height: 1.4;
        }

        .lgpd-notice {
            margin-top: 1.5rem;
            padding: 0.85rem 1.15rem;
            background-color: #F8FAFC;
            border-left: 4px solid var(--color-primary);
            border-radius: 4px;
            font-size: 0.82rem;
            color: var(--color-text-muted);
            line-height: 1.5;
        }

        .empty-state {
            text-align: center;
            padding: 3rem 1.5rem;
            color: var(--color-text-muted);
        }

        .empty-state-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        footer {
            background-color: #FFFFFF;
            border-top: 1px solid var(--color-border);
            padding: 1.5rem;
            text-align: center;
            font-size: 0.82rem;
            color: var(--color-text-muted);
            margin-top: auto;
        }

        footer a {
            color: var(--color-primary);
            text-decoration: none;
            font-weight: 500;
        }

        footer a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>

    <header>
        <div class="header-container">
            <a href="/validar" class="brand">
                <div class="brand-logo">F</div>
                <div class="brand-text">
                    <h1>FUTURO FÁCIL</h1>
                    <p>Validação Pública de Autenticidade</p>
                </div>
            </a>
            <div style="font-size: 0.82rem; font-weight: 600; color: var(--color-accent); background: #F0FDFA; padding: 0.35rem 0.75rem; border-radius: 20px; border: 1px solid #CCFBF1;">
                Registro Digital Oficial
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
                    >
                </div>
                <button type="submit" class="btn-submit">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
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
                        <div class="status-icon success">✓</div>
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
                                <span class="data-value" style="font-size: 1.25rem; color: var(--color-primary-dark);"><?= htmlspecialchars($resultado['curso_nome']) ?></span>
                            </div>
                            <div class="data-item">
                                <span class="data-label">Carga Horária</span>
                                <span class="data-value"><?= htmlspecialchars((string)$resultado['carga_horaria']) ?> horas (<?= htmlspecialchars($resultado['carga_horaria_extenso']) ?>)</span>
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
                                <span class="data-value" style="font-weight: 700; color: var(--color-secondary);">
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
                <article class="result-card">
                    <div class="result-header error">
                        <div class="status-icon error">✕</div>
                        <div class="status-text">
                            <h3>Certificado Não Validado</h3>
                            <p><?= htmlspecialchars($resultado['mensagem']) ?></p>
                        </div>
                    </div>
                    <div class="result-body">
                        <p style="color: var(--color-text-muted); font-size: 0.95rem; margin-bottom: 1rem;">
                            O código de autenticidade pesquisado não pôde ser atestado. Certifique-se de que todos os 64 caracteres hexadecimais foram digitados exatamente como constam no documento, sem omissões.
                        </p>
                        <?php if (!empty($resultado['codigo'])): ?>
                            <div class="auth-hash-box" style="border-color: var(--color-error-border);">
                                <div class="label" style="color: var(--color-error);">Código Pesquisado</div>
                                <div class="auth-hash-code"><?= htmlspecialchars($resultado['codigo']) ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endif; ?>
        <?php else: ?>
            <div class="empty-state">
                <div class="empty-state-icon">🛡️</div>
                <h3 style="font-weight: 700; color: var(--color-secondary); margin-bottom: 0.5rem;">Pronto para Validação</h3>
                <p style="max-width: 480px; margin: 0 auto; font-size: 0.92rem;">
                    Aponte a câmera do seu smartphone para o QR Code impresso no verso do certificado ou insira a chave alfanumérica no campo de busca acima.
                </p>
            </div>
        <?php endif; ?>
    </main>

    <footer>
        <p>
            Plataforma Oficial de Certificação — <strong>Futuro Fácil Capacitação Profissional</strong><br>
            A autenticidade digital deste registro possui fé pública institucional conforme as normas de cursos livres.
        </p>
    </footer>

</body>
</html>
