<?php
/**
 * Despachante único do servidor embutido. Só entrega arquivos dentro de public/.
 */

declare(strict_types=1);

$publicDir = __DIR__;
$uri = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
$uri = str_replace('\\', '/', $uri);

// O php -S não aplica .htaccess. Bloqueie estes caminhos antes de servir arquivos.
if (
    str_contains($uri, "\0")
    || preg_match('#(?:^|/)\.\.(?:/|$)#', $uri)
    || preg_match('#(?:^|/)\.[^/]+#', $uri)
    || $uri === '/public'
    || str_starts_with($uri, '/public/')
    || $uri === '/router.php'
    || preg_match('#(?:^|/)(?:SECRETS|credentials\.local\.php|\.env.*)(?:/|$)#i', $uri)
    || preg_match('#^/turmas/arquivos(?:/|$)#i', $uri)
    || preg_match('#\.(?:db|sqlite|sqlite3|ini|log|bak|sql|sh|md|yml|yaml)(?:-(?:wal|shm|journal))?(?:/|$)#i', $uri)
) {
    http_response_code(403);
    echo 'Acesso Proibido';
    exit;
}

// Cabeçalhos HTTP Defensivos Globais (Ticket 07b / OWASP)
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

// Arquivos físicos são resolvidos no diretório público, nunca no document root
// informado ao php -S. Assim, o router da raiz também não expõe arquivos do repo.
$targetFile = realpath($publicDir . $uri);
$publicRoot = str_replace('\\', '/', (string)realpath($publicDir));
$resolvedTarget = str_replace('\\', '/', (string)$targetFile);
if ($uri !== '/' && $targetFile !== false && is_file($targetFile)) {
    if (!str_starts_with(strtolower($resolvedTarget), strtolower($publicRoot) . '/')) {
        http_response_code(403);
        exit;
    }

    if (str_ends_with(strtolower($targetFile), '.php')) {
        require $targetFile;
        exit;
    }

    $extension = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));
    $mime = match ($extension) {
        'css' => 'text/css',
        'js' => 'text/javascript',
        'svg' => 'image/svg+xml',
        'txt' => 'text/plain; charset=UTF-8',
        'pdf' => 'application/pdf',
        default => mime_content_type($targetFile) ?: 'application/octet-stream',
    };
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($targetFile));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        readfile($targetFile);
    }
    exit;
}

if ($uri === '/favicon.ico') {
    http_response_code(204);
    exit;
}

if ($uri === '/' || $uri === '') {
    header('Location: /diario/');
    exit;
}

if (preg_match('#^/validar(?:/([A-Fa-f0-9]+))?/?$#', $uri, $matches)) {
    if (!empty($matches[1])) {
        $_GET['codigo'] = $matches[1];
    }
    require $publicDir . '/validar/index.php';
    exit;
}

if ($uri === '/diario' || $uri === '/diario/') {
    require $publicDir . '/diario/index.php';
    exit;
}

if (preg_match('#^/diario/turmas/novo/?$#', $uri)) {
    require $publicDir . '/diario/turma_form.php';
    exit;
}

if (preg_match('#^/diario/turma/editar(?:/(\d+))?/?$#', $uri, $matches)) {
    if (!empty($matches[1])) {
        $_GET['id'] = (int)$matches[1];
    }
    require $publicDir . '/diario/turma_form.php';
    exit;
}

if (preg_match('#^/diario/([a-zA-Z0-9_-]+)/?$#', $uri, $matches)) {
    $targetScript = $publicDir . '/diario/' . $matches[1] . '.php';
    if (is_file($targetScript)) {
        require $targetScript;
        exit;
    }
}

if (preg_match('#^/turmas/download(?:\.php)?/?$#', $uri)) {
    require $publicDir . '/turmas/download.php';
    exit;
}

if (preg_match('#^/turmas/logout(?:\.php)?/?$#', $uri)) {
    require $publicDir . '/turmas/logout.php';
    exit;
}

if ($uri === '/turmas' || $uri === '/turmas/') {
    require $publicDir . '/turmas/index.php';
    exit;
}

if (preg_match('#^/turmas/([a-zA-Z0-9_-]+)/?$#', $uri, $matches)) {
    $_GET['turma'] = $matches[1];
    require $publicDir . '/turmas/index.php';
    exit;
}

http_response_code(404);
echo 'Não encontrado';
