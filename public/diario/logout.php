<?php
/**
 * Endpoint de Logout do Operador Administrativo (/diario/logout)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/Database.php';
require_once __DIR__ . '/../../src/Services/AuthService.php';

use FuturoFacil\Services\AuthService;

if (!headers_sent()) {
    header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
}

AuthService::startSecureSession();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = (string)($_POST['csrf_token'] ?? '');
    // Se token for válido, encerra a sessão
    if (AuthService::validateCsrfToken($csrfToken)) {
        AuthService::logout();
    }
} else {
    // Também permite logout direto via GET com redirecionamento limpo
    AuthService::logout();
}

if (!headers_sent()) {
    header('Location: /diario/login');
}
exit;
