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

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO turmas (
                    codigo_turma, curso_nome, cliente_nome, ordem_servico,
                    modalidade, carga_horaria, data_inicio, data_conclusao,
                    turno_padrao, status, chave_acesso, cidade, instrutor, ementa
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?
                )
            ");
            $stmt->execute([
                $codigoTurma, $cursoNome, $clienteNome, $ordemServico,
                $modalidade, $cargaHoraria, $dataInicio, $dataConclusao,
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

        $fields = [];
        $params = [];

        $allowed = [
            'curso_nome', 'cliente_nome', 'ordem_servico', 'modalidade',
            'cliente_tipo', 'cliente_cidade', 'cliente_uf', 'cliente_cnpj',
            'tipo_cobranca', 'valor_hora_aula', 'valor_total', 'carga_horaria',
            'carga_horaria_extenso', 'data_inicio', 'data_conclusao', 'turno_padrao',
            'status', 'chave_acesso', 'portal_certificados_modo', 'instrutor',
            'cidade', 'ementa'
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
}

