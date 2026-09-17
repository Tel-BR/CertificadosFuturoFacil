<?php
/**
 * Portal do Aluno — Área Protegida de Conteúdo e Materiais Didáticos (/turmas)
 * Plataforma Integrada de Gestão Pedagógica, Diário e Certificados Futuro Fácil
 * 
 * Regras de Negócio e Segurança (ADR-0008 / Ticket 07):
 * - Autenticação por Chave de Acesso da Turma com suporte a auto-login (?chave=...)
 * - Estética refinada inspirada na identidade visual Cooabriel (Ubuntu, warm paper, tons institucionais)
 * - Blindagem física de arquivos com download mediado por download.php (Costura de Teste 2)
 * - Suporte a players/leitores expansíveis para YouTube, Google Forms e Microsoft Forms
 * - Canal de suporte direto ao aluno via WhatsApp institucional e e-mail
 * - Gestão dinâmica de certificados (nenhum, entregue à coordenação ou download direto por CPF)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/Database.php';
require_once __DIR__ . '/../../src/Services/AuthService.php';
require_once __DIR__ . '/../../src/Services/MaterialService.php';

use FuturoFacil\Config\Database;
use FuturoFacil\Services\AuthService;
use FuturoFacil\Services\MaterialService;

AuthService::startSecureSession();

$pdo = Database::getConnection();
$authService = new AuthService($pdo);
$materialService = new MaterialService($pdo);

$turmaSlug = isset($_GET['turma']) ? trim((string)$_GET['turma']) : null;
$chaveUrl = isset($_GET['chave']) ? trim((string)$_GET['chave']) : null;

// Contexto preliminar se houver slug na URL
$turmaContexto = null;
if ($turmaSlug !== null && $turmaSlug !== '') {
    $turmaContexto = $materialService->getTurmaBySlugOrChave($turmaSlug);
}

// -------------------------------------------------------------
// 1. Processamento de Auto-login via parâmetro na URL (?chave=...)
// -------------------------------------------------------------
if (!empty($chaveUrl)) {
    $loginResult = $authService->authenticateStudent($chaveUrl, $turmaSlug);
    if ($loginResult['success']) {
        // Redireciona para URL limpa sem expor a chave na barra de navegação
        $cleanPath = '/turmas' . ($turmaSlug ? '/' . urlencode($turmaSlug) : '');
        header("Location: {$cleanPath}");
        exit;
    }
}

// -------------------------------------------------------------
// 2. Processamento do Formulário de Autenticação (POST)
// -------------------------------------------------------------
$loginError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $chaveDigitada = trim((string)($_POST['chave_acesso'] ?? ''));
    $loginResult = $authService->authenticateStudent($chaveDigitada, $turmaSlug);

    if ($loginResult['success']) {
        $cleanPath = '/turmas' . ($turmaSlug ? '/' . urlencode($turmaSlug) : '');
        header("Location: {$cleanPath}");
        exit;
    } else {
        $loginError = $loginResult['error'] ?? 'Chave de acesso incorreta.';
    }
}

// -------------------------------------------------------------
// 3. Verificação de Sessão do Aluno
// -------------------------------------------------------------
$isAlunoLogado = AuthService::isStudentAuthenticated();
$alunoData = $isAlunoLogado ? AuthService::getAuthenticatedStudentTurma() : null;

// Se há slug na URL e aluno logado em outra turma, permite que ele veja a turma logada ou troque
$turma = null;
if ($isAlunoLogado && $alunoData) {
    $turmaId = (int)$alunoData['turma_id'];
    $stmtTurma = $pdo->prepare("SELECT * FROM turmas WHERE id = ?");
    $stmtTurma->execute([$turmaId]);
    $turma = $stmtTurma->fetch(PDO::FETCH_ASSOC);
}

// -------------------------------------------------------------
// 4. Consulta de Certificado Individual por CPF (se habilitado)
// -------------------------------------------------------------
$cpfConsultaFeedback = null;
$certificadoIndividual = null;
if ($isAlunoLogado && $turma && ($turma['portal_certificados_modo'] ?? 'nenhum') === 'download_direto') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'consultar_cpf') {
        $cpfDigitado = preg_replace('/\D/', '', (string)($_POST['cpf'] ?? ''));
        if (strlen($cpfDigitado) !== 11) {
            $cpfConsultaFeedback = ['tipo' => 'danger', 'msg' => 'Por favor, informe um CPF válido com 11 dígitos.'];
        } else {
            // Busca certificado no banco
            $stmtCert = $pdo->prepare("
                SELECT rc.* 
                FROM registros_certificados rc
                INNER JOIN alunos a ON (a.cpf_limpo = :cpf AND a.turma_id = :turma_id)
                WHERE rc.turma_id = :turma_id AND (rc.aluno_cpf LIKE :cpf_like OR rc.aluno_nome = a.nome_completo)
                ORDER BY rc.id DESC 
                LIMIT 1
            ");
            $stmtCert->execute([
                'cpf'       => $cpfDigitado,
                'turma_id'  => $turma['id'],
                'cpf_like'  => "%" . substr($cpfDigitado, 3, 6) . "%",
            ]);
            $certificadoIndividual = $stmtCert->fetch(PDO::FETCH_ASSOC);

            if ($certificadoIndividual) {
                $cpfConsultaFeedback = [
                    'tipo' => 'success',
                    'msg'  => 'Certificado localizado com sucesso! Verifique os dados abaixo ou acesse a validação pública.',
                ];
            } else {
                $cpfConsultaFeedback = [
                    'tipo' => 'warning',
                    'msg'  => 'Nenhum certificado emitido localizado para este CPF nesta turma. Caso sua frequência tenha atingido os 75%, entre em contato com o instrutor.',
                ];
            }
        }
    }
}

// Materiais categorizados da turma ativa
$materiaisCategorizados = [];
if ($isAlunoLogado && $turma) {
    $materiaisCategorizados = $materialService->getMateriaisCategorizados((int)$turma['id'], true);
}

// Variáveis de suporte institucional
$whatsappNumero = "5562981298351";
$whatsappFormatado = "(62) 98129-8351";
$emailSuporte = "contato@futurofacil.com.br";
$cursoNomeSuporte = $turma ? $turma['curso_nome'] : ($turmaContexto ? $turmaContexto['curso_nome'] : 'Treinamento Corporativo');
$whatsappMsg = rawurlencode("Olá! Sou aluno da turma de {$cursoNomeSuporte} e gostaria de solicitar a chave de acesso aos materiais didáticos.");
$whatsappLink = "https://wa.me/{$whatsappNumero}?text={$whatsappMsg}";

?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title><?= $turma ? htmlspecialchars($turma['curso_nome'], ENT_QUOTES, 'UTF-8') . ' · ' : '' ?>Portal de Conteúdos · Futuro Fácil</title>
<meta name="description" content="Área protegida de materiais didáticos, apostilas, planilhas e conteúdos de turmas da Futuro Fácil." />
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet" />
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Ubuntu:ital,wght@0,300;0,400;0,500;0,700;1,300;1,400;1,500&display=swap" rel="stylesheet" />
<style>
  :root {
    --ink:         #1b1918;
    --ink-soft:    #4e4a49;
    --gray:        #9b9d9f;
    --gray-line:   #e2dfda;
    --paper:       #faf7f1;
    --paper-2:     #f1ece3;
    --cyan:        #307abd;
    --cyan-deep:   #1f5a93;
    --cyan-soft:   #e8f1f9;
    --orange:      #f59e0b;
    --excel:       #1f7d3f;
    --pdf:         #c0392b;
    --video:       #991b1b;
    --form:        #7c3aed;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0;
    font-family: 'Ubuntu', system-ui, -apple-system, sans-serif;
    color: var(--ink);
    background:
      radial-gradient(circle at top left, rgba(240,169,126,0.18), transparent 30%),
      radial-gradient(circle at top right, rgba(48,122,189,0.14), transparent 25%),
      linear-gradient(180deg, #fdfaf4 0%, #faf7f1 45%, #fcf9f3 100%);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    letter-spacing: -0.01em;
  }
  a { color: var(--cyan); text-decoration: none; }
  a:hover { color: var(--cyan-deep); }
  
  /* Header Superior */
  .site-header {
    border-bottom: 1px solid var(--gray-line);
    background: rgba(250, 247, 241, 0.92);
    backdrop-filter: blur(10px);
    position: sticky;
    top: 0;
    z-index: 30;
  }
  .header-container {
    max-width: 1100px;
    margin: 0 auto;
    padding: 0 1.5rem;
    height: 68px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
  }
  .brand-block {
    display: flex;
    align-items: center;
    gap: 0.85rem;
  }
  .brand-logo-text {
    font-size: 1.25rem;
    font-weight: 700;
    letter-spacing: -0.04em;
    color: var(--ink);
  }
  .brand-logo-text span {
    color: var(--cyan);
  }
  .header-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    background: var(--cyan-soft);
    color: var(--cyan-deep);
    font-size: 0.75rem;
    font-weight: 700;
    padding: 0.25rem 0.65rem;
    border-radius: 999px;
  }
  .btn-logout {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.8125rem;
    font-weight: 500;
    color: var(--ink-soft);
    padding: 0.4rem 0.85rem;
    border-radius: 999px;
    border: 1px solid var(--gray-line);
    background: #fff;
    transition: all 0.2s;
  }
  .btn-logout:hover {
    color: #991b1b;
    border-color: #fca5a5;
    background: #fef2f2;
  }

  /* Main Container */
  .main-content {
    flex: 1;
    max-width: 1100px;
    width: 100%;
    margin: 0 auto;
    padding: 2.25rem 1.5rem 4rem;
  }

  /* Tela 1: Login de Aluno */
  .login-card-wrap {
    max-width: 480px;
    margin: 2.5rem auto;
  }
  .login-card {
    background: #ffffff;
    border: 1px solid rgba(27, 25, 24, 0.08);
    border-radius: 1.5rem;
    padding: 2.25rem 2rem;
    box-shadow: 0 16px 40px -12px rgba(27, 25, 24, 0.08);
  }
  .login-title {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--ink);
    margin: 0 0 0.5rem 0;
    letter-spacing: -0.03em;
  }
  .login-subtitle {
    font-size: 0.875rem;
    color: var(--ink-soft);
    margin: 0 0 1.5rem 0;
    line-height: 1.45;
  }
  .form-group {
    margin-bottom: 1.25rem;
  }
  .form-label {
    display: block;
    font-size: 0.8125rem;
    font-weight: 700;
    color: var(--ink);
    margin-bottom: 0.4rem;
  }
  .input-chave {
    width: 100%;
    height: 48px;
    border: 1.5px solid var(--gray-line);
    border-radius: 10px;
    padding: 0 1rem;
    font-size: 1rem;
    font-family: monospace;
    letter-spacing: 0.05em;
    color: var(--ink);
    background: var(--paper);
    transition: border-color 0.2s, box-shadow 0.2s;
  }
  .input-chave:focus {
    outline: none;
    border-color: var(--cyan);
    background: #fff;
    box-shadow: 0 0 0 4px rgba(48, 122, 189, 0.15);
  }
  .btn-submit {
    width: 100%;
    height: 48px;
    border-radius: 10px;
    background: var(--cyan);
    color: #ffffff;
    font-weight: 700;
    font-size: 0.9375rem;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    transition: background 0.2s, transform 0.15s;
    box-shadow: 0 8px 20px -4px rgba(48, 122, 189, 0.35);
  }
  .btn-submit:hover {
    background: var(--cyan-deep);
    transform: translateY(-1px);
  }
  .alert-error {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
    border-radius: 10px;
    padding: 0.85rem 1rem;
    font-size: 0.85rem;
    margin-bottom: 1.25rem;
    line-height: 1.4;
  }

  /* Card de Suporte Institucional */
  .support-card {
    background: #ffffff;
    border: 1px solid var(--gray-line);
    border-radius: 1.25rem;
    padding: 1.5rem;
    margin-top: 1.5rem;
    text-align: center;
  }
  .support-card h4 {
    font-size: 0.9375rem;
    font-weight: 700;
    color: var(--ink);
    margin: 0 0 0.35rem 0;
  }
  .support-card p {
    font-size: 0.8125rem;
    color: var(--ink-soft);
    margin: 0 0 1rem 0;
    line-height: 1.4;
  }
  .btn-whatsapp {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    background: #25d366;
    color: #ffffff;
    font-weight: 700;
    font-size: 0.875rem;
    padding: 0.65rem 1.25rem;
    border-radius: 999px;
    text-decoration: none;
    box-shadow: 0 4px 14px rgba(37, 211, 102, 0.3);
    transition: transform 0.15s, background 0.15s;
  }
  .btn-whatsapp:hover {
    background: #1eb854;
    color: #ffffff;
    transform: translateY(-1px);
  }

  /* Tela 2: Hero do Curso */
  .course-hero {
    background: #ffffff;
    border: 1px solid rgba(27, 25, 24, 0.08);
    border-radius: 1.5rem;
    padding: 2rem 2.25rem;
    margin-bottom: 2rem;
    box-shadow: 0 12px 30px -10px rgba(27, 25, 24, 0.06);
    position: relative;
    overflow: hidden;
  }
  .course-hero::after {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, var(--cyan), var(--cyan-deep));
  }
  .hero-eyebrow {
    font-size: 0.8125rem;
    font-weight: 700;
    color: var(--cyan-deep);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 0.4rem;
  }
  .hero-title {
    font-size: 1.875rem;
    font-weight: 700;
    color: var(--ink);
    margin: 0 0 0.5rem 0;
    letter-spacing: -0.03em;
    line-height: 1.2;
  }
  .hero-meta-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 1rem;
    margin-top: 1.5rem;
    padding-top: 1.25rem;
    border-top: 1px solid var(--gray-line);
    font-size: 0.85rem;
  }
  .hero-meta-item label {
    display: block;
    font-size: 0.6875rem;
    font-weight: 700;
    text-transform: uppercase;
    color: var(--gray);
    margin-bottom: 0.2rem;
  }
  .hero-meta-item span {
    font-weight: 600;
    color: var(--ink);
  }

  /* Banner de Certificados */
  .cert-banner {
    background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
    border: 1px solid #86efac;
    border-radius: 1.25rem;
    padding: 1.5rem 1.75rem;
    margin-bottom: 2rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 1.25rem;
  }
  .cert-banner-text h3 {
    font-size: 1.0625rem;
    font-weight: 700;
    color: #166534;
    margin: 0 0 0.35rem 0;
  }
  .cert-banner-text p {
    font-size: 0.85rem;
    color: #15803d;
    margin: 0;
    max-width: 600px;
    line-height: 1.45;
  }
  .btn-cert-action {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    background: #166534;
    color: #ffffff;
    font-weight: 700;
    font-size: 0.875rem;
    padding: 0.65rem 1.25rem;
    border-radius: 999px;
    text-decoration: none;
    box-shadow: 0 4px 12px rgba(22, 101, 52, 0.25);
    transition: background 0.15s, transform 0.15s;
  }
  .btn-cert-action:hover {
    background: #14532d;
    color: #ffffff;
    transform: translateY(-1px);
  }

  /* Seções de Materiais (Estilo Cooabriel) */
  .category-section {
    margin-bottom: 2.25rem;
  }
  .category-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 1.5px solid var(--gray-line);
  }
  .category-title {
    font-size: 1.1875rem;
    font-weight: 700;
    color: var(--ink);
    margin: 0;
    letter-spacing: -0.02em;
  }
  .category-badge {
    background: var(--paper-2);
    color: var(--ink-soft);
    font-size: 0.75rem;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 999px;
  }
  .materials-list {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
  }
  .material-card {
    background: #ffffff;
    border: 1px solid rgba(27, 25, 24, 0.07);
    border-radius: 1.15rem;
    padding: 1.15rem 1.35rem;
    display: grid;
    grid-template-columns: auto 1fr auto;
    gap: 1.25rem;
    align-items: center;
    box-shadow: 0 4px 14px rgba(27, 25, 24, 0.03);
    transition: border-color 0.2s, box-shadow 0.2s, transform 0.15s;
  }
  .material-card:hover {
    border-color: rgba(48, 122, 189, 0.25);
    box-shadow: 0 8px 24px rgba(48, 122, 189, 0.08);
    transform: translateY(-1px);
  }
  .material-icon {
    width: 44px;
    height: 48px;
    border-radius: 8px;
    background: var(--paper);
    border: 1px solid var(--gray-line);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    font-size: 0.6875rem;
    font-weight: 800;
    letter-spacing: 0.04em;
    flex-shrink: 0;
  }
  .material-icon.pdf  { color: var(--pdf); border-color: rgba(192, 57, 43, 0.25); background: #fef2f2; }
  .material-icon.xlsx { color: var(--excel); border-color: rgba(31, 125, 63, 0.25); background: #f0fdf4; }
  .material-icon.video { color: var(--video); border-color: rgba(153, 27, 27, 0.25); background: #fef2f2; }
  .material-icon.form { color: var(--form); border-color: rgba(124, 58, 237, 0.25); background: #f5f3ff; }
  .material-icon.slide { color: var(--cyan); border-color: rgba(48, 122, 189, 0.25); background: var(--cyan-soft); }
  .material-icon.link  { color: var(--cyan-deep); border-color: var(--gray-line); }

  .material-info h4 {
    font-size: 1rem;
    font-weight: 700;
    color: var(--ink);
    margin: 0 0 0.25rem 0;
    line-height: 1.3;
  }
  .material-info p {
    font-size: 0.8125rem;
    color: var(--ink-soft);
    margin: 0 0 0.35rem 0;
    line-height: 1.4;
  }
  .material-meta-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.75rem;
    color: var(--gray);
  }
  .chip-tag {
    display: inline-flex;
    align-items: center;
    font-size: 0.6875rem;
    font-weight: 700;
    text-transform: uppercase;
    padding: 1px 6px;
    border-radius: 4px;
    background: var(--paper-2);
    color: var(--ink-soft);
  }
  .material-actions {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
  }
  .btn-download {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    background: var(--cyan);
    color: #ffffff;
    font-weight: 700;
    font-size: 0.8125rem;
    padding: 0.55rem 1rem;
    border-radius: 8px;
    text-decoration: none;
    transition: background 0.15s, transform 0.15s;
    box-shadow: 0 2px 6px rgba(48, 122, 189, 0.25);
  }
  .btn-download:hover {
    background: var(--cyan-deep);
    color: #ffffff;
    transform: translateY(-1px);
  }
  .btn-expand-embed {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    background: #ffffff;
    color: var(--cyan-deep);
    border: 1px solid var(--gray-line);
    font-weight: 700;
    font-size: 0.8125rem;
    padding: 0.55rem 0.9rem;
    border-radius: 8px;
    cursor: pointer;
    transition: background 0.15s;
  }
  .btn-expand-embed:hover {
    background: var(--paper);
    border-color: var(--cyan);
  }

  /* Player / Leitor Inline */
  .embed-drawer {
    display: none;
    grid-column: 1 / -1;
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px dashed var(--gray-line);
  }
  .embed-drawer.active {
    display: block;
  }
  .embed-responsive {
    position: relative;
    padding-bottom: 56.25%; /* 16:9 */
    height: 0;
    overflow: hidden;
    border-radius: 10px;
    border: 1px solid var(--gray-line);
  }
  .embed-responsive iframe {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    border: none;
  }

  /* Termos de Acesso Formal */
  .terms-note {
    font-size: 0.75rem;
    color: var(--gray);
    margin-top: 2rem;
    text-align: center;
    line-height: 1.5;
  }

  @media (max-width: 680px) {
    .material-card {
      grid-template-columns: 1fr;
      gap: 0.85rem;
    }
    .material-actions {
      width: 100%;
    }
    .btn-download, .btn-expand-embed {
      width: 100%;
      justify-content: center;
    }
  }
</style>
</head>
<body>

<header class="site-header">
  <div class="header-container">
    <div class="brand-block">
      <a href="/turmas" style="text-decoration: none;">
        <div class="brand-logo-text">FUTURO<span>FÁCIL</span></div>
      </a>
      <span class="header-badge">Portal de Conteúdos</span>
    </div>
    <?php if ($isAlunoLogado): ?>
      <div>
        <a href="/turmas/logout" class="btn-logout" title="Sair desta turma">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
          <span>Sair da Turma</span>
        </a>
      </div>
    <?php endif; ?>
  </div>
</header>

<main class="main-content">

<?php if (!$isAlunoLogado): ?>
  <!-- ===================================================================== -->
  <!-- TELA 1: IDENTIFICAÇÃO E ENTRADA DE CHAVE DE ACESSO                    -->
  <!-- ===================================================================== -->
  <div class="login-card-wrap">
    <div class="login-card">
      <h2 class="login-title">Acesso aos Materiais</h2>
      <p class="login-subtitle">
        <?php if ($turmaContexto): ?>
          Bem-vindo à área de conteúdos de <strong><?= htmlspecialchars($turmaContexto['curso_nome'], ENT_QUOTES, 'UTF-8') ?></strong>.
        <?php else: ?>
          Digite a Chave de Acesso informada pelo instrutor para desbloquear as apostilas, planilhas e slides da sua turma.
        <?php endif; ?>
      </p>

      <?php if ($loginError): ?>
        <div class="alert-error">
          <strong>Atenção:</strong> <?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?>
        </div>
      <?php endif; ?>

      <form method="POST" action="/turmas<?= $turmaSlug ? '/' . htmlspecialchars($turmaSlug, ENT_QUOTES, 'UTF-8') : '' ?>">
        <input type="hidden" name="action" value="login">
        
        <div class="form-group">
          <label class="form-label" for="chave_acesso">Chave de Acesso da Turma:</label>
          <input type="text" id="chave_acesso" name="chave_acesso" class="input-chave" 
                 placeholder="ex: sicoob-excel-2026" required autofocus autocomplete="off">
        </div>

        <button type="submit" class="btn-submit">
          <span>Acessar Conteúdos da Turma</span>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
        </button>
      </form>
    </div>

    <!-- Rodapé de Suporte Direto Institucional -->
    <div class="support-card">
      <h4>Não tem a chave ou perdeu o acesso?</h4>
      <p>
        Fale diretamente com o instrutor da Futuro Fácil para confirmar sua matrícula e receber sua chave:
      </p>
      <a href="<?= $whatsappLink ?>" target="_blank" rel="noopener noreferrer" class="btn-whatsapp">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
        <span>Solicitar Chave via WhatsApp</span>
      </a>
      <div style="margin-top: 0.85rem; font-size: 0.75rem; color: var(--gray);">
        Telefone: <strong><?= $whatsappFormatado ?></strong> | E-mail: <a href="mailto:<?= $emailSuporte ?>" style="color: var(--cyan);"><?= $emailSuporte ?></a>
      </div>
    </div>
  </div>

<?php else: ?>
  <!-- ===================================================================== -->
  <!-- TELA 2: PORTAL DO ALUNO E CATÁLOGO DE MATERIAIS                       -->
  <!-- ===================================================================== -->
  
  <!-- Hero do Curso -->
  <div class="course-hero">
    <div class="hero-eyebrow">
      <?= htmlspecialchars($turma['cliente_nome'] ?? 'Futuro Fácil Treinamentos', ENT_QUOTES, 'UTF-8') ?>
    </div>
    <h1 class="hero-title"><?= htmlspecialchars($turma['curso_nome'], ENT_QUOTES, 'UTF-8') ?></h1>
    <p style="margin: 0; color: var(--ink-soft); font-size: 0.9375rem;">
      Código da Turma: <strong><?= htmlspecialchars($turma['codigo_turma'], ENT_QUOTES, 'UTF-8') ?></strong>
      | Modalidade: <strong>Presencial</strong>
    </p>

    <div class="hero-meta-grid">
      <div class="hero-meta-item">
        <label>Carga Horária</label>
        <span><?= (int)$turma['carga_horaria'] ?> horas-aula</span>
      </div>
      <div class="hero-meta-item">
        <label>Período</label>
        <span><?= date('d/m/Y', strtotime($turma['data_inicio'])) ?> a <?= date('d/m/Y', strtotime($turma['data_conclusao'])) ?></span>
      </div>
      <div class="hero-meta-item">
        <label>Instrutor</label>
        <span><?= htmlspecialchars($turma['instrutor'] ?? 'Tel Santana Leite', ENT_QUOTES, 'UTF-8') ?></span>
      </div>
      <div class="hero-meta-item">
        <label>Cidade / Local</label>
        <span><?= htmlspecialchars($turma['cidade'] ?? 'Goiânia - GO', ENT_QUOTES, 'UTF-8') ?></span>
      </div>
    </div>
  </div>

  <!-- Gestão de Certificados Conforme Configuração da Turma -->
  <?php 
  $modoCertificados = $turma['portal_certificados_modo'] ?? 'nenhum';
  if ($modoCertificados === 'coordenacao'): ?>
    <div class="cert-banner">
      <div class="cert-banner-text">
        <h3>🎓 Certificados Oficiais Emitidos</h3>
        <p>
          Os certificados desta turma foram emitidos e entregues diretamente à <strong>coordenação do evento / RH da sua empresa</strong> para distribuição. Você pode verificar a autenticidade a qualquer momento pelo validador oficial.
        </p>
      </div>
      <div>
        <a href="/validar" target="_blank" class="btn-cert-action">
          <span>Acessar Validador de Certificados</span>
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
        </a>
      </div>
    </div>
  <?php elseif ($modoCertificados === 'download_direto'): ?>
    <div class="cert-banner" style="display: block;">
      <div class="cert-banner-text" style="margin-bottom: 1rem;">
        <h3>🎓 Emissão e Consulta Individual de Certificado</h3>
        <p>
          Os certificados desta turma estão liberados para consulta direta. Digite seu CPF para conferir seu código de registro e autenticidade.
        </p>
      </div>

      <?php if ($cpfConsultaFeedback): ?>
        <div style="padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; font-size: 0.85rem; font-weight: 600; background: <?= $cpfConsultaFeedback['tipo'] === 'success' ? '#DCFCE7' : ($cpfConsultaFeedback['tipo'] === 'warning' ? '#FEF3C7' : '#FEE2E2') ?>; color: <?= $cpfConsultaFeedback['tipo'] === 'success' ? '#166534' : ($cpfConsultaFeedback['tipo'] === 'warning' ? '#92400E' : '#991B1B') ?>;">
          <?= htmlspecialchars($cpfConsultaFeedback['msg'], ENT_QUOTES, 'UTF-8') ?>
        </div>
      <?php endif; ?>

      <form method="POST" action="/turmas" style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
        <input type="hidden" name="action" value="consultar_cpf">
        <input type="text" name="cpf" placeholder="Digite seu CPF (apenas números)" required style="height: 40px; padding: 0 0.85rem; border: 1.5px solid #86efac; border-radius: 8px; font-size: 0.875rem; font-family: monospace;">
        <button type="submit" class="btn-cert-action" style="height: 40px; border: none; cursor: pointer;">
          <span>Consultar Certificado</span>
        </button>
      </form>

      <?php if ($certificadoIndividual): ?>
        <div style="background: #ffffff; border: 1px solid #86efac; border-radius: 8px; padding: 1rem; margin-top: 1rem; font-size: 0.85rem;">
          <div>Aluno(a): <strong><?= htmlspecialchars($certificadoIndividual['aluno_nome'], ENT_QUOTES, 'UTF-8') ?></strong></div>
          <div>Livro: <strong><?= (int)$certificadoIndividual['livro_numero'] ?></strong> | Folha: <strong><?= (int)$certificadoIndividual['folha_numero'] ?></strong> | Registro: <strong><?= (int)$certificadoIndividual['registro_numero'] ?></strong></div>
          <div style="margin-top: 0.5rem;">
            Código de Autenticidade: <code style="font-size: 0.8rem; background: var(--paper-2); padding: 2px 6px; border-radius: 4px;"><?= htmlspecialchars($certificadoIndividual['codigo_autenticidade'], ENT_QUOTES, 'UTF-8') ?></code>
          </div>
          <div style="margin-top: 0.75rem;">
            <a href="/validar?validar=<?= htmlspecialchars($certificadoIndividual['codigo_autenticidade'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" style="color: #166534; font-weight: 700; text-decoration: underline;">
              → Abrir Validação Pública Oficial deste Certificado
            </a>
          </div>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <!-- Catálogo de Materiais Didáticos -->
  <?php if (empty($materiaisCategorizados)): ?>
    <div style="background: #ffffff; border: 1px dashed var(--gray-line); border-radius: 1.5rem; padding: 3rem 2rem; text-align: center;">
      <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="var(--gray)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 0.75rem;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
      <h3 style="font-size: 1.125rem; font-weight: 700; color: var(--ink); margin: 0 0 0.4rem 0;">Materiais em Preparação</h3>
      <p style="font-size: 0.875rem; color: var(--ink-soft); margin: 0; max-width: 460px; margin: 0 auto;">
        O instrutor está finalizando a publicação dos arquivos e exercícios desta turma. Fique à vontade para atualizar esta página durante a aula.
      </p>
    </div>
  <?php else: ?>
    <?php foreach ($materiaisCategorizados as $catKey => $categoria): ?>
      <div class="category-section">
        <div class="category-header">
          <h3 class="category-title"><?= htmlspecialchars($categoria['titulo'], ENT_QUOTES, 'UTF-8') ?></h3>
          <span class="category-badge"><?= count($categoria['itens']) ?></span>
        </div>

        <div class="materials-list">
          <?php foreach ($categoria['itens'] as $mat): 
            $matId = (int)$mat['id'];
            $isArquivo = !empty($mat['is_arquivo']);
            $embedInfo = $mat['embed_info'] ?? ['is_embeddable' => false];
            $hasEmbed = !empty($embedInfo['is_embeddable']);
          ?>
            <div class="material-card" id="mat-card-<?= $matId ?>">
              <!-- Ícone de Formato -->
              <div class="material-icon <?= $mat['icone_tipo'] ?>">
                <?= strtoupper($mat['extensao'] ?: $mat['tipo']) ?>
              </div>

              <!-- Detalhes do Material -->
              <div class="material-info">
                <h4><?= htmlspecialchars($mat['titulo'], ENT_QUOTES, 'UTF-8') ?></h4>
                <?php if (!empty($mat['descricao'])): ?>
                  <p><?= htmlspecialchars($mat['descricao'], ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
                
                <div class="material-meta-row">
                  <span class="chip-tag"><?= strtoupper($mat['tipo']) ?></span>
                  <?php if (!empty($mat['tamanho_formatado'])): ?>
                    <span>• <?= $mat['tamanho_formatado'] ?></span>
                  <?php endif; ?>
                </div>
              </div>

              <!-- Botões de Ação -->
              <div class="material-actions">
                <?php if ($isArquivo): ?>
                  <a href="/turmas/download?id=<?= $matId ?>" class="btn-download" title="Baixar material com segurança">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    <span>Baixar Arquivo</span>
                  </a>
                <?php elseif (!empty($mat['url_externa'])): ?>
                  <a href="<?= htmlspecialchars($mat['url_externa'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="btn-download" style="background: var(--cyan-deep);" title="Abrir link em nova aba">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                    <span>Abrir Link</span>
                  </a>

                  <?php if ($hasEmbed): ?>
                    <button type="button" class="btn-expand-embed" onclick="toggleEmbed(<?= $matId ?>)">
                      <span>Visualizar Aqui</span>
                      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                    </button>
                  <?php endif; ?>
                <?php endif; ?>
              </div>

              <!-- Gaveta de Embed Inline Expansível -->
              <?php if ($hasEmbed): ?>
                <div id="embed-drawer-<?= $matId ?>" class="embed-drawer">
                  <div class="embed-responsive">
                    <iframe src="<?= htmlspecialchars($embedInfo['embed_url'], ENT_QUOTES, 'UTF-8') ?>" 
                            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" 
                            allowfullscreen loading="lazy"></iframe>
                  </div>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <!-- Termos de Compromisso e Acesso de Longo Prazo -->
  <div class="terms-note">
    Os materiais didáticos desta turma são de propriedade intelectual exclusiva da Futuro Fácil Treinamentos Corporativos.<br>
    Garantia formal de disponibilidade de acesso por 12 meses após a realização do curso para estudo e consulta contínua da equipe.
  </div>

<?php endif; ?>

</main>

<script>
function toggleEmbed(id) {
  var drawer = document.getElementById('embed-drawer-' + id);
  if (drawer) {
    drawer.classList.toggle('active');
  }
}
</script>

</body>
</html>
