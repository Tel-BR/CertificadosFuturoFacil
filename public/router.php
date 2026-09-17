<?php
/**
 * Router para o Servidor Embutido do PHP (php -S)
 * Emula as regras de reescrita do Apache (.htaccess) para desenvolvimento local
 */

declare(strict_types=1);

$publicDir = is_dir(__DIR__ . '/public') ? __DIR__ . '/public' : __DIR__;
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

// 1. Blindagem de segurança: pasta física de arquivos de turmas é estritamente 403 Forbidden (ADR-0008)
if (str_starts_with($uri, '/turmas/arquivos')) {
    http_response_code(403);
    echo "Acesso Proibido: Downloads de materiais didáticos devem ser mediados pelo controlador autenticado.";
    exit;
}

// 2. Arquivo estático real existente dentro de public/
$targetFile = $publicDir . $uri;
if ($uri !== '/' && file_exists($targetFile) && !is_dir($targetFile)) {
    return false; // Deixa o PHP servir o arquivo estático diretamente
}

// 3. Favicon silencioso se não existir
if ($uri === '/favicon.ico') {
    http_response_code(204);
    exit;
}

// 4. Raiz / -> Redireciona para /diario/
if ($uri === '/' || $uri === '') {
    header('Location: /diario/');
    exit;
}

// 5. Rota /validar e /validar/<hash>
if (preg_match('#^/validar(?:/([A-Fa-f0-9]+))?/?$#', $uri, $matches)) {
    if (!empty($matches[1])) {
        $_GET['codigo'] = $matches[1];
    }
    require $publicDir . '/validar/index.php';
    exit;
}

// 6. Rota /diario ou /diario/
if ($uri === '/diario' || $uri === '/diario/') {
    require $publicDir . '/diario/index.php';
    exit;
}

// 7. Rotas amigáveis de /diario/<slug> (login, logout, turmas, turma, calendario, aula, fechamento)
if (preg_match('#^/diario/([a-zA-Z0-9_-]+)/?$#', $uri, $matches)) {
    $targetScript = $publicDir . '/diario/' . $matches[1] . '.php';
    if (file_exists($targetScript)) {
        require $targetScript;
        exit;
    }
}

// 8. Rota /turmas/download
if (preg_match('#^/turmas/download(?:\.php)?/?$#', $uri)) {
    require $publicDir . '/turmas/download.php';
    exit;
}

// 9. Rota /turmas/logout
if (preg_match('#^/turmas/logout(?:\.php)?/?$#', $uri)) {
    require $publicDir . '/turmas/logout.php';
    exit;
}

// 10. Rota /turmas ou /turmas/
if ($uri === '/turmas' || $uri === '/turmas/') {
    require $publicDir . '/turmas/index.php';
    exit;
}

// 11. Rota amigável de turma /turmas/<slug>
if (preg_match('#^/turmas/([a-zA-Z0-9_-]+)/?$#', $uri, $matches)) {
    $_GET['turma'] = $matches[1];
    require $publicDir . '/turmas/index.php';
    exit;
}

// 12. Se nada combinou, tenta servir como arquivo estático ou 404 padrão
return false;
