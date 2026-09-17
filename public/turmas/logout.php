<?php
/**
 * Logout da Sessão do Aluno no Portal de Turmas
 * Plataforma Integrada de Gestão Pedagógica, Diário e Certificados Futuro Fácil
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/Services/AuthService.php';

use FuturoFacil\Services\AuthService;

AuthService::logoutStudent();

$redirectUrl = '/turmas';
if (!empty($_GET['redirect'])) {
    $cleanRedirect = filter_var($_GET['redirect'], FILTER_SANITIZE_URL);
    if ($cleanRedirect && str_starts_with($cleanRedirect, '/turmas')) {
        $redirectUrl = $cleanRedirect;
    }
}

header("Location: {$redirectUrl}");
exit;
