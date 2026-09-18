<?php
/**
 * Auto-Save Assíncrono do Modo Aula — Chamada Tátil e Plano Dinâmico
 * Plataforma Integrada de Gestão Pedagógica, Diário e Certificados Futuro Fácil
 *
 * Endpoint JSON consumido via fetch() pelo Modo Aula (public/diario/aula.php)
 * com debounce de 600ms. Reaproveita a mesma gravação transacional atômica de
 * AttendanceService::saveAttendance() usada pelo formulário clássico (fallback
 * <noscript>), garantindo que ambos os caminhos persistam de forma idêntica.
 *
 * Ao final, devolve a frequência acumulada e o indicador de risco (<75%)
 * já recalculados por aluno, para que a interface atualize os selos em
 * tempo real sem precisar recarregar a página.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/Database.php';
require_once __DIR__ . '/../../src/Services/AuthService.php';
require_once __DIR__ . '/../../src/Services/AttendanceService.php';

use FuturoFacil\Services\AuthService;
use FuturoFacil\Services\AttendanceService;

// Definida com function_exists para permitir múltiplas inclusões deste
// arquivo no mesmo processo (ex.: suíte de testes automatizados) sem erro
// de redeclaração.
if (!function_exists('futurofacil_aula_autosave_respond')) {
    function futurofacil_aula_autosave_respond(int $status, array $payload): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
        }
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
}

AuthService::startSecureSession();

if (!AuthService::isAuthenticated()) {
    futurofacil_aula_autosave_respond(401, [
        'success' => false,
        'error'   => 'auth_required',
        'message' => 'Sessão expirada. Recarregue a página e faça login novamente.',
    ]);
    return;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    futurofacil_aula_autosave_respond(405, [
        'success' => false,
        'error'   => 'method_not_allowed',
    ]);
    return;
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!AuthService::validateCsrfToken($csrfToken)) {
    futurofacil_aula_autosave_respond(403, [
        'success' => false,
        'error'   => 'csrf_invalid',
        'message' => 'Token de segurança inválido ou expirado. Recarregue a página.',
    ]);
    return;
}

$encontroId = isset($_POST['encontro_id']) ? (int)$_POST['encontro_id'] : 0;
if ($encontroId <= 0) {
    futurofacil_aula_autosave_respond(422, [
        'success' => false,
        'error'   => 'invalid_encontro',
        'message' => 'Encontro inválido ou não informado.',
    ]);
    return;
}

$conteudoMinistrado = trim((string)($_POST['conteudo_ministrado'] ?? ''));
$presencasRaw = $_POST['presencas'] ?? [];
$presencas = is_array($presencasRaw) ? $presencasRaw : [];
$abonado = array_key_exists('abonado', $_POST) ? (bool)$_POST['abonado'] : null;

try {
    $service = new AttendanceService();
    $saveResult = $service->saveAttendance($encontroId, $conteudoMinistrado, $presencas, $abonado);
    $attendanceList = $service->getAttendanceList($encontroId);

    $students = [];
    $totalRisco = 0;
    foreach ($attendanceList as $aluno) {
        if (!empty($aluno['is_risk'])) {
            $totalRisco++;
        }
        $students[] = [
            'aluno_id'             => (int)$aluno['aluno_id'],
            'presente'             => (int)$aluno['presente'],
            'frequencia_acumulada' => (float)$aluno['frequencia_acumulada'],
            'is_risk'              => (bool)$aluno['is_risk'],
        ];
    }

    futurofacil_aula_autosave_respond(200, [
        'success'         => true,
        'encontro_id'     => $encontroId,
        'saved_at'        => date('H:i:s'),
        'total_presentes' => $saveResult['total_presentes'],
        'total_faltas'    => $saveResult['total_faltas'],
        'total_risco'     => $totalRisco,
        'students'        => $students,
    ]);
} catch (InvalidArgumentException $e) {
    futurofacil_aula_autosave_respond(422, [
        'success' => false,
        'error'   => 'invalid_data',
        'message' => $e->getMessage(),
    ]);
} catch (Throwable $e) {
    futurofacil_aula_autosave_respond(500, [
        'success' => false,
        'error'   => 'server_error',
        'message' => 'Erro ao gravar diário de classe. Tente novamente.',
    ]);
}
