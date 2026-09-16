<?php
/**
 * Suíte de Testes Automatizados — Ticket 02: Autenticação e Painel Base
 * Plataforma Integrada de Gestão Pedagógica, Diário e Certificados Futuro Fácil
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Config/Database.php';
require_once __DIR__ . '/../src/Services/AuthService.php';
require_once __DIR__ . '/../src/Views/layout_admin.php';

use FuturoFacil\Config\Database;
use FuturoFacil\Services\AuthService;
use function FuturoFacil\Views\renderAdminLayout;

$verde = "\033[32m";
$vermelho = "\033[31m";
$amarelo = "\033[33m";
$azul = "\033[36m";
$reset = "\033[0m";

echo "{$azul}======================================================================\n";
echo " Executando Suíte de Testes — Ticket 02: Autenticação e Painel Base\n";
echo "======================================================================{$reset}\n\n";

$testDbPath = __DIR__ . '/test_temp_auth.db';
if (file_exists($testDbPath)) {
    unlink($testDbPath);
}

$passedCount = 0;
$totalCount = 0;

function assertTest(bool $condition, string $description, ?string $detail = null): void
{
    global $verde, $vermelho, $reset, $passedCount, $totalCount;
    $totalCount++;
    if ($condition) {
        $passedCount++;
        echo "  {$verde}✓ [OK]{$reset} {$description}\n";
    } else {
        echo "  {$vermelho}✗ [FALHA]{$reset} {$description}";
        if ($detail) {
            echo " ({$detail})";
        }
        echo "\n";
    }
}

try {
    // 1. Inicializa banco SQLite de testes
    $pdo = new PDO("sqlite:{$testDbPath}", null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $schemaSql = file_get_contents(__DIR__ . '/../database/schema_sqlite.sql');
    $pdo->exec($schemaSql);

    Database::setConfig([
        'driver'   => 'sqlite',
        'database' => $testDbPath,
    ]);

    // 2. Insere operador de teste na tabela usuarios_admin
    $testUsername = 'admin_teste';
    $testPassword = 'SenhaSecreta@Futuro2026';
    $testPasswordHash = password_hash($testPassword, PASSWORD_BCRYPT);
    $testNome = 'Instrutor Homologação';
    $testEmail = 'operador@futurofacil.com.br';

    $stmtInsert = $pdo->prepare("
        INSERT INTO usuarios_admin (username, password_hash, nome, email)
        VALUES (:username, :password_hash, :nome, :email)
    ");
    $stmtInsert->execute([
        'username'      => $testUsername,
        'password_hash' => $testPasswordHash,
        'nome'          => $testNome,
        'email'         => $testEmail,
    ]);

    $authService = new AuthService($pdo);

    echo "{$amarelo}-> 1. Testando Autenticação e Validação de Credenciais...{$reset}\n";

    // Teste 1.1: Rejeição de campos vazios
    $resVazio = $authService->authenticate('', '', '192.168.1.10');
    assertTest(!$resVazio['success'], "Rejeição de credenciais vazias");

    // Teste 1.2: Rejeição de usuário inexistente
    $resInexistente = $authService->authenticate('usuario_inexistente', 'senha123', '192.168.1.10');
    assertTest(!$resInexistente['success'], "Rejeição de usuário inexistente");

    // Teste 1.3: Rejeição de senha incorreta
    $resSenhaIncorreta = $authService->authenticate($testUsername, 'senha_errada_123', '192.168.1.10');
    assertTest(!$resSenhaIncorreta['success'], "Rejeição de senha incorreta com bcrypt");
    assertTest(str_contains($resSenhaIncorreta['error'] ?? '', 'Credenciais inválidas'), "Mensagem informativa sobre tentativas restantes");

    // Teste 1.4: Sucesso com credenciais corretas
    $resSucesso = $authService->authenticate($testUsername, $testPassword, '192.168.1.10');
    assertTest($resSucesso['success'], "Autenticação bem-sucedida com senha bcrypt correta");
    assertTest(AuthService::isAuthenticated(), "Estado de autenticação confirmado na sessão");
    $currentUser = AuthService::getCurrentUser();
    assertTest($currentUser !== null && $currentUser['username'] === $testUsername, "Dados do operador carregados corretamente no escopo da sessão");

    // Verifica atualização de ultimo_login no banco
    $stmtCheckLogin = $pdo->prepare("SELECT ultimo_login FROM usuarios_admin WHERE id = :id");
    $stmtCheckLogin->execute(['id' => $currentUser['id']]);
    $ultimoLogin = $stmtCheckLogin->fetchColumn();
    assertTest(!empty($ultimoLogin), "Campo 'ultimo_login' atualizado no banco de dados");

    echo "\n{$amarelo}-> 2. Testando Rate Limiting e Proteção contra Força Bruta...{$reset}\n";

    $attackerIp = '203.0.113.50';
    $targetUser = 'admin_teste';

    // 4 tentativas falhas consecutivas
    for ($i = 1; $i <= 4; $i++) {
        $res = $authService->authenticate($targetUser, "senha_tentativa_{$i}", $attackerIp);
        assertTest(!$res['success'] && empty($res['blocked']), "Tentativa falha {$i}/5 registrada sem bloqueio imediato");
    }

    // 5ª tentativa falha -> dispara o bloqueio
    $resBloqueio = $authService->authenticate($targetUser, "senha_tentativa_5", $attackerIp);
    assertTest(!$resBloqueio['success'] && !empty($resBloqueio['blocked']), "5ª tentativa consecutiva aciona bloqueio temporário por rate-limiting");
    assertTest(isset($resBloqueio['remaining_seconds']) && $resBloqueio['remaining_seconds'] > 800, "Tempo de bloqueio fixado em 15 minutos (900 segundos)");

    // 6ª tentativa MESMO com a senha CORRETA deve ser bloqueada sumariamente pelo rate-limiting
    $resTentativaComSenhaCorretaDuranteBloqueio = $authService->authenticate($targetUser, $testPassword, $attackerIp);
    assertTest(!$resTentativaComSenhaCorretaDuranteBloqueio['success'] && !empty($resTentativaComSenhaCorretaDuranteBloqueio['blocked']), "Tentativa com senha correta durante a janela de bloqueio é sumariamente rejeitada");

    // Resetando bloqueio para outro IP legítimo
    $resOutroIp = $authService->authenticate($targetUser, $testPassword, '192.168.1.99');
    assertTest($resOutroIp['success'], "Outro endereço IP legítimo não é afetado pelo bloqueio do IP agressor");

    // Limpeza de tentativas no sucesso
    $tentativasRestantes = $authService->getAttemptCount('192.168.1.99', $targetUser);
    assertTest($tentativasRestantes === 0, "Tentativas falhas são zeradas após autenticação bem-sucedida");

    echo "\n{$amarelo}-> 3. Testando Isolamento Estrito de Sessão (Admin vs Aluno)...{$reset}\n";

    // Simula sessão de aluno preexistente
    $_SESSION[AuthService::SESSION_STUDENT_KEY] = [
        'turma_id' => 101,
        'chave'    => 'CHAVE-TURMA-SICOOB-2026',
        'auth_at'  => date('Y-m-d H:i:s'),
    ];

    // Operador se autentica
    $authService->authenticate($testUsername, $testPassword, '127.0.0.1');

    assertTest(isset($_SESSION[AuthService::SESSION_ADMIN_KEY]), "Sessão de operador administrativo inicializada");
    assertTest(isset($_SESSION[AuthService::SESSION_STUDENT_KEY]), "Sessão de aluno preservada intacta (sem sobrescrita)");
    assertTest($_SESSION[AuthService::SESSION_STUDENT_KEY]['chave'] === 'CHAVE-TURMA-SICOOB-2026', "Integridade dos dados da sessão de aluno mantida");

    // Operador faz logout
    AuthService::logout();
    assertTest(!AuthService::isAuthenticated(), "Sessão de operador encerrada com sucesso");
    assertTest(!isset($_SESSION[AuthService::SESSION_ADMIN_KEY]), "Chave do operador removida da sessão");
    assertTest(isset($_SESSION[AuthService::SESSION_STUDENT_KEY]), "Sessão de aluno permanece preservada após logout do operador");

    echo "\n{$amarelo}-> 4. Testando Proteção Anti-CSRF...{$reset}\n";

    $tokenCsrf = AuthService::getCsrfToken();
    assertTest(strlen($tokenCsrf) === 64 && ctype_xdigit($tokenCsrf), "Token anti-CSRF gerado com 64 caracteres hexadecimais criptográficos");
    assertTest(AuthService::validateCsrfToken($tokenCsrf), "Token CSRF legítimo aceito com sucesso");
    assertTest(!AuthService::validateCsrfToken('token_forjado_invalido'), "Token CSRF forjado rejeitado");
    assertTest(!AuthService::validateCsrfToken(''), "Token CSRF vazio rejeitado");
    assertTest(!AuthService::validateCsrfToken(null), "Token CSRF nulo rejeitado");

    echo "\n{$amarelo}-> 5. Testando Proteção contra Motores de Busca e Diretivas de Robôs...{$reset}\n";

    // Checa robots.txt
    $robotsPath = __DIR__ . '/../public/robots.txt';
    assertTest(file_exists($robotsPath), "Arquivo public/robots.txt existe");
    $robotsContent = file_get_contents($robotsPath);
    assertTest(str_contains($robotsContent, 'Disallow: /diario/'), "robots.txt bloqueia expressamente o diretório /diario/");

    // Checa .htaccess em public/diario/
    $htaccessPath = __DIR__ . '/../public/diario/.htaccess';
    assertTest(file_exists($htaccessPath), "Arquivo public/diario/.htaccess existe");
    $htaccessContent = file_get_contents($htaccessPath);
    assertTest(str_contains($htaccessContent, 'Header set X-Robots-Tag "noindex, nofollow'), "Cabeçalho X-Robots-Tag configurado no .htaccess");
    assertTest(str_contains($htaccessContent, 'RewriteRule ^login/?$ login.php'), "Regra de reescrita /diario/login configurada no .htaccess");

    echo "\n{$amarelo}-> 6. Testando Renderização HTML da Tela de Login e Layout Administrativo...{$reset}\n";

    // Renderização do Login
    $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start();
    include __DIR__ . '/../public/diario/login.php';
    $loginHtml = ob_get_clean();

    assertTest(str_contains($loginHtml, 'Futuro'), "Tela de login contém a marca institucional Futuro Fácil");
    assertTest(str_contains($loginHtml, 'name="csrf_token"'), "Formulário de login contém campo oculto do token CSRF");
    assertTest(str_contains($loginHtml, 'name="username"'), "Formulário de login contém campo username");
    assertTest(str_contains($loginHtml, 'name="password"'), "Formulário de login contém campo password");
    assertTest(str_contains($loginHtml, '<meta name="robots" content="noindex, nofollow'), "Tela de login contém meta tag robots noindex, nofollow");

    // Renderização do Layout Base
    ob_start();
    renderAdminLayout(
        title: 'Teste Base',
        activeNav: 'inicio',
        contentHtml: '<div id="teste-conteudo">Área de Conteúdo Operacional</div>',
        user: ['nome' => 'Operador Teste', 'username' => 'admin_teste', 'email' => 'teste@futurofacil.com.br']
    );
    $layoutHtml = ob_get_clean();

    assertTest(str_contains($layoutHtml, 'Calendário'), "Layout base contém atalho operacional para Calendário");
    assertTest(str_contains($layoutHtml, 'Diário & Turmas'), "Layout base contém atalho operacional para Diário & Turmas");
    assertTest(str_contains($layoutHtml, 'Novo Agendamento'), "Layout base contém botão de ação Novo Agendamento");
    assertTest(str_contains($layoutHtml, 'Operador Teste'), "Layout base exibe o nome do operador logado");
    assertTest(str_contains($layoutHtml, 'action="/diario/logout"'), "Layout base contém formulário de logout protegido");
    assertTest(str_contains($layoutHtml, 'name="csrf_token"'), "Logout contém token anti-CSRF na requisição");
    assertTest(str_contains($layoutHtml, 'id="teste-conteudo"'), "Slot de conteúdo filho injetado corretamente no layout");

    // 7. Limpeza
    unset($stmtInsert, $stmtCheckLogin, $authService);
    $pdo = null;
    Database::resetConnection();
    gc_collect_cycles();

    if (file_exists($testDbPath)) {
        @unlink($testDbPath);
    }

    echo "\n{$azul}----------------------------------------------------------------------\n";
    if ($passedCount === $totalCount) {
        echo "{$verde}RESULTADO: 100% DE SUCESSO! ({$passedCount}/{$totalCount} verificações executadas sem falhas).\n";
        echo "Ticket 02 (Autenticação Administrativa e Painel Base) APROVADO!{$reset}\n";
        echo "{$azul}----------------------------------------------------------------------{$reset}\n";
    } else {
        $falhas = $totalCount - $passedCount;
        echo "{$vermelho}RESULTADO: {$falhas} verificação(ões) falharam de {$totalCount}.{$reset}\n";
        echo "{$azul}----------------------------------------------------------------------{$reset}\n";
        exit(1);
    }

} catch (Exception $e) {
    echo "{$vermelho}[ERRO EXCEÇÃO]: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "{$reset}\n";
    exit(1);
}
