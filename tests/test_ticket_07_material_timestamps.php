<?php
/**
 * CRUD público de materiais com dialeto que rejeita datetime('now') do SQLite.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Services/MaterialService.php';

use FuturoFacil\Services\MaterialService;

$storageDir = __DIR__ . '/test_temp_material_timestamps';
$passed = 0;
$total = 0;

function assertBehavior(bool $condition, string $label): void
{
    global $passed, $total;
    $total++;
    if ($condition) {
        $passed++;
    }
    echo ($condition ? 'OK' : 'FALHA') . ": $label\n";
}

try {
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec(file_get_contents(__DIR__ . '/../database/schema_sqlite.sql'));
    $pdo->exec("INSERT INTO turmas (
        codigo_turma, curso_nome, data_inicio, data_conclusao, chave_acesso
    ) VALUES ('TURMA-TIMESTAMPS', 'Curso Teste', '2026-09-01', '2026-09-02', 'chave-timestamps')");
    $turmaId = (int)$pdo->lastInsertId();

    // Simula um segundo dialeto SQL: a função SQLite deixa de estar disponível.
    $pdo->sqliteCreateFunction('datetime', static function (): never {
        throw new RuntimeException("datetime('now') não existe no MariaDB");
    }, 1);

    $service = new MaterialService($pdo, $storageDir);
    $materialId = $service->createMaterial([
        'turma_id' => $turmaId,
        'titulo' => 'Apostila digital',
        'url_externa' => 'https://example.org/apostila',
    ]);
    $material = $service->getMaterialById($materialId);
    assertBehavior($materialId > 0 && $material !== null, 'material pode ser cadastrado');
    assertBehavior(preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', (string)$material['created_at']) === 1, 'cadastro recebe timestamp portável');
    assertBehavior($service->updateMaterial($materialId, ['titulo' => 'Apostila revisada']), 'material pode ser alterado');
    assertBehavior($service->toggleMaterialStatus($materialId), 'visibilidade pode ser alternada');
    assertBehavior($service->updateTurmaChaveAcesso($turmaId, 'nova-chave-timestamps'), 'chave da turma pode ser alterada');
    assertBehavior($service->updateTurmaCertificadosModo($turmaId, 'coordenacao'), 'modo do portal pode ser alterado');

    echo "Ticket 07 timestamps: $passed/$total verificações passaram.\n";
    if ($passed !== $total) {
        exit(1);
    }
} catch (Throwable $error) {
    fwrite(STDERR, "FALHA: {$error->getMessage()}\n");
    exit(1);
} finally {
    if (is_dir($storageDir)) {
        @rmdir($storageDir);
    }
}
