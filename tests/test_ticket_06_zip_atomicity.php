<?php
/**
 * Emissão pelo fluxo público com falha real de escrita do ZIP no diretório temporário.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Services/CertificateService.php';

use FuturoFacil\Services\CertificateService;

if (($argv[1] ?? '') === '--child') {
    $pdo = new PDO('sqlite:' . $argv[2], null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $service = new CertificateService($pdo);
    try {
        $service->emitirCertificadosTurma((int)$argv[3]);
        fwrite(STDERR, "A emissão terminou apesar da falha de escrita do ZIP.\n");
        exit(1);
    } catch (Throwable $error) {
        if (!str_contains($error->getMessage(), 'ZIP')) {
            fwrite(STDERR, "Falha inesperada: {$error->getMessage()}\n");
            exit(2);
        }
        exit(0);
    }
}

$dbPath = __DIR__ . '/test_temp_zip_atomicity_' . bin2hex(random_bytes(4)) . '.db';
$badTempDir = __DIR__ . '/test_missing_zip_' . bin2hex(random_bytes(4));

try {
    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec(file_get_contents(__DIR__ . '/../database/schema_sqlite.sql'));
    $pdo->exec("INSERT INTO turmas (
        codigo_turma, curso_nome, carga_horaria, data_inicio, data_conclusao,
        turno_padrao, status, chave_acesso, ementa
    ) VALUES (
        'TURMA-ZIP-ATOMICITY', 'Excel', 4, '2026-09-01', '2026-09-01',
        'V', 'em_andamento', 'CHAVE-ZIP-ATOMICITY', 'Planilhas e fórmulas'
    )");
    $turmaId = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO encontros (turma_id, numero_encontro, data_encontro, turno, tipo)
        VALUES ($turmaId, 1, '2026-09-01', 'V', 'aula')");
    $encontroId = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO alunos (turma_id, nome_completo, cpf, cpf_limpo)
        VALUES ($turmaId, 'Aluno Teste', '123.456.789-09', '12345678909')");
    $alunoId = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO frequencias (encontro_id, aluno_id, presente)
        VALUES ($encontroId, $alunoId, 1)");

    $command = [PHP_BINARY, '-d', "sys_temp_dir=$badTempDir", __FILE__, '--child', $dbPath, (string)$turmaId];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('Não foi possível iniciar a emissão isolada.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    $service = new CertificateService($pdo);
    $fechamento = $service->getTurmaFechamentoData($turmaId);
    $checks = [
        'falha ocorreu na montagem do ZIP' => $exitCode === 0,
        'nenhum assento foi confirmado' => $fechamento['total_certificados_emitidos'] === 0,
        'turma continua aberta para emissão' => $fechamento['turma']['status'] === 'em_andamento',
    ];
    foreach ($checks as $label => $passed) {
        echo ($passed ? 'OK' : 'FALHA') . ": $label\n";
    }
    if (in_array(false, $checks, true)) {
        fwrite(STDERR, "Subprocesso: $stdout $stderr\n");
        exit(1);
    }
} catch (Throwable $error) {
    fwrite(STDERR, "ERRO: {$error->getMessage()}\n");
    exit(1);
} finally {
    $service = null;
    $pdo = null;
    @unlink($dbPath);
}
