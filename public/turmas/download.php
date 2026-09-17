<?php
/**
 * Controlador de Download Seguro de Materiais Didáticos
 * Plataforma Integrada de Gestão Pedagógica, Diário e Certificados Futuro Fácil
 * 
 * Regras de Segurança (ADR-0008 / Costura de Teste 2):
 * - Intermedeia 100% dos downloads de materiais protegidos
 * - Rejeita com HTTP 403 Forbidden qualquer tentativa de acesso não autenticado
 * - Garante isolamento estrito: aluno da Turma A não baixa materiais da Turma B
 * - Permite acesso de auditoria ao operador administrativo autenticado
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/Database.php';
require_once __DIR__ . '/../../src/Services/AuthService.php';
require_once __DIR__ . '/../../src/Services/MaterialService.php';

use FuturoFacil\Config\Database;
use FuturoFacil\Services\AuthService;
use FuturoFacil\Services\MaterialService;

AuthService::startSecureSession();

$materialId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($materialId <= 0) {
    http_response_code(400);
    echo "Identificador de material inválido.";
    exit;
}

$isAdmin = AuthService::isAuthenticated();
$isStudent = AuthService::isStudentAuthenticated();

if (!$isAdmin && !$isStudent) {
    http_response_code(403);
    echo "Acesso negado. É necessário autenticar-se com a Chave de Acesso da Turma para baixar materiais.";
    exit;
}

$studentTurmaId = null;
if ($isStudent) {
    $studentData = AuthService::getAuthenticatedStudentTurma();
    $studentTurmaId = (int)($studentData['turma_id'] ?? 0);
}

try {
    $pdo = Database::getConnection();
    $materialService = new MaterialService($pdo);
    $materialService->streamMaterialDownload($materialId, $studentTurmaId, $isAdmin);
} catch (Throwable $e) {
    http_response_code(500);
    echo "Erro ao processar download do material: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    exit;
}
