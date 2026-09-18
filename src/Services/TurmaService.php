<?php
/**
 * Serviço de Gestão do Ciclo de Vida de Turmas, Lixeira e Soft Delete
 * Plataforma Integrada de Gestão Pedagógica, Diário e Certificados Futuro Fácil
 */

declare(strict_types=1);

namespace FuturoFacil\Services;

require_once __DIR__ . '/../Config/Database.php';
require_once __DIR__ . '/CalendarService.php';
require_once __DIR__ . '/ValidatorService.php';

use FuturoFacil\Config\Database;
use FuturoFacil\Services\ValidatorService;
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
                $fiscalCols = [
                    'razao_social'       => 'TEXT NULL',
                    'cnpj_tomador'       => 'TEXT NULL',
                    'cidade_uf'          => 'TEXT NULL',
                    'email_financeiro'   => 'TEXT NULL',
                    'numero_os_contrato' => 'TEXT NULL',
                    'tipo_cobranca'      => "TEXT NOT NULL DEFAULT 'hora_aula'",
                    'valor_unitario'     => 'REAL NOT NULL DEFAULT 0.00',
                    'valor_total'        => 'REAL NOT NULL DEFAULT 0.00',
                ];
                foreach ($fiscalCols as $fCol => $fDef) {
                    if (!in_array($fCol, $names, true)) {
                        $this->pdo->exec("ALTER TABLE turmas ADD COLUMN {$fCol} {$fDef}");
                    }
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
                $fiscalColsMysql = [
                    'razao_social'       => 'VARCHAR(255) NULL AFTER `cliente_nome`',
                    'cnpj_tomador'       => 'VARCHAR(20) NULL AFTER `razao_social`',
                    'cidade_uf'          => 'VARCHAR(100) NULL AFTER `cnpj_tomador`',
                    'email_financeiro'   => 'VARCHAR(255) NULL AFTER `cidade_uf`',
                    'numero_os_contrato' => 'VARCHAR(50) NULL AFTER `email_financeiro`',
                    'valor_unitario'     => 'DECIMAL(10, 2) NOT NULL DEFAULT 0.00 AFTER `valor_hora_aula`',
                ];
                foreach ($fiscalColsMysql as $fCol => $fDef) {
                    $checkCol = $this->pdo->query("
                        SELECT COLUMN_NAME 
                        FROM INFORMATION_SCHEMA.COLUMNS 
                        WHERE TABLE_SCHEMA = DATABASE() 
                          AND TABLE_NAME = 'turmas' 
                          AND COLUMN_NAME = '{$fCol}'
                    ")->fetchColumn();
                    if (!$checkCol) {
                        $this->pdo->exec("ALTER TABLE `turmas` ADD COLUMN `{$fCol}` {$fDef}");
                    }
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
            // Ignora falhas não impeditivas
        }

        // 3. Tabela alunos (email e telefone)
        try {
            if ($driver === 'sqlite') {
                $colsAlunos = $this->pdo->query("PRAGMA table_info(alunos)")->fetchAll(PDO::FETCH_ASSOC);
                $namesAlunos = array_column($colsAlunos, 'name');
                if (!in_array('email', $namesAlunos, true)) {
                    $this->pdo->exec("ALTER TABLE alunos ADD COLUMN email TEXT NULL DEFAULT NULL");
                    $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_alunos_email ON alunos(email)");
                }
                if (!in_array('telefone', $namesAlunos, true)) {
                    $this->pdo->exec("ALTER TABLE alunos ADD COLUMN telefone TEXT NULL DEFAULT NULL");
                }
            } else {
                $checkEmail = $this->pdo->query("
                    SELECT COLUMN_NAME 
                    FROM INFORMATION_SCHEMA.COLUMNS 
                    WHERE TABLE_SCHEMA = DATABASE() 
                      AND TABLE_NAME = 'alunos' 
                      AND COLUMN_NAME = 'email'
                ")->fetchColumn();
                if (!$checkEmail) {
                    $this->pdo->exec("ALTER TABLE `alunos` ADD COLUMN `email` VARCHAR(255) NULL AFTER `nome_completo`");
                    $this->pdo->exec("CREATE INDEX `idx_alunos_email` ON `alunos` (`email`)");
                }

                $checkTel = $this->pdo->query("
                    SELECT COLUMN_NAME 
                    FROM INFORMATION_SCHEMA.COLUMNS 
                    WHERE TABLE_SCHEMA = DATABASE() 
                      AND TABLE_NAME = 'alunos' 
                      AND COLUMN_NAME = 'telefone'
                ")->fetchColumn();
                if (!$checkTel) {
                    $this->pdo->exec("ALTER TABLE `alunos` ADD COLUMN `telefone` VARCHAR(20) NULL AFTER `email`");
                }
            }
        } catch (Throwable) {
            // Ignora falhas não impeditivas
        }

        // 4. Tabela solicitacoes_correcao_aluno
        try {
            if ($driver === 'sqlite') {
                $this->pdo->exec("
                    CREATE TABLE IF NOT EXISTS solicitacoes_correcao_aluno (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        turma_id INTEGER NOT NULL,
                        aluno_id INTEGER NOT NULL,
                        nome_proposto TEXT NULL,
                        cpf_proposto TEXT NULL,
                        email_proposto TEXT NULL,
                        telefone_proposto TEXT NULL,
                        motivo TEXT NULL,
                        status TEXT NOT NULL DEFAULT 'pendente',
                        created_at TEXT NOT NULL DEFAULT (datetime('now')),
                        resolved_at TEXT NULL DEFAULT NULL,
                        FOREIGN KEY (turma_id) REFERENCES turmas (id) ON DELETE CASCADE,
                        FOREIGN KEY (aluno_id) REFERENCES alunos (id) ON DELETE CASCADE
                    )
                ");
                $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_solic_turma_status ON solicitacoes_correcao_aluno(turma_id, status)");
            } else {
                $this->pdo->exec("
                    CREATE TABLE IF NOT EXISTS `solicitacoes_correcao_aluno` (
                        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        `turma_id` INT UNSIGNED NOT NULL,
                        `aluno_id` INT UNSIGNED NOT NULL,
                        `nome_proposto` VARCHAR(255) NULL,
                        `cpf_proposto` VARCHAR(20) NULL,
                        `email_proposto` VARCHAR(255) NULL,
                        `telefone_proposto` VARCHAR(20) NULL,
                        `motivo` TEXT NULL,
                        `status` ENUM('pendente', 'aprovada', 'rejeitada') NOT NULL DEFAULT 'pendente',
                        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        `resolved_at` DATETIME NULL DEFAULT NULL,
                        CONSTRAINT `fk_solic_turma` FOREIGN KEY (`turma_id`) REFERENCES `turmas` (`id`) ON DELETE CASCADE,
                        CONSTRAINT `fk_solic_aluno` FOREIGN KEY (`aluno_id`) REFERENCES `alunos` (`id`) ON DELETE CASCADE,
                        INDEX `idx_solic_turma_status` (`turma_id`, `status`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            }
        } catch (Throwable) {
            // Ignora falhas não impeditivas
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

    /**
     * Inferência bidirecional inteligente de horários livres para turno padrão.
     * M: Matutino, V: Vespertino, N: Noturno, D: Dia Todo / Integral.
     */
    public static function inferTurnoFromHorarios(?string $horarioInicio, ?string $horarioFim, string $defaultTurno = 'V'): string
    {
        if (empty($horarioInicio) || empty($horarioFim)) {
            return strtoupper($defaultTurno);
        }

        $hIni = trim($horarioInicio);
        $hFim = trim($horarioFim);

        $partsIni = explode(':', $hIni);
        $partsFim = explode(':', $hFim);

        if (count($partsIni) < 2 || count($partsFim) < 2) {
            return strtoupper($defaultTurno);
        }

        $minIni = (int)$partsIni[0] * 60 + (int)$partsIni[1];
        $minFim = (int)$partsFim[0] * 60 + (int)$partsFim[1];

        if ($minFim <= $minIni) {
            return strtoupper($defaultTurno);
        }

        $duracao = $minFim - $minIni;

        // Se duração >= 6h (360min) ou inicia de manhã (< 12:00 / 720min) e termina após 14:00 (840min) -> Dia Todo [D]
        if ($duracao >= 360 || ($minIni < 720 && $minFim > 840)) {
            return 'D';
        }

        // Noturno [N]: início a partir das 18:00 (1080min)
        if ($minIni >= 1080) {
            return 'N';
        }

        // Vespertino [V]: início entre 12:00 e 17:59 (720min a 1079min)
        // Cobre: 13:00 - 17:00, 14:00 - 16:00, 14:00 - 18:00 etc.
        if ($minIni >= 720 && $minIni < 1080) {
            return 'V';
        }

        // Matutino [M]: início antes das 12:00 (720min)
        if ($minIni < 720) {
            return 'M';
        }

        return strtoupper($defaultTurno);
    }

    /**
     * Retorna os horários de início e término padrão para cada turno.
     */
    public static function getTurnoDefaultHorarios(string $turno): array
    {
        $t = strtoupper(trim($turno));
        return match ($t) {
            'M' => ['horario_inicio' => '08:00', 'horario_fim' => '12:00'],
            'V' => ['horario_inicio' => '14:00', 'horario_fim' => '18:00'],
            'N' => ['horario_inicio' => '18:30', 'horario_fim' => '22:30'],
            'D' => ['horario_inicio' => '08:00', 'horario_fim' => '17:00'],
            default => ['horario_inicio' => '14:00', 'horario_fim' => '18:00'],
        };
    }

    /**
     * Normaliza e ordena cronologicamente uma lista de datas vindas do Modo Seleção (ex: query string).
     */
    public static function parseSelectedDates(string $datasQuery): array
    {
        $raw = explode(',', $datasQuery);
        $valid = [];
        foreach ($raw as $d) {
            $clean = trim($d);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $clean)) {
                $valid[] = $clean;
            }
        }
        $valid = array_unique($valid);
        sort($valid);
        return array_values($valid);
    }

    /**
     * Cria uma nova turma com suporte a 0 alunos (desacoplada) e grade customizada de encontros.
     */
    public function createTurma(array $dadosTurma, array $encontros = []): int
    {
        $this->ensureSchema();

        $cursoNome = trim((string)($dadosTurma['curso_nome'] ?? ''));
        if (empty($cursoNome)) {
            throw new InvalidArgumentException("O nome do curso é obrigatório.");
        }

        $codigoTurma = trim((string)($dadosTurma['codigo_turma'] ?? ''));
        if (empty($codigoTurma)) {
            $codigoTurma = 'TURMA-' . date('Ymd') . '-' . substr(bin2hex(random_bytes(3)), 0, 5);
        }

        $chaveAcesso = trim((string)($dadosTurma['chave_acesso'] ?? ''));
        if (empty($chaveAcesso)) {
            $slug = strtolower((string)preg_replace('/[^a-z0-9]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $cursoNome) ?: $cursoNome));
            if (empty($slug)) {
                $slug = 'turma';
            }
            $chaveAcesso = substr($slug, 0, 16) . '-' . rand(100, 999);
        }

        $turnoPadrao = strtoupper(trim((string)($dadosTurma['turno_padrao'] ?? 'V')));
        if (!in_array($turnoPadrao, ['M', 'V', 'N', 'D'], true)) {
            $turnoPadrao = 'V';
        }

        // Calcula limites de datas a partir dos encontros ou campos diretos
        $datasEncontros = [];
        foreach ($encontros as $e) {
            if (!empty($e['data_encontro'])) {
                $datasEncontros[] = trim((string)$e['data_encontro']);
            }
        }
        sort($datasEncontros);

        $dataInicio = !empty($datasEncontros) ? $datasEncontros[0] : trim((string)($dadosTurma['data_inicio'] ?? date('Y-m-d')));
        $dataConclusao = !empty($datasEncontros) ? end($datasEncontros) : trim((string)($dadosTurma['data_conclusao'] ?? $dataInicio));

        // Determinação inteligente de status
        $status = trim((string)($dadosTurma['status'] ?? ''));
        if (empty($status)) {
            $hoje = date('Y-m-d');
            $status = ($dataInicio <= $hoje) ? 'em_andamento' : 'prevista';
        }

        $cargaHoraria = (int)($dadosTurma['carga_horaria'] ?? 0);
        $modalidade = trim((string)($dadosTurma['modalidade'] ?? 'Presencial'));
        $clienteNome = trim((string)($dadosTurma['cliente_nome'] ?? '')) ?: null;
        $ordemServico = trim((string)($dadosTurma['ordem_servico'] ?? '')) ?: null;
        $cidade = trim((string)($dadosTurma['cidade'] ?? 'Goiânia - GO')) ?: null;
        $instrutor = trim((string)($dadosTurma['instrutor'] ?? '')) ?: null;
        $ementa = trim((string)($dadosTurma['ementa'] ?? '')) ?: null;

        $razaoSocial = trim((string)($dadosTurma['razao_social'] ?? '')) ?: null;
        $cnpjTomador = trim((string)($dadosTurma['cnpj_tomador'] ?? '')) ?: null;
        $cidadeUf = trim((string)($dadosTurma['cidade_uf'] ?? '')) ?: null;
        $emailFinanceiro = trim((string)($dadosTurma['email_financeiro'] ?? '')) ?: null;
        $numeroOsContrato = trim((string)($dadosTurma['numero_os_contrato'] ?? '')) ?: null;
        $tipoCobranca = trim((string)($dadosTurma['tipo_cobranca'] ?? 'hora_aula')) ?: 'hora_aula';
        $valorUnitario = (float)($dadosTurma['valor_unitario'] ?? $dadosTurma['valor_hora_aula'] ?? 0.0);
        $valorTotal = (float)($dadosTurma['valor_total'] ?? 0.0);

        // Sincronização bidirecional com campos legados
        if ($razaoSocial && empty($clienteNome)) {
            $clienteNome = $razaoSocial;
        } elseif ($clienteNome && empty($razaoSocial)) {
            $razaoSocial = $clienteNome;
        }

        if ($numeroOsContrato && empty($ordemServico)) {
            $ordemServico = $numeroOsContrato;
        } elseif ($ordemServico && empty($numeroOsContrato)) {
            $numeroOsContrato = $ordemServico;
        }

        $clienteCnpj = trim((string)($dadosTurma['cliente_cnpj'] ?? '')) ?: null;
        if ($cnpjTomador && empty($clienteCnpj)) {
            $clienteCnpj = $cnpjTomador;
        } elseif ($clienteCnpj && empty($cnpjTomador)) {
            $cnpjTomador = $clienteCnpj;
        }

        if ($cidadeUf && empty($cidade)) {
            $cidade = $cidadeUf;
        } elseif ($cidade && empty($cidadeUf)) {
            $cidadeUf = $cidade;
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO turmas (
                    codigo_turma, curso_nome, cliente_nome, ordem_servico,
                    razao_social, cnpj_tomador, cidade_uf, email_financeiro,
                    numero_os_contrato, tipo_cobranca, valor_unitario, valor_hora_aula, valor_total,
                    cliente_cnpj, modalidade, carga_horaria, data_inicio, data_conclusao,
                    turno_padrao, status, chave_acesso, cidade, instrutor, ementa
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?
                )
            ");
            $stmt->execute([
                $codigoTurma, $cursoNome, $clienteNome, $ordemServico,
                $razaoSocial, $cnpjTomador, $cidadeUf, $emailFinanceiro,
                $numeroOsContrato, $tipoCobranca, $valorUnitario, $valorUnitario, $valorTotal,
                $clienteCnpj, $modalidade, $cargaHoraria, $dataInicio, $dataConclusao,
                $turnoPadrao, $status, $chaveAcesso, $cidade, $instrutor, $ementa
            ]);

            $turmaId = (int)$this->pdo->lastInsertId();

            // Insere encontros com overrides individuais
            $numEnc = 1;
            $stmtEnc = $this->pdo->prepare("
                INSERT INTO encontros (
                    turma_id, numero_encontro, data_encontro, turno,
                    horario_inicio, horario_fim, conteudo_previsto, tipo
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($encontros as $enc) {
                $dEnc = trim((string)($enc['data_encontro'] ?? ''));
                if (empty($dEnc)) {
                    continue;
                }

                $hIni = trim((string)($enc['horario_inicio'] ?? ''));
                $hFim = trim((string)($enc['horario_fim'] ?? ''));

                // Turno: usa o informado ou infere pelos horários livres
                $tEnc = !empty($enc['turno']) ? strtoupper(trim((string)$enc['turno'])) : self::inferTurnoFromHorarios($hIni, $hFim, $turnoPadrao);
                if (!in_array($tEnc, ['M', 'V', 'N', 'D'], true)) {
                    $tEnc = $turnoPadrao;
                }

                // Horários padrões se vazios
                if (empty($hIni) || empty($hFim)) {
                    $padroes = self::getTurnoDefaultHorarios($tEnc);
                    $hIni = $hIni ?: $padroes['horario_inicio'];
                    $hFim = $hFim ?: $padroes['horario_fim'];
                }

                $numAtual = isset($enc['numero_encontro']) && (int)$enc['numero_encontro'] > 0 ? (int)$enc['numero_encontro'] : $numEnc;
                $conteudo = trim((string)($enc['conteudo_previsto'] ?? '')) ?: null;
                $tipo = trim((string)($enc['tipo'] ?? 'aula')) ?: 'aula';

                $stmtEnc->execute([
                    $turmaId, $numAtual, $dEnc, $tEnc,
                    $hIni, $hFim, $conteudo, $tipo
                ]);

                $numEnc++;
            }

            $this->pdo->commit();
            return $turmaId;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new RuntimeException("Falha ao criar turma: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Atualiza dados de uma turma existente.
     */
    public function updateTurma(int $turmaId, array $dados): bool
    {
        $this->ensureSchema();

        // Sincronização bidirecional antes da montagem da query
        if (isset($dados['razao_social']) && !isset($dados['cliente_nome'])) {
            $dados['cliente_nome'] = $dados['razao_social'];
        } elseif (isset($dados['cliente_nome']) && !isset($dados['razao_social'])) {
            $dados['razao_social'] = $dados['cliente_nome'];
        }

        if (isset($dados['numero_os_contrato']) && !isset($dados['ordem_servico'])) {
            $dados['ordem_servico'] = $dados['numero_os_contrato'];
        } elseif (isset($dados['ordem_servico']) && !isset($dados['numero_os_contrato'])) {
            $dados['numero_os_contrato'] = $dados['ordem_servico'];
        }

        if (isset($dados['cnpj_tomador']) && !isset($dados['cliente_cnpj'])) {
            $dados['cliente_cnpj'] = $dados['cnpj_tomador'];
        } elseif (isset($dados['cliente_cnpj']) && !isset($dados['cnpj_tomador'])) {
            $dados['cnpj_tomador'] = $dados['cliente_cnpj'];
        }

        if (isset($dados['valor_unitario']) && !isset($dados['valor_hora_aula'])) {
            $dados['valor_hora_aula'] = $dados['valor_unitario'];
        }

        $fields = [];
        $params = [];

        $allowed = [
            'curso_nome', 'cliente_nome', 'ordem_servico', 'modalidade',
            'cliente_tipo', 'cliente_cidade', 'cliente_uf', 'cliente_cnpj',
            'tipo_cobranca', 'valor_hora_aula', 'valor_total', 'carga_horaria',
            'carga_horaria_extenso', 'data_inicio', 'data_conclusao', 'turno_padrao',
            'status', 'chave_acesso', 'portal_certificados_modo', 'instrutor',
            'cidade', 'ementa',
            'razao_social', 'cnpj_tomador', 'cidade_uf', 'email_financeiro',
            'numero_os_contrato', 'valor_unitario'
        ];

        foreach ($allowed as $f) {
            if (array_key_exists($f, $dados)) {
                $fields[] = "{$f} = ?";
                $params[] = $dados[$f] !== '' ? $dados[$f] : null;
            }
        }

        if (empty($fields)) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $fields[] = "updated_at = ?";
        $params[] = $now;
        $params[] = $turmaId;

        $sql = "UPDATE turmas SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Recalcula data_inicio e data_conclusao da turma com base nos encontros ativos.
     */
    public function recalculateTurmaDates(int $turmaId): void
    {
        $stmt = $this->pdo->prepare("
            SELECT MIN(data_encontro) as min_d, MAX(data_encontro) as max_d 
            FROM encontros 
            WHERE turma_id = ? AND deleted_at IS NULL
        ");
        $stmt->execute([$turmaId]);
        $bounds = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($bounds && !empty($bounds['min_d'])) {
            $now = date('Y-m-d H:i:s');
            $up = $this->pdo->prepare("UPDATE turmas SET data_inicio = ?, data_conclusao = ?, updated_at = ? WHERE id = ?");
            $up->execute([$bounds['min_d'], $bounds['max_d'], $now, $turmaId]);
        }
    }

    /**
     * Adiciona um encontro avulso ou de reposição à turma.
     */
    public function addEncontro(int $turmaId, array $encontroData): int
    {
        $this->ensureSchema();

        $dataEncontro = trim((string)($encontroData['data_encontro'] ?? ''));
        if (empty($dataEncontro)) {
            throw new InvalidArgumentException("Data do encontro é obrigatória.");
        }

        // Descobre o próximo número de encontro disponível
        $stmtNum = $this->pdo->prepare("SELECT COALESCE(MAX(numero_encontro), 0) + 1 FROM encontros WHERE turma_id = ?");
        $stmtNum->execute([$turmaId]);
        $proxNum = (int)$stmtNum->fetchColumn();

        $hIni = trim((string)($encontroData['horario_inicio'] ?? ''));
        $hFim = trim((string)($encontroData['horario_fim'] ?? ''));
        $turno = !empty($encontroData['turno']) ? strtoupper(trim((string)$encontroData['turno'])) : self::inferTurnoFromHorarios($hIni, $hFim);

        if (empty($hIni) || empty($hFim)) {
            $padroes = self::getTurnoDefaultHorarios($turno);
            $hIni = $hIni ?: $padroes['horario_inicio'];
            $hFim = $hFim ?: $padroes['horario_fim'];
        }

        $tipo = trim((string)($encontroData['tipo'] ?? 'aula')) ?: 'aula';
        $conteudo = trim((string)($encontroData['conteudo_previsto'] ?? '')) ?: null;

        $stmt = $this->pdo->prepare("
            INSERT INTO encontros (
                turma_id, numero_encontro, data_encontro, turno,
                horario_inicio, horario_fim, conteudo_previsto, tipo
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $turmaId, $proxNum, $dataEncontro, $turno,
            $hIni, $hFim, $conteudo, $tipo
        ]);

        $novoId = (int)$this->pdo->lastInsertId();
        $this->recalculateTurmaDates($turmaId);

        return $novoId;
    }

    /**
     * Atualiza um encontro individual (override de data, turno, horários ou plano).
     */
    public function updateEncontro(int $encontroId, array $encontroData): bool
    {
        $this->ensureSchema();

        $stmt = $this->pdo->prepare("SELECT turma_id FROM encontros WHERE id = ?");
        $stmt->execute([$encontroId]);
        $turmaId = (int)$stmt->fetchColumn();

        if ($turmaId <= 0) {
            throw new InvalidArgumentException("Encontro ID {$encontroId} não encontrado.");
        }

        $fields = [];
        $params = [];

        $hIni = isset($encontroData['horario_inicio']) ? trim((string)$encontroData['horario_inicio']) : null;
        $hFim = isset($encontroData['horario_fim']) ? trim((string)$encontroData['horario_fim']) : null;

        if (isset($encontroData['data_encontro'])) {
            $fields[] = "data_encontro = ?";
            $params[] = trim((string)$encontroData['data_encontro']);
        }
        if (isset($encontroData['horario_inicio'])) {
            $fields[] = "horario_inicio = ?";
            $params[] = $hIni ?: null;
        }
        if (isset($encontroData['horario_fim'])) {
            $fields[] = "horario_fim = ?";
            $params[] = $hFim ?: null;
        }
        if (isset($encontroData['turno'])) {
            $t = strtoupper(trim((string)$encontroData['turno']));
            $fields[] = "turno = ?";
            $params[] = $t;
        } elseif ($hIni && $hFim) {
            $fields[] = "turno = ?";
            $params[] = self::inferTurnoFromHorarios($hIni, $hFim);
        }
        if (isset($encontroData['conteudo_previsto'])) {
            $fields[] = "conteudo_previsto = ?";
            $params[] = trim((string)$encontroData['conteudo_previsto']) ?: null;
        }
        if (isset($encontroData['conteudo_ministrado'])) {
            $fields[] = "conteudo_ministrado = ?";
            $params[] = trim((string)$encontroData['conteudo_ministrado']) ?: null;
        }
        if (isset($encontroData['tipo'])) {
            $fields[] = "tipo = ?";
            $params[] = trim((string)$encontroData['tipo']) ?: 'aula';
        }

        if (empty($fields)) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $fields[] = "updated_at = ?";
        $params[] = $now;
        $params[] = $encontroId;

        $sql = "UPDATE encontros SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmtUpdate = $this->pdo->prepare($sql);
        $ok = $stmtUpdate->execute($params);

        if ($ok) {
            $this->recalculateTurmaDates($turmaId);
        }

        return $ok;
    }

    /**
     * Exclui um encontro pendente. Se já houver chamadas registradas em frequencias, bloqueia.
     */
    public function deleteEncontro(int $encontroId): array
    {
        $this->ensureSchema();

        $stmt = $this->pdo->prepare("SELECT turma_id, numero_encontro FROM encontros WHERE id = ?");
        $stmt->execute([$encontroId]);
        $enc = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$enc) {
            return ['success' => false, 'message' => "Encontro ID {$encontroId} não encontrado."];
        }

        $turmaId = (int)$enc['turma_id'];

        // Blindagem: verificar se há presenças registradas
        $stmtFreq = $this->pdo->prepare("SELECT COUNT(*) FROM frequencias WHERE encontro_id = ?");
        $stmtFreq->execute([$encontroId]);
        $totalFreq = (int)$stmtFreq->fetchColumn();

        if ($totalFreq > 0) {
            return [
                'success' => false,
                'message' => "Não é possível excluir o Encontro {$enc['numero_encontro']}: ele já possui {$totalFreq} registro(s) de chamada/presença gravados no diário.",
            ];
        }

        $del = $this->pdo->prepare("DELETE FROM encontros WHERE id = ?");
        $ok = $del->execute([$encontroId]);

        if ($ok) {
            $this->recalculateTurmaDates($turmaId);
            return ['success' => true, 'message' => "Encontro {$enc['numero_encontro']} excluído com sucesso."];
        }

        return ['success' => false, 'message' => "Falha ao excluir o encontro no banco de dados."];
    }

    /**
     * Verifica e atualiza o ciclo de vida da turma:
     * Transiciona de 'prevista' para 'em_andamento' automaticamente se a data do primeiro encontro for hoje ou passada.
     */
    public function checkAndTransitionLifecycle(?int $turmaId = null, ?string $currentDate = null): int
    {
        $this->ensureSchema();
        $hoje = $currentDate ?? date('Y-m-d');
        $now = date('Y-m-d H:i:s');

        $query = "
            SELECT t.id, t.status, 
                   COALESCE(MIN(e.data_encontro), t.data_inicio) as primeira_data
            FROM turmas t
            LEFT JOIN encontros e ON e.turma_id = t.id AND e.deleted_at IS NULL
            WHERE t.status = 'prevista' AND t.deleted_at IS NULL
        ";

        if ($turmaId !== null && $turmaId > 0) {
            $query .= " AND t.id = " . (int)$turmaId;
        }

        $query .= " GROUP BY t.id";

        $rows = $this->pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
        $count = 0;

        $stmtUp = $this->pdo->prepare("UPDATE turmas SET status = 'em_andamento', updated_at = ? WHERE id = ?");

        foreach ($rows as $r) {
            $pData = $r['primeira_data'];
            if (!empty($pData) && $hoje >= $pData) {
                $stmtUp->execute([$now, $r['id']]);
                $count++;
            }
        }

        return $count;
    }

    public function findAlunoById(int $alunoId): ?array
    {
        $this->ensureSchema();
        $stmt = $this->pdo->prepare("SELECT * FROM alunos WHERE id = ?");
        $stmt->execute([$alunoId]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        return $res ?: null;
    }

    public function findAlunoByEmail(int $turmaId, string $email): ?array
    {
        $this->ensureSchema();
        $emailClean = strtolower(trim($email));
        if (empty($emailClean)) {
            return null;
        }
        $stmt = $this->pdo->prepare("SELECT * FROM alunos WHERE turma_id = ? AND LOWER(TRIM(email)) = ? LIMIT 1");
        $stmt->execute([$turmaId, $emailClean]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        return $res ?: null;
    }

    public function reconcileOrRegisterStudent(
        int $turmaId,
        string $nome,
        string $email,
        ?string $cpf = null,
        ?string $telefone = null,
        ?int $encontroIdPresenca = null
    ): array {
        $this->ensureSchema();

        $nome = trim(preg_replace('/\s+/', ' ', $nome) ?? '');
        $email = strtolower(trim($email));
        $cpfLimpo = !empty($cpf) ? ValidatorService::cleanCpf($cpf) : null;
        $cpfFormatado = ($cpfLimpo && strlen($cpfLimpo) === 11) ? ValidatorService::formatCpf($cpfLimpo) : null;
        $cpfMascarado = ($cpfLimpo && strlen($cpfLimpo) === 11) ? ValidatorService::maskCpf($cpfLimpo) : null;
        $telefone = !empty($telefone) ? trim($telefone) : null;

        if (empty($nome)) {
            throw new InvalidArgumentException("O nome do aluno é obrigatório.");
        }
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("E-mail do aluno inválido.");
        }

        // Validação matemática estrita de CPF se fornecido
        if (!empty($cpfLimpo)) {
            if (!ValidatorService::validateCpf($cpfLimpo)) {
                throw new InvalidArgumentException("O CPF informado é inválido.");
            }
        }

        // Reconciliação Inteligente em Cascata
        $matchedAluno = null;

        // 1. Match por CPF (se preenchido)
        if (!empty($cpfLimpo)) {
            $stmtCpf = $this->pdo->prepare("SELECT * FROM alunos WHERE turma_id = ? AND cpf_limpo = ? LIMIT 1");
            $stmtCpf->execute([$turmaId, $cpfLimpo]);
            $matchedAluno = $stmtCpf->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        // 2. Match por E-mail
        if (!$matchedAluno) {
            $stmtEmail = $this->pdo->prepare("SELECT * FROM alunos WHERE turma_id = ? AND LOWER(TRIM(email)) = ? LIMIT 1");
            $stmtEmail->execute([$turmaId, $email]);
            $matchedAluno = $stmtEmail->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        // 3. Match por Nome Completo exato
        if (!$matchedAluno) {
            $stmtNome = $this->pdo->prepare("SELECT * FROM alunos WHERE turma_id = ? AND LOWER(TRIM(nome_completo)) = ? LIMIT 1");
            $stmtNome->execute([$turmaId, mb_strtolower($nome, 'UTF-8')]);
            $matchedAluno = $stmtNome->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $now = date('Y-m-d H:i:s');
        $alunoId = 0;
        $isNew = false;
        $action = 'none';

        if ($matchedAluno) {
            $alunoId = (int)$matchedAluno['id'];
            $updates = [];
            $params = [];

            // Se o e-mail não existia ou é diferente, atualiza
            if (empty($matchedAluno['email'])) {
                $updates[] = "email = ?";
                $params[] = $email;
            }

            // Se forneceu CPF e o aluno não tinha
            if (!empty($cpfLimpo) && empty($matchedAluno['cpf_limpo'])) {
                $updates[] = "cpf = ?";
                $params[] = $cpfFormatado;
                $updates[] = "cpf_limpo = ?";
                $params[] = $cpfLimpo;
                $updates[] = "cpf_mascarado = ?";
                $params[] = $cpfMascarado;
            }

            // Se forneceu telefone e o aluno não tinha
            if (!empty($telefone) && empty($matchedAluno['telefone'])) {
                $updates[] = "telefone = ?";
                $params[] = $telefone;
            }

            // Se o match foi por CPF e o nome digitado tem mais detalhes
            if (!empty($nome) && $matchedAluno['nome_completo'] !== $nome && !empty($cpfLimpo) && $matchedAluno['cpf_limpo'] === $cpfLimpo) {
                $updates[] = "nome_completo = ?";
                $params[] = $nome;
            }

            if (!empty($updates)) {
                $updates[] = "updated_at = ?";
                $params[] = $now;
                $params[] = $alunoId;
                $sqlUp = "UPDATE alunos SET " . implode(", ", $updates) . " WHERE id = ?";
                $stmtUp = $this->pdo->prepare($sqlUp);
                $stmtUp->execute($params);
                $action = 'updated';
            } else {
                $action = 'matched';
            }
        } else {
            // Novo aluno regular na turma
            $stmtIns = $this->pdo->prepare("
                INSERT INTO alunos (turma_id, nome_completo, cpf, cpf_limpo, cpf_mascarado, email, telefone, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtIns->execute([
                $turmaId,
                $nome,
                $cpfFormatado,
                $cpfLimpo,
                $cpfMascarado,
                $email,
                $telefone,
                $now,
                $now,
            ]);
            $alunoId = (int)$this->pdo->lastInsertId();
            $isNew = true;
            $action = 'created';
        }

        // Frequência automática se solicitada
        $presencaRegistrada = false;
        if ($encontroIdPresenca !== null && $encontroIdPresenca > 0) {
            $stmtCheckEnc = $this->pdo->prepare("SELECT id FROM encontros WHERE id = ? AND turma_id = ? AND tipo = 'aula' LIMIT 1");
            $stmtCheckEnc->execute([$encontroIdPresenca, $turmaId]);
            if ($stmtCheckEnc->fetch()) {
                $stmtFreqCheck = $this->pdo->prepare("SELECT id FROM frequencias WHERE encontro_id = ? AND aluno_id = ? LIMIT 1");
                $stmtFreqCheck->execute([$encontroIdPresenca, $alunoId]);
                $freqId = $stmtFreqCheck->fetchColumn();
                if ($freqId) {
                    $this->pdo->prepare("UPDATE frequencias SET presente = 1, updated_at = ? WHERE id = ?")->execute([$now, $freqId]);
                } else {
                    $this->pdo->prepare("INSERT INTO frequencias (encontro_id, aluno_id, presente, created_at, updated_at) VALUES (?, ?, 1, ?, ?)")->execute([$encontroIdPresenca, $alunoId, $now, $now]);
                }
                $presencaRegistrada = true;
            }
        }

        $alunoFinal = $this->findAlunoById($alunoId);

        return [
            'aluno_id'            => $alunoId,
            'aluno'               => $alunoFinal,
            'is_new'              => $isNew,
            'action'              => $action,
            'presenca_registrada' => $presencaRegistrada,
        ];
    }

    public function updateAluno(
        int $alunoId,
        string|array $nome,
        ?string $cpf = null,
        ?string $email = null,
        ?string $telefone = null,
        bool $syncCertificados = true
    ): bool {
        $this->ensureSchema();

        if (is_array($nome)) {
            $data = $nome;
            $nome = (string)($data['nome_completo'] ?? $data['nome'] ?? '');
            $cpf = isset($data['cpf']) ? (string)$data['cpf'] : null;
            $email = isset($data['email']) ? (string)$data['email'] : null;
            $telefone = isset($data['telefone']) ? (string)$data['telefone'] : null;
            $syncCertificados = $data['sync_certificados'] ?? true;
        }

        $alunoAtual = $this->findAlunoById($alunoId);
        if (!$alunoAtual) {
            return false;
        }

        $nome = trim(preg_replace('/\s+/', ' ', $nome) ?? '');
        if (empty($nome)) {
            throw new InvalidArgumentException("O nome do aluno não pode ser vazio.");
        }

        $emailClean = !empty($email) ? strtolower(trim($email)) : null;
        if ($emailClean && !filter_var($emailClean, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("E-mail informado é inválido.");
        }

        $cpfLimpo = !empty($cpf) ? ValidatorService::cleanCpf($cpf) : null;
        $cpfFormatado = null;
        $cpfMascarado = null;
        if (!empty($cpfLimpo)) {
            if (!ValidatorService::validateCpf($cpfLimpo)) {
                throw new InvalidArgumentException("CPF informado é inválido.");
            }
            $cpfFormatado = ValidatorService::formatCpf($cpfLimpo);
            $cpfMascarado = ValidatorService::maskCpf($cpfLimpo);
        }

        $telefoneClean = !empty($telefone) ? trim($telefone) : null;
        $now = date('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare("
            UPDATE alunos 
            SET nome_completo = ?, cpf = ?, cpf_limpo = ?, cpf_mascarado = ?, email = ?, telefone = ?, updated_at = ?
            WHERE id = ?
        ");
        $success = $stmt->execute([
            $nome,
            $cpfFormatado,
            $cpfLimpo,
            $cpfMascarado,
            $emailClean,
            $telefoneClean,
            $now,
            $alunoId,
        ]);

        // Sincronização retroativa com Livro Digital de Certificados
        if ($success && $syncCertificados) {
            $turmaId = (int)$alunoAtual['turma_id'];
            $oldCpfLimpo = $alunoAtual['cpf_limpo'];
            $oldNome = $alunoAtual['nome_completo'];

            $stmtSync = $this->pdo->prepare("
                UPDATE registros_certificados 
                SET aluno_nome = ?,
                    aluno_cpf = COALESCE(?, aluno_cpf),
                    aluno_cpf_mascarado = COALESCE(?, aluno_cpf_mascarado)
                WHERE turma_id = ? 
                  AND (aluno_cpf = ? OR aluno_cpf = ? OR aluno_nome = ?)
            ");
            $stmtSync->execute([
                $nome,
                $cpfFormatado,
                $cpfMascarado,
                $turmaId,
                $alunoAtual['cpf'],
                $oldCpfLimpo,
                $oldNome,
            ]);
        }

        return $success;
    }

    public function createCorrectionRequest(
        int $turmaId,
        int $alunoId,
        array|string $dadosPropostos,
        ?string $cpfOuMotivo = null,
        ?string $telefone = null,
        ?string $motivo = null
    ): int {
        $this->ensureSchema();

        $aluno = $this->findAlunoById($alunoId);
        if (!$aluno || (int)$aluno['turma_id'] !== $turmaId) {
            throw new InvalidArgumentException("Aluno inválido para esta turma.");
        }

        if (is_array($dadosPropostos)) {
            $nome = isset($dadosPropostos['nome_completo']) ? trim((string)$dadosPropostos['nome_completo']) : (isset($dadosPropostos['nome']) ? trim((string)$dadosPropostos['nome']) : null);
            $cpf = isset($dadosPropostos['cpf']) ? trim((string)$dadosPropostos['cpf']) : null;
            $email = isset($dadosPropostos['email']) ? trim((string)$dadosPropostos['email']) : null;
            $telefone = isset($dadosPropostos['telefone']) ? trim((string)$dadosPropostos['telefone']) : null;
            $motivo = !empty($cpfOuMotivo) ? trim($cpfOuMotivo) : (!empty($dadosPropostos['motivo']) ? trim((string)$dadosPropostos['motivo']) : null);
        } else {
            $nome = !empty($dadosPropostos) ? trim((string)$dadosPropostos) : null;
            $cpf = !empty($cpfOuMotivo) ? trim((string)$cpfOuMotivo) : null;
            $email = null;
            $telefone = !empty($telefone) ? trim((string)$telefone) : null;
            $motivo = !empty($motivo) ? trim((string)$motivo) : null;
        }

        if ($cpf) {
            $cpfLimpo = ValidatorService::cleanCpf($cpf);
            if (!ValidatorService::validateCpf($cpfLimpo)) {
                throw new InvalidArgumentException("O CPF proposto para correção é inválido.");
            }
            $cpf = ValidatorService::formatCpf($cpfLimpo);
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO solicitacoes_correcao_aluno 
            (turma_id, aluno_id, nome_proposto, cpf_proposto, email_proposto, telefone_proposto, motivo, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'pendente', ?)
        ");
        $stmt->execute([
            $turmaId,
            $alunoId,
            $nome,
            $cpf,
            $email,
            $telefone,
            $motivo,
            date('Y-m-d H:i:s'),
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function getPendingCorrectionRequests(int $turmaId): array
    {
        $this->ensureSchema();

        $stmt = $this->pdo->prepare("
            SELECT s.*, 
                   a.nome_completo AS aluno_nome_atual,
                   a.cpf AS aluno_cpf_atual,
                   a.cpf_mascarado AS aluno_cpf_mascarado_atual,
                   a.email AS aluno_email_atual,
                   a.telefone AS aluno_telefone_atual
            FROM solicitacoes_correcao_aluno s
            JOIN alunos a ON a.id = s.aluno_id
            WHERE s.turma_id = ? AND s.status = 'pendente'
            ORDER BY s.id ASC
        ");
        $stmt->execute([$turmaId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function resolveCorrectionRequest(int $requestId, bool $approve, ?string $respostaAdmin = null): bool
    {
        $this->ensureSchema();

        $stmt = $this->pdo->prepare("SELECT * FROM solicitacoes_correcao_aluno WHERE id = ? LIMIT 1");
        $stmt->execute([$requestId]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$req || $req['status'] !== 'pendente') {
            return false;
        }

        $now = date('Y-m-d H:i:s');

        if ($approve) {
            $aluno = $this->findAlunoById((int)$req['aluno_id']);
            if (!$aluno) {
                return false;
            }

            $nomeNovo = !empty($req['nome_proposto']) ? $req['nome_proposto'] : $aluno['nome_completo'];
            $cpfNovo = !empty($req['cpf_proposto']) ? $req['cpf_proposto'] : $aluno['cpf'];
            $emailNovo = !empty($req['email_proposto']) ? $req['email_proposto'] : $aluno['email'];
            $telefoneNovo = !empty($req['telefone_proposto']) ? $req['telefone_proposto'] : $aluno['telefone'];

            $this->updateAluno((int)$req['aluno_id'], $nomeNovo, $cpfNovo, $emailNovo, $telefoneNovo, true);

            $stmtUp = $this->pdo->prepare("UPDATE solicitacoes_correcao_aluno SET status = 'aprovada', resolved_at = ? WHERE id = ?");
            return $stmtUp->execute([$now, $requestId]);
        } else {
            $stmtUp = $this->pdo->prepare("UPDATE solicitacoes_correcao_aluno SET status = 'rejeitada', resolved_at = ? WHERE id = ?");
            return $stmtUp->execute([$now, $requestId]);
        }
    }
}

