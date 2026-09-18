<?php
/**
 * Serviço de Gestão do Ciclo de Vida de Turmas, Lixeira e Soft Delete
 * Plataforma Integrada de Gestão Pedagógica, Diário e Certificados Futuro Fácil
 */

declare(strict_types=1);

namespace FuturoFacil\Services;

require_once __DIR__ . '/../Config/Database.php';
require_once __DIR__ . '/CalendarService.php';

use FuturoFacil\Config\Database;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

class TurmaService
{
    private PDO $pdo;
    private CalendarService $calendarService;
    private static bool $schemaEnsured = false;

    public function __construct(?PDO $pdo = null, ?CalendarService $calendarService = null)
    {
        $this->pdo = $pdo ?? Database::getConnection();
        $this->calendarService = $calendarService ?? new CalendarService($this->pdo);
    }

    /**
     * Garante de forma idempotente a existência da coluna deleted_at e índices
     * em turmas e encontros tanto em SQLite quanto no MariaDB.
     */
    public function ensureSchema(): void
    {
        if (self::$schemaEnsured) {
            return;
        }

        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        // 1. Tabela turmas
        try {
            if ($driver === 'sqlite') {
                $cols = $this->pdo->query("PRAGMA table_info(turmas)")->fetchAll(PDO::FETCH_ASSOC);
                $names = array_column($cols, 'name');
                if (!in_array('deleted_at', $names, true)) {
                    $this->pdo->exec("ALTER TABLE turmas ADD COLUMN deleted_at TEXT NULL DEFAULT NULL");
                    $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_turmas_deleted_at ON turmas(deleted_at)");
                }
            } else {
                $check = $this->pdo->query("
                    SELECT COLUMN_NAME 
                    FROM INFORMATION_SCHEMA.COLUMNS 
                    WHERE TABLE_SCHEMA = DATABASE() 
                      AND TABLE_NAME = 'turmas' 
                      AND COLUMN_NAME = 'deleted_at'
                ")->fetchColumn();
                if (!$check) {
                    $this->pdo->exec("ALTER TABLE `turmas` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL AFTER `ementa`");
                    $this->pdo->exec("CREATE INDEX `idx_turmas_deleted_at` ON `turmas` (`deleted_at`)");
                }
            }
        } catch (Throwable) {
            // Ignora se coluna já existir ou falha não impeditiva
        }

        // 2. Tabela encontros
        try {
            if ($driver === 'sqlite') {
                $cols = $this->pdo->query("PRAGMA table_info(encontros)")->fetchAll(PDO::FETCH_ASSOC);
                $names = array_column($cols, 'name');
                if (!in_array('deleted_at', $names, true)) {
                    $this->pdo->exec("ALTER TABLE encontros ADD COLUMN deleted_at TEXT NULL DEFAULT NULL");
                    $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_encontros_deleted_at ON encontros(deleted_at)");
                }
            } else {
                $check = $this->pdo->query("
                    SELECT COLUMN_NAME 
                    FROM INFORMATION_SCHEMA.COLUMNS 
                    WHERE TABLE_SCHEMA = DATABASE() 
                      AND TABLE_NAME = 'encontros' 
                      AND COLUMN_NAME = 'deleted_at'
                ")->fetchColumn();
                if (!$check) {
                    $this->pdo->exec("ALTER TABLE `encontros` ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL AFTER `abonado`");
                    $this->pdo->exec("CREATE INDEX `idx_encontros_deleted_at` ON `encontros` (`deleted_at`)");
                }
            }
        } catch (Throwable) {
            // Ignora se coluna já existir ou falha não impeditiva
        }

        self::$schemaEnsured = true;
    }

    /**
     * Move uma turma para a Lixeira (Soft Delete), marcando deleted_at = NOW().
     * Replica a marcação nos encontros para desocupação atômica do calendário.
     */
    public function moveToTrash(int $turmaId): bool
    {
        $this->ensureSchema();

        $stmtTurma = $this->pdo->prepare("SELECT id FROM turmas WHERE id = ?");
        $stmtTurma->execute([$turmaId]);
        if (!$stmtTurma->fetch()) {
            throw new InvalidArgumentException("Turma ID {$turmaId} não encontrada.");
        }

        $now = date('Y-m-d H:i:s');

        $this->pdo->beginTransaction();
        try {
            $stmtUpdateTurma = $this->pdo->prepare("UPDATE turmas SET deleted_at = ? WHERE id = ?");
            $stmtUpdateTurma->execute([$now, $turmaId]);

            $stmtUpdateEncontros = $this->pdo->prepare("UPDATE encontros SET deleted_at = ? WHERE turma_id = ?");
            $stmtUpdateEncontros->execute([$now, $turmaId]);

            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new RuntimeException("Falha ao mover turma para a Lixeira: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Valida se algum encontro da turma colide com horários de OUTRAS turmas ativas ou bloqueios.
     */
    public function checkRestoreConflicts(int $turmaId): array
    {
        $this->ensureSchema();

        $stmtEnc = $this->pdo->prepare("
            SELECT id, numero_encontro, data_encontro, turno, tipo 
            FROM encontros 
            WHERE turma_id = ?
            ORDER BY data_encontro ASC, numero_encontro ASC
        ");
        $stmtEnc->execute([$turmaId]);
        $encontros = $stmtEnc->fetchAll(PDO::FETCH_ASSOC);

        $conflicts = [];

        foreach ($encontros as $enc) {
            $data = $enc['data_encontro'];
            $turno = $enc['turno'];
            $encId = (int)$enc['id'];

            $conflito = $this->calendarService->checkConflict($data, $turno, $encId, $turmaId);
            if ($conflito !== null) {
                $conflicts[] = [
                    'encontro_id'     => $encId,
                    'numero_encontro' => (int)$enc['numero_encontro'],
                    'data'            => $data,
                    'data_formatada'  => date('d/m/Y', strtotime($data)),
                    'turno'           => $turno,
                    'tipo'            => $enc['tipo'],
                    'motivo'          => $conflito['motivo'] ?? 'Choque de horário no turno.',
                    'mensagem'        => $conflito['mensagem'] ?? "Choque no dia " . date('d/m/Y', strtotime($data)) . " ({$turno})",
                ];
            }
        }

        return $conflicts;
    }

    /**
     * Restaura uma turma da lixeira de volta para a grade ativa.
     * Bloqueia se houver colisão de horário com turmas ativas.
     */
    public function restoreFromTrash(int $turmaId): array
    {
        $this->ensureSchema();

        $stmtTurma = $this->pdo->prepare("SELECT id, codigo_turma, curso_nome, deleted_at FROM turmas WHERE id = ?");
        $stmtTurma->execute([$turmaId]);
        $turma = $stmtTurma->fetch(PDO::FETCH_ASSOC);

        if (!$turma) {
            return [
                'success'   => false,
                'conflicts' => [],
                'message'   => "Turma ID {$turmaId} não encontrada.",
            ];
        }

        if (empty($turma['deleted_at'])) {
            return [
                'success'   => true,
                'conflicts' => [],
                'message'   => "A turma já está ativa na grade.",
            ];
        }

        // Verifica choques de capacidade
        $conflicts = $this->checkRestoreConflicts($turmaId);
        if (!empty($conflicts)) {
            $diasFormatados = array_unique(array_column($conflicts, 'data_formatada'));
            $listaDias = implode(', ', $diasFormatados);

            return [
                'success'   => false,
                'conflicts' => $conflicts,
                'message'   => "Não foi possível restaurar a turma '{$turma['curso_nome']}': " .
                               "foram detectados choques de horário nos dias {$listaDias} com turmas ativas na grade.",
            ];
        }

        // Sem conflitos: restaura atomicamente
        $this->pdo->beginTransaction();
        try {
            $stmtRestoreTurma = $this->pdo->prepare("UPDATE turmas SET deleted_at = NULL WHERE id = ?");
            $stmtRestoreTurma->execute([$turmaId]);

            $stmtRestoreEncontros = $this->pdo->prepare("UPDATE encontros SET deleted_at = NULL WHERE turma_id = ?");
            $stmtRestoreEncontros->execute([$turmaId]);

            $this->pdo->commit();

            return [
                'success'   => true,
                'conflicts' => [],
                'message'   => "Turma '{$turma['curso_nome']}' restaurada com sucesso para a grade ativa!",
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new RuntimeException("Falha ao restaurar turma: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Expurga definitivamente a turma do banco de dados (exclusão física permanente).
     * Blindagem: proíbe exclusão se houver certificados vinculados no Livro de Registro Digital.
     */
    public function expungeTurma(int $turmaId): array
    {
        $this->ensureSchema();

        $stmtTurma = $this->pdo->prepare("SELECT id, codigo_turma, curso_nome, deleted_at FROM turmas WHERE id = ?");
        $stmtTurma->execute([$turmaId]);
        $turma = $stmtTurma->fetch(PDO::FETCH_ASSOC);

        if (!$turma) {
            return [
                'success' => false,
                'message' => "Turma ID {$turmaId} não encontrada.",
            ];
        }

        // Deve estar previamente na Lixeira
        if (empty($turma['deleted_at'])) {
            return [
                'success' => false,
                'message' => "Não é permitido excluir definitivamente uma turma ativa. Mova a turma para a Lixeira antes de realizar o expurgo.",
            ];
        }

        // 1. Blindagem de Fé Pública: Proíbe se houver registros de certificados
        $stmtCerts = $this->pdo->prepare("
            SELECT COUNT(*) FROM registros_certificados 
            WHERE turma_id = ? OR lote_id = ? OR lote_id = ?
        ");
        $stmtCerts->execute([$turmaId, $turma['codigo_turma'], 'TURMA-' . $turmaId]);
        $totalCerts = (int)$stmtCerts->fetchColumn();

        if ($totalCerts > 0) {
            return [
                'success' => false,
                'message' => "Não é permitido excluir definitivamente a turma '{$turma['curso_nome']}': " .
                             "existem {$totalCerts} certificado(s) oficiais emitidos vinculados a ela no Livro de Registro Digital.",
            ];
        }

        // 2. Exclusão permanente em cascata
        $this->pdo->beginTransaction();
        try {
            // Remove frequencias associadas aos encontros da turma
            $this->pdo->prepare("
                DELETE FROM frequencias 
                WHERE encontro_id IN (SELECT id FROM encontros WHERE turma_id = ?)
            ")->execute([$turmaId]);

            // Remove materiais da turma
            $this->pdo->prepare("DELETE FROM materiais_turma WHERE turma_id = ?")->execute([$turmaId]);

            // Remove alunos
            $this->pdo->prepare("DELETE FROM alunos WHERE turma_id = ?")->execute([$turmaId]);

            // Remove encontros
            $this->pdo->prepare("DELETE FROM encontros WHERE turma_id = ?")->execute([$turmaId]);

            // Remove turma
            $this->pdo->prepare("DELETE FROM turmas WHERE id = ?")->execute([$turmaId]);

            $this->pdo->commit();

            return [
                'success' => true,
                'message' => "Turma '{$turma['curso_nome']}' e seus registros foram excluídos definitivamente.",
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new RuntimeException("Falha ao expurgar turma: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Retorna a listagem padrão de turmas ativas (deleted_at IS NULL).
     */
    public function getActiveTurmas(): array
    {
        $this->ensureSchema();

        $stmt = $this->pdo->query("
            SELECT t.*,
                   (SELECT COUNT(*) FROM alunos a WHERE a.turma_id = t.id) as total_alunos,
                   (SELECT COUNT(*) FROM encontros e WHERE e.turma_id = t.id AND e.tipo = 'aula' AND e.deleted_at IS NULL) as total_aulas
            FROM turmas t
            WHERE t.deleted_at IS NULL
            ORDER BY 
                CASE t.status 
                    WHEN 'em_andamento' THEN 1 
                    WHEN 'prevista' THEN 2 
                    ELSE 3 
                END,
                t.data_inicio DESC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retorna a listagem de turmas descartadas na Lixeira (deleted_at IS NOT NULL).
     */
    public function getTrashedTurmas(): array
    {
        $this->ensureSchema();

        $stmt = $this->pdo->query("
            SELECT t.*,
                   (SELECT COUNT(*) FROM alunos a WHERE a.turma_id = t.id) as total_alunos,
                   (SELECT COUNT(*) FROM encontros e WHERE e.turma_id = t.id AND e.tipo = 'aula') as total_aulas
            FROM turmas t
            WHERE t.deleted_at IS NOT NULL
            ORDER BY t.deleted_at DESC
        ");

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $ts = !empty($r['deleted_at']) ? strtotime($r['deleted_at']) : false;
            $r['deleted_at_formatado'] = $ts ? date('d/m/Y \à\s H:i', $ts) : 'Data indisponível';
        }
        unset($r);

        return $rows;
    }

    /**
     * Retorna a contagem de turmas na Lixeira para alimentar o badge visual das abas.
     */
    public function getTrashCount(): int
    {
        $this->ensureSchema();

        $stmt = $this->pdo->query("SELECT COUNT(*) FROM turmas WHERE deleted_at IS NOT NULL");
        return (int)$stmt->fetchColumn();
    }

    /**
     * Retorna dados completos de uma turma por ID.
     */
    public function getTurmaById(int $turmaId): ?array
    {
        $this->ensureSchema();

        $stmt = $this->pdo->prepare("SELECT * FROM turmas WHERE id = ?");
        $stmt->execute([$turmaId]);
        $turma = $stmt->fetch(PDO::FETCH_ASSOC);

        return $turma ?: null;
    }
}
