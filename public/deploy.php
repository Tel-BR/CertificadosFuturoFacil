<?php
/**
 * Endpoint de Deploy e Atualização Contínua em Staging (ADR-0005)
 * Plataforma Integrada de Gestão Pedagógica, Diário e Certificados Futuro Fácil
 * 
 * Regras de Segurança:
 * - Acesso restrito a requisições autenticadas com Bearer DEPLOY_TOKEN
 * - Descompactação atômica via ZipArchive nas pastas canônicas (src/ e public_html/)
 * - Execução controlada de deltas SQL via PDO local (localhost:3306)
 * - Preservação inegociável de src/Config/credentials.local.php
 * - Registro de auditoria em src/logs/deploy.log
 */

declare(strict_types=1);

// Cabeçalhos de API
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');

require_once __DIR__ . '/../src/Config/Database.php';

use FuturoFacil\Config\Database;

$domainRoot = realpath(dirname(__DIR__));
$logsDir = $domainRoot . '/src/logs';
if (!is_dir($logsDir)) {
    @mkdir($logsDir, 0755, true);
}

function logDeploy(string $message): void {
    global $logsDir;
    $logFile = $logsDir . '/deploy.log';
    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[{$timestamp}] {$message}\n", FILE_APPEND);
}

// 1. Obtenção e Validação do Token de Deploy
$configuredToken = null;
$credentialsFile = $domainRoot . '/src/Config/credentials.local.php';
if (file_exists($credentialsFile)) {
    $creds = require $credentialsFile;
    if (is_array($creds) && !empty($creds['DEPLOY_TOKEN'])) {
        $configuredToken = (string)$creds['DEPLOY_TOKEN'];
    }
}
if (!$configuredToken) {
    $configuredToken = getenv('DEPLOY_TOKEN') ?: null;
}

if (!$configuredToken) {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'error'   => 'DEPLOY_TOKEN não configurado no servidor (credentials.local.php).',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// Captura do token enviado no cabeçalho Authorization ou parâmetro
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
$receivedToken = '';
if (preg_match('/Bearer\s+([a-f0-9]{64})/i', $authHeader, $matches)) {
    $receivedToken = $matches[1];
} elseif (!empty($_POST['token'])) {
    $receivedToken = (string)$_POST['token'];
} elseif (!empty($_GET['token'])) {
    $receivedToken = (string)$_GET['token'];
}

if (empty($receivedToken) || !hash_equals($configuredToken, $receivedToken)) {
    http_response_code(403);
    logDeploy("Acesso negado: token de deploy inválido ou ausente. IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'desconhecido'));
    echo json_encode([
        'success' => false,
        'error'   => 'Acesso negado. Token de deploy inválido ou ausente.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// 2. Healthcheck / Status via GET
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    http_response_code(200);
    echo json_encode([
        'success'   => true,
        'status'    => 'ready',
        'message'   => 'Endpoint de deploy pronto para receber pacotes.',
        'timestamp' => date('Y-m-d H:i:s'),
        'server'    => PHP_OS,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método HTTP não permitido. Utilize POST.'], JSON_PRETTY_PRINT);
    exit;
}

// 3. Processamento do Pacote ZIP
$filesExtracted = 0;
if (!empty($_FILES['package']['tmp_name'])) {
    $zipFile = $_FILES['package']['tmp_name'];
    $zip = new ZipArchive();
    $res = $zip->open($zipFile);

    if ($res !== true) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "Falha ao abrir pacote ZIP (código: {$res})."], JSON_PRETTY_PRINT);
        exit;
    }

    // Extrai arquivo por arquivo com proteção estrita
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entryName = $zip->getNameIndex($i);

        // Previne Path Traversal no ZIP
        if (str_contains($entryName, '..') || str_starts_with($entryName, '/') || str_starts_with($entryName, '\\')) {
            continue;
        }

        // NUNCA sobrescreve as credenciais locais do servidor!
        if (str_ends_with(strtolower($entryName), 'credentials.local.php')) {
            continue;
        }

        // Determina caminho absoluto de destino
        $targetPath = $domainRoot . '/' . str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $entryName);

        if (str_ends_with($entryName, '/') || str_ends_with($entryName, '\\')) {
            if (!is_dir($targetPath)) {
                @mkdir($targetPath, 0755, true);
            }
            continue;
        }

        $targetDir = dirname($targetPath);
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0755, true);
        }

        $content = $zip->getFromIndex($i);
        if ($content !== false) {
            file_put_contents($targetPath, $content);
            $filesExtracted++;
        }
    }
    $zip->close();
}

// 4. Execução de Migração SQL Opcional via PDO
$sqlStatementsExecuted = 0;
$sqlErrors = [];
$sqlDelta = $_POST['migration_sql'] ?? null;

if (!empty($_FILES['migration_sql']['tmp_name'])) {
    $sqlDelta = file_get_contents($_FILES['migration_sql']['tmp_name']);
}

if (!empty($sqlDelta) && is_string($sqlDelta)) {
    try {
        $pdo = Database::getConnection();
        // Remove comentários de linha antes de dividir o SQL por ponto e vírgula.
        // Sem essa normalização, um comentário no início de uma migração fazia
        // o primeiro comando ser descartado inteiro pelo filtro abaixo.
        $sqlDeltaSemComentarios = preg_replace('/^\\h*--[^\\r\\n]*(?:\\r?\\n|$)/m', '', $sqlDelta);
        $statements = array_filter(
            array_map('trim', explode(';', $sqlDeltaSemComentarios ?? $sqlDelta)),
            fn($s) => !empty($s)
        );

        $pdo->beginTransaction();
        foreach ($statements as $stmtSql) {
            $pdo->exec($stmtSql);
            $sqlStatementsExecuted++;
        }
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $sqlErrors[] = $e->getMessage();
    }
}

logDeploy("Deploy concluído com sucesso: {$filesExtracted} arquivos extraídos, {$sqlStatementsExecuted} queries SQL executadas.");

http_response_code(empty($sqlErrors) ? 200 : 207);
echo json_encode([
    'success'          => empty($sqlErrors),
    'message'          => 'Deploy processado com sucesso.',
    'files_extracted'  => $filesExtracted,
    'sql_executed'     => $sqlStatementsExecuted,
    'sql_errors'       => $sqlErrors,
    'timestamp'        => date('Y-m-d H:i:s'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
