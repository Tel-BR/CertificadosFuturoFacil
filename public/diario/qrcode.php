<?php
/**
 * Endpoint de Geração Vetorial de QR Code para Projeção no Telão
 * Plataforma Integrada de Gestão Pedagógica, Diário e Certificados Futuro Fácil
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/Database.php';
require_once __DIR__ . '/../../src/Services/AuthService.php';
require_once __DIR__ . '/../../src/Utils/QRCodeGenerator.php';

use FuturoFacil\Services\AuthService;
use FuturoFacil\Utils\QRCodeGenerator;

// Acesso restrito a operadores autenticados da plataforma
AuthService::requireAuth();

$text = isset($_GET['text']) ? trim((string)$_GET['text']) : '';
if (empty($text)) {
    http_response_code(400);
    echo 'Texto inválido para geração de QR Code.';
    exit;
}

$size = isset($_GET['size']) ? max(100, min(1000, (int)$_GET['size'])) : 360;
$svg = QRCodeGenerator::generateSvg($text, $size);

header('Content-Type: image/svg+xml; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
echo $svg;
