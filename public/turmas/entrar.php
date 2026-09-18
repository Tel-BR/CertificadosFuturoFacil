<?php
/**
 * Portal do Aluno — Recepção em Sala de Aula e Auto-Cadastro (/turmas/entrar)
 * Plataforma Integrada de Gestão Pedagógica, Diário e Certificados Futuro Fácil
 * 
 * Regras de Negócio e Segurança (Ticket 12 / ADR-0005 / ADR-0006):
 * - Recepção smartphone-first ultra-rápida após leitura de QR Code projetado no telão
 * - Coleta obrigatória de Nome Completo e E-mail
 * - Coleta condicional de CPF (com validação módulo 11) e WhatsApp conforme toggles do professor
 * - Barreira anti-robô com Cloudflare Turnstile, Honeypot e Rate-Limiting estrito (3 req/10 min)
 * - Reconciliação inteligente com alunos já existentes para prevenir duplicatas
 * - Presença automática no encontro ativo caso o instrutor tenha habilitado o seletor
 * - Inicialização imediata da sessão do aluno (zero atrito) e e-mail transacional em 2º plano
 */

declare(strict_types=1);

// Cabeçalhos HTTP Defensivos Globais (Ticket 07c / ADR-0005)
if (!headers_sent()) {
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: SAMEORIGIN");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://challenges.cloudflare.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https://challenges.cloudflare.com; frame-src 'self' https://challenges.cloudflare.com; connect-src 'self' https://challenges.cloudflare.com;");
}

require_once __DIR__ . '/../../src/Config/Database.php';
require_once __DIR__ . '/../../src/Services/AuthService.php';
require_once __DIR__ . '/../../src/Services/TurmaService.php';
require_once __DIR__ . '/../../src/Services/MaterialService.php';
require_once __DIR__ . '/../../src/Services/TurnstileService.php';
require_once __DIR__ . '/../../src/Services/ValidatorService.php';
require_once __DIR__ . '/../../src/Services/TransactionalMailService.php';

use FuturoFacil\Config\Database;
use FuturoFacil\Services\AuthService;
use FuturoFacil\Services\TurmaService;
use FuturoFacil\Services\MaterialService;
use FuturoFacil\Services\TurnstileService;
use FuturoFacil\Services\ValidatorService;
use FuturoFacil\Services\TransactionalMailService;

AuthService::startSecureSession();

$pdo = Database::getConnection();
$authService = new AuthService($pdo);
$turmaService = new TurmaService($pdo);
$materialService = new MaterialService($pdo);
$turnstileService = new TurnstileService();
$mailService = new TransactionalMailService();

$turmaParam = isset($_GET['turma']) ? trim((string)$_GET['turma']) : (isset($_POST['turma']) ? trim((string)$_POST['turma']) : '');
$solicitarCpf = !empty($_GET['cpf']) || !empty($_POST['solicitar_cpf']);
$solicitarWpp = !empty($_GET['wpp']) || !empty($_POST['solicitar_wpp']);
$encontroId = isset($_GET['encontro_id']) ? (int)$_GET['encontro_id'] : (isset($_POST['encontro_id']) ? (int)$_POST['encontro_id'] : 0);

$turma = null;
if (!empty($turmaParam)) {
    $turma = $materialService->getTurmaBySlugOrChave($turmaParam);
}

$erroGrave = null;
if (!$turma) {
    $erroGrave = 'A turma solicitada não foi encontrada ou o link expirou. Verifique o código no telão ou solicite ajuda ao instrutor.';
} elseif (!empty($turma['deleted_at'])) {
    $erroGrave = 'O acesso aos materiais desta turma foi desativado (turma na lixeira).';
} elseif ($turma['status'] === 'cancelada') {
    $erroGrave = 'O acesso a esta turma foi desativado pois a mesma foi cancelada.';
}

$erroForm = null;
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

// Processamento do Formulário (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$erroGrave) {
    // 1. Rate-limiting por IP (máximo 3 envios por IP a cada 10 minutos)
    if (!$authService->checkStudentAccessRateLimit($clientIp, 3, 600)) {
        http_response_code(429);
        $erroForm = 'Limite de solicitações excedido para o seu endereço IP. Por segurança, aguarde alguns minutos antes de tentar novamente.';
    }

    // 2. Defesa Honeypot Inteligente
    if (!$erroForm && !empty($_POST['empresa_website_extra'])) {
        // Robô detectado: responde silenciosamente sem persistir
        header('Location: /turmas/' . urlencode((string)$turma['codigo_turma']));
        exit;
    }

    // 3. Validação Anti-CSRF
    $tokenCsrf = (string)($_POST['csrf_token'] ?? '');
    if (!$erroForm && !AuthService::validateCsrfToken($tokenCsrf)) {
        $erroForm = 'Erro de segurança na requisição. Por favor, recarregue a página e tente novamente.';
    }

    // 4. Barreira Cloudflare Turnstile
    if (!$erroForm) {
        $cfToken = $_POST['cf-turnstile-response'] ?? null;
        if (!$turnstileService->verify($cfToken, $clientIp)) {
            $erroForm = 'Por favor, conclua a verificação de segurança (Cloudflare Turnstile) para continuar.';
        }
    }

    // 5. Validação dos Campos de Entrada
    $nome = trim((string)($_POST['nome_completo'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $cpf = !empty($_POST['cpf']) ? trim((string)$_POST['cpf']) : null;
    $telefone = !empty($_POST['telefone']) ? trim((string)$_POST['telefone']) : null;

    if (!$erroForm) {
        if (empty($nome)) {
            $erroForm = 'Por favor, informe o seu Nome Completo.';
        } elseif (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erroForm = 'Por favor, informe um endereço de e-mail válido.';
        } elseif (!empty($cpf)) {
            $cpfLimpo = ValidatorService::cleanCpf($cpf);
            if (!ValidatorService::validateCpf($cpfLimpo)) {
                $erroForm = 'O CPF informado parece inválido. Por favor, confira os números digitados.';
            }
        }
    }

    // 6. Persistência, Reconciliação Inteligente e Presença Automática
    if (!$erroForm && $turma) {
        try {
            $encontroPresenca = ($encontroId > 0) ? $encontroId : null;
            $resReg = $turmaService->reconcileOrRegisterStudent(
                (int)$turma['id'],
                $nome,
                $email,
                $cpf,
                $telefone,
                $encontroPresenca
            );

            $alunoData = $resReg['aluno'];

            // 7. Disparo em 2º plano do E-mail Transacional de Apoio com Chave de Acesso
            try {
                $mailService->sendStudentAccessEmail($turma, $email, $nome, $resReg['is_new']);
            } catch (\Throwable) {
                // Não interrompe o onboarding em sala de aula caso o MTA oscile
            }

            // 8. Inicialização Imediata da Sessão do Aluno (Zero Atrito)
            $authService->authenticateStudent($turma['chave_acesso'], $turma['codigo_turma'], $alunoData);

            // Mensagem de boas-vindas com confirmação de presença se aplicável
            $msgSucesso = "Bem-vindo(a), " . htmlspecialchars($nome, ENT_QUOTES, 'UTF-8') . "! Seus materiais didáticos já estão disponíveis.";
            if ($resReg['presenca_registrada']) {
                $msgSucesso .= " Sua presença na aula de hoje também foi validada com sucesso!";
            }
            $_SESSION['flash_aluno_sucesso'] = $msgSucesso;

            $slugDestino = !empty($turma['codigo_turma']) ? urlencode((string)$turma['codigo_turma']) : '';
            header("Location: /turmas/{$slugDestino}");
            exit;
        } catch (\Throwable $e) {
            $erroForm = 'Erro ao processar cadastro: ' . $e->getMessage();
        }
    }
}

$csrfToken = AuthService::getCsrfToken();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    <title>Recepção de Alunos — Futuro Fácil</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Ubuntu:ital,wght@0,300;0,400;0,500;0,700;1,400&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    <?= TurnstileService::renderScriptTag() ?>
    <style>
        :root {
            --primary: #0E7490;
            --primary-hover: #155E75;
            --primary-light: #ECFEFF;
            --dark: #0F172A;
            --text: #1B1918;
            --text-muted: #64748B;
            --border: #E2DFDA;
            --bg: #FAF7F1;
            --surface: #FFFFFF;
            --danger: #DC2626;
            --danger-bg: #FEF2F2;
            --success: #16A34A;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Ubuntu', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 1.5rem 1rem;
        }

        .reception-card {
            background-color: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            width: 100%;
            max-width: 460px;
            padding: 2rem 1.75rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
        }

        .header-brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
        }

        .header-brand img {
            height: 32px;
            width: auto;
        }

        .brand-text {
            font-size: 1.125rem;
            font-weight: 700;
            color: var(--primary);
            letter-spacing: -0.3px;
        }

        .turma-tag {
            display: inline-block;
            background-color: var(--primary-light);
            color: var(--primary);
            font-size: 0.75rem;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.75rem;
        }

        .course-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--dark);
            line-height: 1.3;
            margin-bottom: 0.35rem;
        }

        .course-client {
            font-size: 0.875rem;
            color: var(--text-muted);
            margin-bottom: 1.5rem;
        }

        .alert-error {
            background-color: var(--danger-bg);
            border: 1px solid #FECACA;
            color: var(--danger);
            border-radius: 8px;
            padding: 0.75rem 1rem;
            font-size: 0.875rem;
            margin-bottom: 1.25rem;
            line-height: 1.4;
        }

        .form-group {
            margin-bottom: 1.125rem;
        }

        .form-label {
            display: block;
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.35rem;
        }

        .form-label .optional {
            font-weight: 400;
            color: var(--text-muted);
        }

        .form-input {
            width: 100%;
            padding: 0.75rem 0.875rem;
            font-size: 0.9375rem;
            font-family: inherit;
            border: 1px solid #CBD5E1;
            border-radius: 8px;
            background-color: #FFFFFF;
            color: var(--text);
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(14, 116, 144, 0.15);
        }

        .turnstile-box {
            display: flex;
            justify-content: center;
            margin: 1.25rem 0;
            min-height: 65px;
        }

        .btn-submit {
            width: 100%;
            background-color: var(--primary);
            color: #FFFFFF;
            border: none;
            border-radius: 8px;
            padding: 0.875rem;
            font-size: 1rem;
            font-weight: 700;
            font-family: inherit;
            cursor: pointer;
            transition: background-color 0.15s ease, transform 0.05s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .btn-submit:hover {
            background-color: var(--primary-hover);
        }

        .btn-submit:active {
            transform: scale(0.99);
        }

        .lgpd-note {
            font-size: 0.75rem;
            color: var(--text-muted);
            text-align: center;
            margin-top: 1.25rem;
            line-height: 1.4;
        }

        .hp-trap {
            position: absolute;
            left: -9999px;
            opacity: 0;
            pointer-events: none;
            width: 1px;
            height: 1px;
        }
    </style>
</head>
<body>

<div class="reception-card">
    <div class="header-brand">
        <img src="/assets/logo.svg" alt="Futuro Fácil">
        <span class="brand-text">FUTURO FÁCIL</span>
    </div>

    <?php if ($erroGrave): ?>
        <div class="alert-error">
            <strong>Aviso:</strong> <?= htmlspecialchars($erroGrave, ENT_QUOTES, 'UTF-8') ?>
        </div>
        <div style="text-align: center; margin-top: 1rem;">
            <a href="/turmas" style="color: var(--primary); font-weight: 600; font-size: 0.875rem; text-decoration: none;">Acessar Portal do Aluno com Chave</a>
        </div>
    <?php else: ?>
        <span class="turma-tag">Recepção de Alunos</span>
        <h1 class="course-title"><?= htmlspecialchars($turma['curso_nome'], ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="course-client">
            <?= !empty($turma['cliente_nome']) ? 'Cliente: ' . htmlspecialchars($turma['cliente_nome'], ENT_QUOTES, 'UTF-8') : 'Acesso aos Materiais Didáticos' ?>
        </p>

        <?php if ($erroForm): ?>
            <div class="alert-error">
                <?= htmlspecialchars($erroForm, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form action="/turmas/entrar" method="POST" id="formEntrar" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="turma" value="<?= htmlspecialchars($turmaParam, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="solicitar_cpf" value="<?= $solicitarCpf ? '1' : '0' ?>">
            <input type="hidden" name="solicitar_wpp" value="<?= $solicitarWpp ? '1' : '0' ?>">
            <input type="hidden" name="encontro_id" value="<?= (int)$encontroId ?>">

            <!-- Honeypot anti-robô inteligente -->
            <div class="hp-trap" aria-hidden="true">
                <input type="text" name="empresa_website_extra" tabindex="-1" autocomplete="off">
            </div>

            <div class="form-group">
                <label for="nome_completo" class="form-label">Nome Completo</label>
                <input type="text" id="nome_completo" name="nome_completo" class="form-input" required 
                       placeholder="Seu nome como sairá no certificado" 
                       value="<?= htmlspecialchars($_POST['nome_completo'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                       autocomplete="name" autofocus>
            </div>

            <div class="form-group">
                <label for="email" class="form-label">E-mail</label>
                <input type="email" id="email" name="email" class="form-input" required 
                       placeholder="seu.email@exemplo.com" 
                       value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                       autocomplete="email">
            </div>

            <?php if ($solicitarCpf): ?>
                <div class="form-group">
                    <label for="cpf" class="form-label">CPF <span class="optional">(opcional para certificado)</span></label>
                    <input type="text" id="cpf" name="cpf" class="form-input" 
                           placeholder="000.000.000-00" 
                           value="<?= htmlspecialchars($_POST['cpf'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           maxlength="14" autocomplete="off">
                </div>
            <?php endif; ?>

            <?php if ($solicitarWpp): ?>
                <div class="form-group">
                    <label for="telefone" class="form-label">WhatsApp / Celular <span class="optional">(opcional)</span></label>
                    <input type="tel" id="telefone" name="telefone" class="form-input" 
                           placeholder="(00) 00000-0000" 
                           value="<?= htmlspecialchars($_POST['telefone'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           maxlength="15" autocomplete="tel">
                </div>
            <?php endif; ?>

            <!-- Widget Cloudflare Turnstile -->
            <div class="turnstile-box">
                <?= $turnstileService->renderWidget('managed') ?>
            </div>

            <button type="submit" class="btn-submit" id="btnSubmit">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                <span>Acessar Materiais</span>
            </button>

            <p class="lgpd-note">
                Seus dados são protegidos sob a LGPD e utilizados exclusivamente para emissão de certificados e identificação pedagógica.
            </p>
        </form>
    <?php endif; ?>
</div>

<script>
    // Máscara dinâmica de CPF
    const cpfInput = document.getElementById('cpf');
    if (cpfInput) {
        cpfInput.addEventListener('input', function(e) {
            let v = e.target.value.replace(/\D/g, '');
            if (v.length > 11) v = v.slice(0, 11);
            if (v.length > 9) {
                v = v.replace(/(\d{3})(\d{3})(\d{3})(\d{1,2})/, '$1.$2.$3-$4');
            } else if (v.length > 6) {
                v = v.replace(/(\d{3})(\d{3})(\d{1,3})/, '$1.$2.$3');
            } else if (v.length > 3) {
                v = v.replace(/(\d{3})(\d{1,3})/, '$1.$2');
            }
            e.target.value = v;
        });
    }

    // Máscara dinâmica de Telefone / WhatsApp
    const telInput = document.getElementById('telefone');
    if (telInput) {
        telInput.addEventListener('input', function(e) {
            let v = e.target.value.replace(/\D/g, '');
            if (v.length > 11) v = v.slice(0, 11);
            if (v.length > 10) {
                v = v.replace(/(\d{2})(\d{5})(\d{4})/, '($1) $2-$3');
            } else if (v.length > 6) {
                v = v.replace(/(\d{2})(\d{4})(\d{0,4})/, '($1) $2-$3');
            } else if (v.length > 2) {
                v = v.replace(/(\d{2})(\d{0,5})/, '($1) $2');
            }
            e.target.value = v;
        });
    }
</script>

</body>
</html>
