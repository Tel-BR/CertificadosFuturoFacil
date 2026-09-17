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
use FuturoFacil\Services\CertificatePdfService;

AuthService::startSecureSession();

// 1. Download de Certificado Individual em PDF (Ticket 07c / O1)
if (isset($_GET['action']) && $_GET['action'] === 'certificado') {
    $certId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($certId <= 0) {
        http_response_code(400);
        echo "Identificador de certificado inválido.";
        exit;
    }

    $isStudent = AuthService::isStudentAuthenticated();
    $isAdmin = AuthService::isAuthenticated();
    if (!$isStudent && !$isAdmin) {
        http_response_code(403);
        echo "Acesso negado. Autentique-se na turma para baixar seu certificado.";
        exit;
    }

    try {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT rc.*, t.curso_nome, t.carga_horaria, t.data_inicio, t.data_conclusao, t.instrutor, t.cidade, t.ementa
            FROM registros_certificados rc
            INNER JOIN turmas t ON t.id = rc.turma_id
            WHERE rc.id = ?
        ");
        $stmt->execute([$certId]);
        $reg = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$reg) {
            http_response_code(404);
            echo "Certificado não localizado.";
            exit;
        }

        // Se for aluno, garante que o certificado pertence à turma ativa dele
        if ($isStudent && !$isAdmin) {
            $studentData = AuthService::getAuthenticatedStudentTurma();
            if ((int)($studentData['turma_id'] ?? 0) !== (int)$reg['turma_id']) {
                http_response_code(403);
                echo "Acesso negado. Este certificado pertence a outra turma.";
                exit;
            }
        }

        require_once __DIR__ . '/../../src/Services/CertificatePdfService.php';
        $pdfService = new CertificatePdfService();

        $alunoMock = [
            'nome_completo' => $reg['aluno_nome'],
            'cpf_mascarado' => $reg['aluno_cpf'],
        ];
        $turmaMock = [
            'id'            => $reg['turma_id'],
            'curso_nome'    => $reg['curso_nome'],
            'carga_horaria' => $reg['carga_horaria'],
            'data_inicio'   => $reg['data_inicio'],
            'data_conclusao'=> $reg['data_conclusao'],
            'instrutor'     => $reg['instrutor'],
            'cidade'        => $reg['cidade'],
            'ementa'        => $reg['ementa'] ?? '',
        ];

        $pdfContent = $pdfService->renderCertificateDuplex($alunoMock, $turmaMock, $reg);
        $filename = 'Certificado_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $reg['aluno_nome']) . '.pdf';

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdfContent));
        echo $pdfContent;
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo "Erro ao gerar certificado em PDF: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        exit;
    }
}

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
