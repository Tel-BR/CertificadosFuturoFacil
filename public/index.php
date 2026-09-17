<?php
/**
 * Ponto de Entrada Raiz da Plataforma Futuro Fácil
 * Despacho inteligente para compatibilidade com Apache (.htaccess) e servidor embutido (php -S)
 */

declare(strict_types=1);

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

// 1. Favicon
if ($uri === '/favicon.ico') {
    http_response_code(204);
    exit;
}

// 2. Rota de validação pública: /validar ou /validar/<hash>
if (preg_match('#^/validar(?:/([A-Fa-f0-9]+))?/?$#', $uri, $m)) {
    if (!empty($m[1])) {
        $_GET['codigo'] = $m[1];
    }
    require __DIR__ . '/validar/index.php';
    exit;
}

// 3. Rotas do diário: /diario/<slug>
if (preg_match('#^/diario(?:/([a-zA-Z0-9_-]+))?/?$#', $uri, $m)) {
    $slug = $m[1] ?? 'index';
    $file = __DIR__ . '/diario/' . $slug . '.php';
    if (file_exists($file)) {
        require $file;
        exit;
    }
}

// 4. Raiz padrão: redireciona para /diario/
header('Location: /diario/');
exit;
