<?php
/**
 * Costura HTTP do Ticket 07: arquivos privados não são servidos pelo php -S.
 */

declare(strict_types=1);

$repoDir = dirname(__DIR__);
$privateDb = $repoDir . '/router_regression.db';
$publicDb = $repoDir . '/public/router_regression.db';
$protectedMaterial = $repoDir . '/public/turmas/arquivos/router_regression.pdf';
$failures = [];
$checks = 0;

function assertHttpStatus(string $label, int $actual, int $expected): void
{
    global $checks, $failures;
    $checks++;
    if ($actual !== $expected) {
        $failures[] = "$label: HTTP $actual; esperado HTTP $expected";
    }
}

function requestStatus(int $port, string $method, string $path): int
{
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'ignore_errors' => true,
            'timeout' => 3,
            'follow_location' => 0,
        ],
    ]);
    @file_get_contents("http://127.0.0.1:$port$path", false, $context);
    $statusLine = $http_response_header[0] ?? '';
    return preg_match('/^HTTP\/\S+\s+(\d{3})/', $statusLine, $matches)
        ? (int)$matches[1]
        : 0;
}

function runServerCase(string $label, array $arguments, array $protectedPaths, string $repoDir): void
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    if ($socket === false) {
        throw new RuntimeException("Não foi possível reservar porta: $errorMessage");
    }
    $address = stream_socket_get_name($socket, false);
    $port = (int)substr((string)strrchr((string)$address, ':'), 1);
    fclose($socket);

    $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $command = array_merge([PHP_BINARY, '-S', "127.0.0.1:$port"], $arguments);
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['file', $nullDevice, 'w'],
        2 => ['file', $nullDevice, 'w'],
    ], $pipes, $repoDir, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException("Não foi possível iniciar o servidor: $label");
    }

    try {
        fclose($pipes[0]);
        $ready = false;
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $connection = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.1);
            if ($connection !== false) {
                fclose($connection);
                $ready = true;
                break;
            }
            usleep(50000);
        }
        if (!$ready) {
            throw new RuntimeException("Servidor não iniciou: $label");
        }

        foreach ($protectedPaths as $path) {
            foreach (['GET', 'HEAD'] as $method) {
                assertHttpStatus("$label $method $path", requestStatus($port, $method, $path), 403);
            }
        }
    } finally {
        proc_terminate($process);
        proc_close($process);
    }
}

try {
    file_put_contents($privateDb, 'fixture privada');
    file_put_contents($publicDb, 'fixture publica');
    file_put_contents($protectedMaterial, '%PDF-1.4 fixture');

    runServerCase('raiz + router.php', ['router.php'], [
        '/router_regression.db',
        '/public/turmas/arquivos/router_regression.pdf',
    ], $repoDir);
    runServerCase('public + router.php', ['-t', 'public', 'router.php'], [
        '/router_regression.db',
        '/turmas/arquivos/router_regression.pdf',
    ], $repoDir);
    runServerCase('public + public/router.php', ['-t', 'public', 'public/router.php'], [
        '/router_regression.db',
        '/turmas/arquivos/router_regression.pdf',
    ], $repoDir);

    // Apache não está disponível no ambiente de teste; valida o contrato de
    // configuração nos dois document roots possíveis da implantação.
    foreach ([$repoDir . '/.htaccess', $repoDir . '/public/.htaccess'] as $apacheConfig) {
        $contents = @file_get_contents($apacheConfig);
        $configured = $contents !== false
            && preg_match('/<FilesMatch\s+"[^"]*db[^"]*">\s*Require all denied\s*<\/FilesMatch>/s', $contents) === 1;
        $checks++;
        if (!$configured) {
            $failures[] = "$apacheConfig: bloqueio Apache para .db ausente";
        }
    }

    echo "Ticket 07 HTTP: " . ($checks - count($failures)) . "/$checks verificações passaram.\n";
    foreach ($failures as $failure) {
        echo "FALHA: $failure\n";
    }
    if ($failures !== []) {
        exit(1);
    }
} catch (Throwable $error) {
    fwrite(STDERR, "ERRO: {$error->getMessage()}\n");
    exit(1);
} finally {
    @unlink($privateDb);
    @unlink($publicDb);
    @unlink($protectedMaterial);
}
