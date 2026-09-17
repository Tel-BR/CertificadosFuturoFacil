<?php
/**
 * Suíte de Testes Automatizados — Ticket 07c: Reforços de Segurança, Turnstile, LGPD Sobrenome e CSP
 * Plataforma Integrada de Gestão Pedagógica, Diário e Certificados Futuro Fácil
 * 
 * Cobertura de Testes:
 * 1. O1 — Desafio de Sobrenome em CAIXA ALTA (extração, 4 opções, sem duplicatas, validação)
 * 2. O2 — Proteção Anti-Automação na Chave de Acesso (limiar de 3 tentativas, TurnstileService)
 * 3. O3 & O4 — Rate-Limiting no Validador (30 req/min por IP) e Mascaramento LGPD
 * 4. O10 & Y1 — Redirecionamento 301 HTTPS no .htaccess e Cabeçalho CSP com embeds autorizados
 * 5. UI 01 — Shell Editorial: tokens institucionais, Bottom Nav mobile de 56px e logo.svg oficial
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Config/Database.php';
require_once __DIR__ . '/../src/Services/AuthService.php';
require_once __DIR__ . '/../src/Services/TurnstileService.php';
require_once __DIR__ . '/../src/Services/ValidatorService.php';

use FuturoFacil\Config\Database;
use FuturoFacil\Services\AuthService;
use FuturoFacil\Services\TurnstileService;
use FuturoFacil\Services\ValidatorService;

$totalAsserts = 0;
$passedAsserts = 0;

function assertRule(bool $condition, string $message): void {
    global $totalAsserts, $passedAsserts;
    $totalAsserts++;
    if ($condition) {
        $passedAsserts++;
        echo "  ✓ [OK] {$message}\n";
    } else {
        echo "  ✗ [FALHA] {$message}\n";
    }
}

echo "======================================================================\n";
echo " Executando Suíte de Testes — Ticket 07c: Reforços de Segurança\n";
echo "======================================================================\n\n";

// Cria banco SQLite em memória isolado para os testes
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Cria schema mínimo necessário
$pdo->exec("
    CREATE TABLE IF NOT EXISTS alunos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        turma_id INTEGER NOT NULL,
        nome_completo TEXT NOT NULL,
        cpf TEXT,
        cpf_limpo TEXT,
        cpf_mascarado TEXT
    );

    CREATE TABLE IF NOT EXISTS tentativas_login (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ip_address TEXT NOT NULL,
        username TEXT NOT NULL,
        tentativas INTEGER NOT NULL DEFAULT 1,
        bloqueado_ate TEXT NULL,
        ultimo_erro TEXT NOT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    );
");

// Popula alunos de teste com sobrenomes diversos
$pdo->exec("
    INSERT INTO alunos (turma_id, nome_completo, cpf_limpo) VALUES 
    (1, 'Carlos Eduardo da Silva', '11122233344'),
    (1, 'Mariana Ferreira Gomes', '22233344455'),
    (1, 'Rodrigo Albuquerque Lima', '33344455566'),
    (1, 'Aline Cristina Pereira', '44455566677'),
    (1, 'Lucas Gabriel Teixeira', '55566677788');
");

$authService = new AuthService($pdo);
$turnstileService = new TurnstileService();

// =====================================================================
// 1. O1 — Desafio de Sobrenome em CAIXA ALTA
// =====================================================================
echo "-> 1. Testando Desafio de Sobrenome em CAIXA ALTA (O1)...\n";

$sobrenome1 = AuthService::extractSurname("Lucas Gabriel Ferreira da Silva");
assertRule($sobrenome1 === "SILVA", "extractSurname extrai último token em maiúsculas ('SILVA')");

$sobrenome2 = AuthService::extractSurname("Tel Santana Leite");
assertRule($sobrenome2 === "LEITE", "extractSurname extrai 'LEITE'");

$sobrenome3 = AuthService::extractSurname("   Ana Maria de Souza   ");
assertRule($sobrenome3 === "SOUZA", "extractSurname com espaços em branco ('SOUZA')");

$challenge = $authService->generateSurnameChallenge("Carlos Eduardo da Silva", 1);
assertRule(count($challenge['options']) === 4, "generateSurnameChallenge gera exatamente 4 opções");
assertRule($challenge['correct'] === "SILVA", "generateSurnameChallenge identifica o sobrenome correto ('SILVA')");
assertRule(in_array("SILVA", $challenge['options'], true), "Sobrenome correto está presente entre as 4 opções");

$allUppercase = true;
foreach ($challenge['options'] as $opt) {
    if (mb_strtoupper($opt, 'UTF-8') !== $opt) {
        $allUppercase = false;
        break;
    }
}
assertRule($allUppercase, "Todas as opções do desafio de sobrenome estão estritamente em CAIXA ALTA");

$uniqueOptions = array_unique($challenge['options']);
assertRule(count($uniqueOptions) === 4, "Todas as 4 opções geradas são distintas (sem duplicatas)");

assertRule(
    AuthService::verifySurnameChallenge("SILVA", "Carlos Eduardo da Silva") === true,
    "verifySurnameChallenge aprova resposta exata do titular"
);
assertRule(
    AuthService::verifySurnameChallenge("silva", "Carlos Eduardo da Silva") === true,
    "verifySurnameChallenge é tolerante a caixa na entrada do usuário (converte para CAIXA ALTA)"
);
assertRule(
    AuthService::verifySurnameChallenge("GOMES", "Carlos Eduardo da Silva") === false,
    "verifySurnameChallenge rejeita sobrenome distrator de outro colega"
);
assertRule(
    AuthService::verifySurnameChallenge("", "Carlos Eduardo da Silva") === false,
    "verifySurnameChallenge rejeita entrada vazia"
);

// =====================================================================
// 2. O2 — Proteção Anti-Automação na Chave de Acesso (Turnstile)
// =====================================================================
echo "\n-> 2. Testando Proteção Anti-Automação com Cloudflare Turnstile (O2)...\n";

assertRule(
    TurnstileService::isRequiredForStudent(0) === false,
    "Turnstile não é exigido com 0 tentativas incorretas"
);
assertRule(
    TurnstileService::isRequiredForStudent(1) === false,
    "Turnstile não é exigido com 1 tentativa incorreta"
);
assertRule(
    TurnstileService::isRequiredForStudent(2) === false,
    "Turnstile não é exigido com 2 tentativas incorretas"
);
assertRule(
    TurnstileService::isRequiredForStudent(3) === true,
    "Turnstile É EXIGIDO a partir da 3ª tentativa incorreta consecutiva"
);
assertRule(
    TurnstileService::isRequiredForStudent(5) === true,
    "Turnstile permanece exigido com 5 tentativas incorretas"
);

assertRule(
    $turnstileService->verify("XXXX.DUMMY.TOKEN.XXXX") === true,
    "TurnstileService aceita token de teste em ambiente CLI"
);
assertRule(
    $turnstileService->verify("turnstile-valid-token") === true,
    "TurnstileService aceita token simulado"
);
assertRule(
    $turnstileService->verify("") === false,
    "TurnstileService rejeita token vazio"
);
assertRule(
    $turnstileService->verify(null) === false,
    "TurnstileService rejeita token nulo"
);

$widgetHtml = $turnstileService->renderWidget('managed');
assertRule(
    str_contains($widgetHtml, 'class="cf-turnstile"'),
    "renderWidget gera div com classe cf-turnstile"
);
assertRule(
    str_contains($widgetHtml, 'data-sitekey='),
    "renderWidget injeta data-sitekey público"
);

$invisibleWidget = $turnstileService->renderWidget('invisible');
assertRule(
    str_contains($invisibleWidget, 'data-size="invisible"'),
    "renderWidget('invisible') gera widget Turnstile invisível"
);

$scriptTag = TurnstileService::renderScriptTag();
assertRule(
    str_contains($scriptTag, 'challenges.cloudflare.com/turnstile/v0/api.js'),
    "renderScriptTag referencia o script oficial da Cloudflare"
);

// =====================================================================
// 3. O3 & O4 — Rate-Limiting no Validador e Mascaramento LGPD
// =====================================================================
echo "\n-> 3. Testando Rate-Limiting no Validador (30 req/min) e LGPD (O3 & O4)...\n";

$testIp = "192.168.10.50";

// Executa 30 requisições: todas devem ser permitidas
$all30Passed = true;
for ($i = 1; $i <= 30; $i++) {
    if (!$authService->checkValidatorRateLimit($testIp, 30)) {
        $all30Passed = false;
        break;
    }
}
assertRule($all30Passed, "checkValidatorRateLimit permite até 30 requisições por minuto por IP");

// 31ª requisição deve ser bloqueada!
$request31 = $authService->checkValidatorRateLimit($testIp, 30);
assertRule($request31 === false, "checkValidatorRateLimit bloqueia a 31ª requisição (rate-limit excedido)");

// Outro IP independente não deve ser afetado
$otherIp = "192.168.10.51";
assertRule(
    $authService->checkValidatorRateLimit($otherIp, 30) === true,
    "IP diferente opera sob cota independente e não é penalizado"
);

// Mascaramento LGPD padrão ouro
$maskedName = ValidatorService::maskName("Lucas Gabriel Ferreira");
assertRule($maskedName === "L**** G****** F*******", "Mascaramento LGPD de Nome preserva iniciais e ofusca o restante");

$maskedCpf = ValidatorService::maskCpf("12345678900");
assertRule($maskedCpf === "***.456.789-**", "Mascaramento LGPD de CPF preserva apenas miolo central (***.456.789-**)");
assertRule(!str_contains($maskedCpf, "123"), "CPF mascarado nunca expõe os 3 primeiros dígitos");
assertRule(!str_contains($maskedCpf, "00"), "CPF mascarado nunca expõe os 2 dígitos verificadores");

// =====================================================================
// 4. O10 & Y1 — Perímetro HTTPS 301 e CSP
// =====================================================================
echo "\n-> 4. Testando Perímetro HTTPS 301 e Cabeçalhos CSP (O10 & Y1)...\n";

$htaccessPath = __DIR__ . '/../public/.htaccess';
assertRule(file_exists($htaccessPath), "Arquivo public/.htaccess existe");
$htaccessContent = file_get_contents($htaccessPath);

assertRule(
    str_contains($htaccessContent, 'RewriteEngine On'),
    "public/.htaccess ativa motor RewriteEngine"
);
assertRule(
    str_contains($htaccessContent, 'RewriteCond %{HTTPS} !=on'),
    "public/.htaccess verifica se HTTPS está inativo"
);
assertRule(
    str_contains($htaccessContent, 'RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]'),
    "public/.htaccess aplica redirecionamento permanente 301 para HTTPS"
);

$turmasIndexPath = __DIR__ . '/../public/turmas/index.php';
$turmasContent = file_get_contents($turmasIndexPath);

assertRule(
    str_contains($turmasContent, 'Content-Security-Policy'),
    "public/turmas/index.php emite cabeçalho Content-Security-Policy"
);
assertRule(
    str_contains($turmasContent, 'https://challenges.cloudflare.com'),
    "CSP autoriza Cloudflare Turnstile para proteção anti-bot"
);
assertRule(
    str_contains($turmasContent, 'https://www.youtube-nocookie.com'),
    "CSP autoriza player de vídeo YouTube nocookie"
);
assertRule(
    str_contains($turmasContent, 'https://player.vimeo.com'),
    "CSP autoriza player Vimeo"
);
assertRule(
    str_contains($turmasContent, 'https://docs.google.com'),
    "CSP autoriza formulários Google Forms"
);
assertRule(
    str_contains($turmasContent, 'https://forms.office.com'),
    "CSP autoriza formulários Microsoft Forms"
);

$validarIndexPath = __DIR__ . '/../public/validar/index.php';
$validarContent = file_get_contents($validarIndexPath);
assertRule(
    str_contains($validarContent, 'checkValidatorRateLimit'),
    "public/validar/index.php executa verificação ativa de rate limit"
);
assertRule(
    str_contains($validarContent, '429'),
    "public/validar/index.php emite código HTTP 429 em caso de abuso"
);

// =====================================================================
// 5. UI 01 — Shell Editorial, Bottom Nav Mobile e Logo Oficial
// =====================================================================
echo "\n-> 5. Testando Shell Editorial e Design System (UI 01 - ADR-0006)...\n";

$layoutPath = __DIR__ . '/../src/Views/layout_admin.php';
assertRule(file_exists($layoutPath), "Arquivo src/Views/layout_admin.php existe");
$layoutContent = file_get_contents($layoutPath);

assertRule(
    str_contains($layoutContent, '--ff-paper: #FAF7F1'),
    "layout_admin.php define token institucional --ff-paper (#FAF7F1)"
);
assertRule(
    str_contains($layoutContent, '--ff-ink: #1B1918'),
    "layout_admin.php define token institucional --ff-ink (#1B1918)"
);
assertRule(
    str_contains($layoutContent, '--ff-cyan: #0E7490'),
    "layout_admin.php define token institucional Petróleo Tech --ff-cyan (#0E7490)"
);
assertRule(
    str_contains($layoutContent, '--ff-orange: #EA580C'),
    "layout_admin.php define token institucional Coral Solar --ff-orange (#EA580C)"
);
assertRule(
    str_contains($layoutContent, '--ff-line: #E2DFDA'),
    "layout_admin.php define token institucional de borda --ff-line (#E2DFDA)"
);
assertRule(
    str_contains($layoutContent, 'bottom-nav'),
    "layout_admin.php implementa estrutura de Bottom Navigation Bar móvel"
);
assertRule(
    str_contains($layoutContent, 'height: 56px') || str_contains($layoutContent, '56px'),
    "Bottom Nav mobile possui altura padrão de 56px na zona do polegar"
);
assertRule(
    str_contains($layoutContent, 'min-width: 44px') && str_contains($layoutContent, 'min-height: 44px'),
    "Bottom Nav respeita alvo tátil mínimo de 44x44px por item"
);
assertRule(
    str_contains($layoutContent, 'logo.svg'),
    "layout_admin.php incorpora o logotipo vetorial oficial logo.svg"
);
assertRule(
    !str_contains($layoutContent, 'coruja'),
    "layout_admin.php rejeita representações legadas com a coruja"
);

$logoPath = __DIR__ . '/../public/assets/logo.svg';
assertRule(file_exists($logoPath), "Arquivo oficial public/assets/logo.svg existe e está acessível");

$certificadosAdminPath = __DIR__ . '/../public/diario/certificados.php';
assertRule(file_exists($certificadosAdminPath), "Aba canônica public/diario/certificados.php criada e acessível");

$ajustesAdminPath = __DIR__ . '/../public/diario/ajustes.php';
assertRule(file_exists($ajustesAdminPath), "Aba canônica public/diario/ajustes.php criada e acessível");

echo "\n----------------------------------------------------------------------\n";
echo "RESULTADO: {$passedAsserts}/{$totalAsserts} asserções aprovadas (" . round(($passedAsserts / $totalAsserts) * 100, 1) . "%)\n";
if ($passedAsserts === $totalAsserts) {
    echo "Suíte de Testes Ticket 07c & UI 01 APROVADA COM 100% DE SUCESSO!\n";
} else {
    echo "Atenção: Houve falha em algumas asserções!\n";
    exit(1);
}
echo "----------------------------------------------------------------------\n";
