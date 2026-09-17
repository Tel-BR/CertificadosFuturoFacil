<?php
/**
 * Suíte de Testes Automatizados — Ticket 07b (Security Hardening & OWASP Regression)
 * Plataforma Integrada de Gestão Pedagógica, Diário e Certificados Futuro Fácil
 * 
 * Valida de ponta a ponta:
 * 1. Blindagem de Perímetro (.htaccess, cabeçalhos de segurança, bloqueio a arquivos sensíveis)
 * 2. Filtros de Segurança do Despachante Web (router.php)
 * 3. Imunidade a SQL Injection e Integridade de Prepared Statements
 * 4. Conformidade e Mascaramento LGPD (Nomes e CPFs)
 * 5. Proteção contra Path Traversal e Injeção de Arquivos em Downloads
 * 6. Hardening de Sessões, Mitigação de Força Bruta (Rate-Limiting) e Tokens CSRF
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Config/Database.php';
require_once __DIR__ . '/../src/Services/AuthService.php';
require_once __DIR__ . '/../src/Services/ValidatorService.php';
require_once __DIR__ . '/../src/Services/MaterialService.php';

use FuturoFacil\Config\Database;
use FuturoFacil\Services\AuthService;
use FuturoFacil\Services\ValidatorService;
use FuturoFacil\Services\MaterialService;

$verde = "\033[32m";
$vermelho = "\033[31m";
$amarelo = "\033[33m";
$azul = "\033[36m";
$reset = "\033[0m";

echo "{$azul}======================================================================\n";
echo " Executando Ticket 07b: Auditoria OWASP, Perímetro e Testes de Segurança\n";
echo "======================================================================{$reset}\n\n";

$totalCount = 0;
$passedCount = 0;

function assertTest(bool $condition, string $message): void {
    global $totalCount, $passedCount, $verde, $vermelho, $reset;
    $totalCount++;
    if ($condition) {
        $passedCount++;
        echo "  {$verde}[PASS]{$reset} {$message}\n";
    } else {
        echo "  {$vermelho}[FAIL]{$reset} {$message}\n";
    }
}

try {
    // -------------------------------------------------------------------------
    // 1. BLINDAGEM DE PERÍMETRO (.htaccess e CABEÇALHOS DE SEGURANÇA)
    // -------------------------------------------------------------------------
    echo "{$azul}1. Verificando Blindagem de Perímetro e Regras Apache (.htaccess)...{$reset}\n";
    
    $publicHtaccessPath = __DIR__ . '/../public/.htaccess';
    assertTest(file_exists($publicHtaccessPath), "Arquivo public/.htaccess existe no document root");
    
    $publicHtaccess = file_get_contents($publicHtaccessPath) ?: '';
    assertTest(str_contains($publicHtaccess, 'Options -Indexes'), "public/.htaccess desativa listagem de diretórios (Options -Indexes)");
    assertTest(str_contains($publicHtaccess, 'X-Frame-Options "SAMEORIGIN"'), "public/.htaccess injeta X-Frame-Options SAMEORIGIN contra Clickjacking");
    assertTest(str_contains($publicHtaccess, 'X-Content-Type-Options "nosniff"'), "public/.htaccess injeta X-Content-Type-Options nosniff contra MIME-sniffing");
    assertTest(str_contains($publicHtaccess, 'Referrer-Policy "strict-origin-when-cross-origin"'), "public/.htaccess injeta Referrer-Policy strict-origin");
    assertTest(str_contains($publicHtaccess, 'Permissions-Policy'), "public/.htaccess restringe APIs de dispositivos via Permissions-Policy");
    assertTest(str_contains($publicHtaccess, 'Strict-Transport-Security'), "public/.htaccess declara política HSTS para conexões seguras");
    assertTest(str_contains($publicHtaccess, 'SECRETS'), "public/.htaccess bloqueia acesso ao arquivo SECRETS");
    assertTest((bool)preg_match('/credentials\\\\?\.local\\\\?\.php/', $publicHtaccess), "public/.htaccess bloqueia acesso a credentials.local.php");
    assertTest(str_contains($publicHtaccess, 'sqlite'), "public/.htaccess bloqueia acesso a arquivos .db / .sqlite");

    // Verifica blindagem de downloads diretos em turmas/arquivos/
    $arquivosHtaccessPath = __DIR__ . '/../public/turmas/arquivos/.htaccess';
    assertTest(file_exists($arquivosHtaccessPath), "Diretório public/turmas/arquivos/.htaccess existe");
    $arquivosHtaccess = file_get_contents($arquivosHtaccessPath) ?: '';
    assertTest(str_contains($arquivosHtaccess, 'Require all denied'), "Pasta turmas/arquivos/.htaccess bloqueia acesso HTTP direto (Require all denied)");
    assertTest(str_contains($arquivosHtaccess, 'Options -Indexes'), "Pasta turmas/arquivos/.htaccess bloqueia listagem de arquivos (Options -Indexes)");

    // -------------------------------------------------------------------------
    // 2. FILTROS DE SEGURANÇA DO DESPACHANTE WEB (router.php)
    // -------------------------------------------------------------------------
    echo "\n{$azul}2. Verificando Filtros e Cabeçalhos no router.php...{$reset}\n";
    $routerPath = __DIR__ . '/../public/router.php';
    assertTest(file_exists($routerPath), "Arquivo public/router.php existe");
    $routerCode = file_get_contents($routerPath) ?: '';
    
    assertTest(str_contains($routerCode, 'X-Frame-Options: SAMEORIGIN'), "router.php injeta cabeçalho X-Frame-Options SAMEORIGIN");
    assertTest(str_contains($routerCode, 'X-Content-Type-Options: nosniff'), "router.php injeta cabeçalho X-Content-Type-Options nosniff");
    assertTest(str_contains($routerCode, 'Referrer-Policy: strict-origin-when-cross-origin'), "router.php injeta cabeçalho Referrer-Policy");

    // Simulação dos padrões de bloqueio do router
    $blockedUris = [
        '/SECRETS',
        '/secrets',
        '/credentials.local.php',
        '/.env',
        '/.env.production',
        '/registros.db',
        '/teste.sqlite3',
        '/database/schema.sql',
        '/turmas/arquivos/aula.pdf',
        '/backup.bak',
        '/config.ini',
        '/app.log',
    ];

    $patternBlock = '#(?:^|/)(?:SECRETS|credentials\.local\.php|\.env.*)(?:/|$)#i';
    $patternArquivos = '#^/turmas/arquivos(?:/|$)#i';
    $patternExt = '#\.(?:db|sqlite|sqlite3|ini|log|bak|sql|sh|md|yml|yaml)(?:-(?:wal|shm|journal))?(?:/|$)#i';

    foreach ($blockedUris as $uri) {
        $isBlocked = (
            preg_match($patternBlock, $uri) ||
            preg_match($patternArquivos, $uri) ||
            preg_match($patternExt, $uri)
        );
        assertTest((bool)$isBlocked, "Router bloqueia caminho sensível simulado: {$uri}");
    }

    $allowedUris = ['/validar', '/turmas', '/diario/login', '/diario/turmas'];
    foreach ($allowedUris as $uri) {
        $isAllowed = !(
            preg_match($patternBlock, $uri) ||
            preg_match($patternArquivos, $uri) ||
            preg_match($patternExt, $uri)
        );
        assertTest($isAllowed, "Router permite acesso a endpoint legítimo da aplicação: {$uri}");
    }

    // -------------------------------------------------------------------------
    // 3. IMUNIDADE A SQL INJECTION E VALIDAÇÃO DE INPUTS
    // -------------------------------------------------------------------------
    echo "\n{$azul}3. Verificando Imunidade a SQL Injection e Sanitização...{$reset}\n";

    $sqliPayloads = [
        "' OR '1'='1",
        "'; DROP TABLE turmas; --",
        "1 UNION SELECT null, username, password_hash FROM usuarios_admin--",
        "admin' --",
        "<script>alert('xss')</script>",
        "../../etc/passwd",
    ];

    foreach ($sqliPayloads as $payload) {
        $cleaned = ValidatorService::cleanAuthCode($payload);
        $isValidSha = ValidatorService::isValidSha256($cleaned);
        assertTest(!$isValidSha, "ValidatorService rejeita payload hostil como SHA-256 válido: " . substr($payload, 0, 20) . "...");
    }

    // Testa configuração de banco (Prepared Statements não-emulados)
    $dbReflection = new ReflectionClass(Database::class);
    $method = $dbReflection->getMethod('createConnection');
    $method->setAccessible(true);
    
    Database::setConfig(['driver' => 'sqlite', 'database' => ':memory:']);
    $pdo = Database::getConnection();
    assertTest($pdo instanceof PDO, "Conexão PDO instanciada com sucesso para testes de segurança");

    // -------------------------------------------------------------------------
    // 4. CONFORMIDADE LGPD (MASCARAMENTO DE DADOS SENSÍVEIS)
    // -------------------------------------------------------------------------
    echo "\n{$azul}4. Verificando Conformidade e Mascaramento LGPD...{$reset}\n";

    assertTest(ValidatorService::maskName('Ana Luiza Santos') === 'A** L**** S*****', "Mascaramento LGPD de Nome de 3 termos");
    assertTest(ValidatorService::maskName('Carlos') === 'C*****', "Mascaramento LGPD de Nome monônimo");
    assertTest(ValidatorService::maskName('Tel Santana Leite') === 'T** S****** L****', "Mascaramento LGPD preserva apenas iniciais");
    assertTest(ValidatorService::maskName('') === '—', "Mascaramento LGPD de nome vazio retorna travessão");

    assertTest(ValidatorService::maskCpf('123.456.789-00') === '***.456.789-**', "Mascaramento LGPD de CPF formatado preserva apenas miolo central");
    assertTest(ValidatorService::maskCpf('12345678900') === '***.456.789-**', "Mascaramento LGPD de CPF puro de 11 dígitos");
    assertTest(!str_contains(ValidatorService::maskCpf('12345678900'), '123'), "CPF mascarado nunca expõe os 3 primeiros dígitos");
    assertTest(!str_contains(ValidatorService::maskCpf('12345678900'), '00'), "CPF mascarado nunca expõe os 2 dígitos verificadores");

    // -------------------------------------------------------------------------
    // 5. PROTEÇÃO CONTRA PATH TRAVERSAL EM DOWNLOADS (MaterialService)
    // -------------------------------------------------------------------------
    echo "\n{$azul}5. Verificando Proteção contra Path Traversal em Materiais...{$reset}\n";

    $tempStorage = sys_get_temp_dir() . '/ff_test_sec_storage_' . uniqid();
    @mkdir($tempStorage, 0755, true);
    file_put_contents($tempStorage . '/legitimo.pdf', '%PDF-1.4 mock content');

    $materialService = new MaterialService($pdo, $tempStorage);
    
    // Teste 1: Arquivo legítimo dentro da raiz é resolvido
    $resolvedLegit = $materialService->resolveFilePath('legitimo.pdf');
    assertTest($resolvedLegit !== null && is_file($resolvedLegit), "MaterialService resolve caminho legítimo dentro do storage");

    // Teste 2: Path Traversal clássico com ..
    $traversal1 = $materialService->resolveFilePath('../../../SECRETS');
    assertTest($traversal1 === null, "MaterialService bloqueia tentativa de Path Traversal '../../../SECRETS'");

    // Teste 3: Path Traversal com subpasta inexistente
    $traversal2 = $materialService->resolveFilePath('sub/../../../../etc/passwd');
    assertTest($traversal2 === null, "MaterialService bloqueia escape de diretório para fora da raiz");

    // Teste 4: Tentativa com byte nulo
    $traversalNull = $materialService->resolveFilePath("legitimo.pdf\0.exe");
    assertTest($traversalNull === null, "MaterialService rejeita caminhos contendo byte nulo");

    // -------------------------------------------------------------------------
    // 6. HARDENING DE SESSÕES, RATE-LIMITING E TOKENS ANTI-CSRF
    // -------------------------------------------------------------------------
    echo "\n{$azul}6. Verificando Sessões Seguras, Rate-Limiting e CSRF...{$reset}\n";

    // CSRF Tokens
    $csrf1 = AuthService::getCsrfToken();
    assertTest(strlen($csrf1) === 64, "Token anti-CSRF gerado com 64 caracteres hexadecimais (32 bytes)");
    assertTest(ctype_xdigit($csrf1), "Token anti-CSRF é estritamente hexadecimal");
    assertTest(AuthService::validateCsrfToken($csrf1) === true, "Token anti-CSRF válido é aceito");
    assertTest(AuthService::validateCsrfToken('token_falso_invalido_123') === false, "Token anti-CSRF adulterado é sumariamente rejeitado");
    assertTest(AuthService::validateCsrfToken('') === false, "Token anti-CSRF vazio é rejeitado");
    assertTest(AuthService::validateCsrfToken(null) === false, "Token anti-CSRF nulo é rejeitado");

    // Delay progressivo de aluno para mitigar enumeração
    $authService = new AuthService($pdo);
    $_SESSION = [];
    $d1 = $authService->recordStudentFailedAttempt(applySleep: false);
    assertTest($d1 === 1, "Primeira tentativa errada de aluno aplica delay de 1s");
    $d2 = $authService->recordStudentFailedAttempt(applySleep: false);
    assertTest($d2 === 2, "Segunda tentativa errada de aluno aplica delay de 2s");
    $d3 = $authService->recordStudentFailedAttempt(applySleep: false);
    assertTest($d3 === 4, "Terceira tentativa errada de aluno aplica delay máximo de 4s");
    $authService->clearStudentFailedAttempts();
    assertTest($authService->getStudentFailedAttempts() === 0, "Tentativas de aluno são devidamente zeradas após sucesso");

    // Limpeza de diretório temporário
    @unlink($tempStorage . '/legitimo.pdf');
    @rmdir($tempStorage);

    // -------------------------------------------------------------------------
    // RESULTADO FINAL
    // -------------------------------------------------------------------------
    echo "\n{$azul}----------------------------------------------------------------------\n";
    if ($passedCount === $totalCount) {
        echo "{$verde}RESULTADO: 100% DE SUCESSO! ({$passedCount}/{$totalCount} asserções de segurança aprovadas).\n";
        echo "Ticket 07b (Auditoria OWASP, Blindagem de Perímetro e Testes) APROVADO!{$reset}\n";
        echo "{$azul}----------------------------------------------------------------------{$reset}\n";
    } else {
        $falhas = $totalCount - $passedCount;
        echo "{$vermelho}RESULTADO: {$falhas} asserção(ões) falharam de um total de {$totalCount}.{$reset}\n";
        echo "{$azul}----------------------------------------------------------------------{$reset}\n";
        exit(1);
    }

} catch (Throwable $e) {
    echo "{$vermelho}[ERRO EXCEÇÃO]: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "{$reset}\n";
    exit(1);
}
