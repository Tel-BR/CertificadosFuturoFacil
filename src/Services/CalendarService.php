<?php
/**
 * Serviço de Calendário de Capacidade, Agendamento e Prevenção de Choques
 * Plataforma Integrada de Gestão Pedagógica, Diário e Certificados Futuro Fácil
 * 
 * Responsabilidades:
 * 1. Geração de Matrizes do Calendário Anual (12 meses) e Mensal Detalhado.
 * 2. Identificação precisa de turnos ([M], [V], [N], [D]) com posições dedicadas e paleta acessível.
 * 3. Estilização automática de datas passadas (< hoje) em tons neutros.
 * 4. Montagem de dados de popover/hover informativos para desktop e mobile.
 * 5. Costura de Teste 5: Validação rigorosa e bloqueio impeditivo de choques de horário.
 */

declare(strict_types=1);

namespace FuturoFacil\Services;

use FuturoFacil\Config\Database;
use InvalidArgumentException;
use PDO;
use RuntimeException;

class CalendarService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getConnection();
        $this->ensureBloqueiosTable();
    }

    /**
     * Assegura a existência da tabela bloqueios_agenda no banco de dados ativo.
     */
    public function ensureBloqueiosTable(): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS bloqueios_agenda (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    data TEXT NOT NULL,
                    descricao TEXT NOT NULL,
                    tipo TEXT NOT NULL DEFAULT 'feriado_nacional',
                    bloqueante INTEGER NOT NULL DEFAULT 1,
                    permite_excecao INTEGER NOT NULL DEFAULT 1,
                    created_at TEXT NOT NULL DEFAULT (datetime('now')),
                    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
                );
                CREATE INDEX IF NOT EXISTS idx_bloqueios_data ON bloqueios_agenda(data);
                CREATE INDEX IF NOT EXISTS idx_bloqueios_tipo ON bloqueios_agenda(tipo);
            ");
        } else {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS `bloqueios_agenda` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `data` DATE NOT NULL,
                    `descricao` VARCHAR(255) NOT NULL,
                    `tipo` ENUM('feriado_nacional', 'bloqueio_pessoal') NOT NULL DEFAULT 'feriado_nacional',
                    `bloqueante` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=Bloqueia agendamento por padrão, 0=Informativo',
                    `permite_excecao` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=Permite exceção consciente confirmada pelo operador',
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX `idx_bloqueios_data` (`data`),
                    INDEX `idx_bloqueios_tipo` (`tipo`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        }
    }

    /**
     * Calcula a data da Páscoa para qualquer ano civil (algoritmo de Gauss / Meeus com fallback nativo).
     */
    public static function calculateEaster(int $year): string
    {
        if (function_exists('easter_date')) {
            return date('Y-m-d', easter_date($year));
        }
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * Retorna a lista de feriados nacionais oficiais brasileiros (fixos e móveis).
     */
    public static function getFeriadosNacionais(int $year): array
    {
        $pascoa = self::calculateEaster($year);
        $pascoaTs = strtotime($pascoa);

        $feriados = [
            sprintf('%04d-01-01', $year) => 'Confraternização Universal (Ano Novo)',
            date('Y-m-d', strtotime('-47 days', $pascoaTs)) => 'Carnaval',
            date('Y-m-d', strtotime('-2 days', $pascoaTs))  => 'Sexta-feira Santa / Paixão de Cristo',
            $pascoa                                         => 'Páscoa',
            sprintf('%04d-04-21', $year)                    => 'Tiradentes',
            sprintf('%04d-05-01', $year)                    => 'Dia Mundial do Trabalho',
            date('Y-m-d', strtotime('+60 days', $pascoaTs)) => 'Corpus Christi',
            sprintf('%04d-09-07', $year)                    => 'Independência do Brasil',
            sprintf('%04d-10-12', $year)                    => 'Nossa Senhora Aparecida (Padroeira do Brasil)',
            sprintf('%04d-11-02', $year)                    => 'Finados',
            sprintf('%04d-11-15', $year)                    => 'Proclamação da República',
            sprintf('%04d-11-20', $year)                    => 'Dia Nacional de Zumbi e da Consciência Negra',
            sprintf('%04d-12-25', $year)                    => 'Natal',
        ];

        $result = [];
        foreach ($feriados as $data => $desc) {
            $result[] = [
                'data'            => $data,
                'descricao'       => $desc,
                'tipo'            => 'feriado_nacional',
                'bloqueante'      => 1,
                'permite_excecao' => 1,
            ];
        }

        usort($result, fn($a, $b) => strcmp($a['data'], $b['data']));
        return $result;
    }

    /**
     * Efetua a carga de feriados nacionais oficiais para um determinado ano.
     * Operação idempotente que não duplica datas pré-existentes.
     */
    public function seedFeriadosNacionais(int $year): int
    {
        $this->ensureBloqueiosTable();
        $feriados = self::getFeriadosNacionais($year);
        $stmtCheck = $this->pdo->prepare("SELECT COUNT(*) FROM bloqueios_agenda WHERE data = ? AND tipo = 'feriado_nacional'");
        $stmtInsert = $this->pdo->prepare("
            INSERT INTO bloqueios_agenda (data, descricao, tipo, bloqueante, permite_excecao)
            VALUES (?, ?, 'feriado_nacional', 1, 1)
        ");

        $inserted = 0;
        foreach ($feriados as $f) {
            $stmtCheck->execute([$f['data']]);
            $exists = ((int)$stmtCheck->fetchColumn() > 0);
            $stmtCheck->closeCursor();
            if (!$exists) {
                $stmtInsert->execute([$f['data'], $f['descricao']]);
                $inserted++;
            }
        }
        return $inserted;
    }

    /**
     * Adiciona um bloqueio de agenda personalizado (ex: férias, congresso, compromisso).
     */
    public function addBloqueio(
        string $data,
        string $descricao,
        string $tipo = 'bloqueio_pessoal',
        bool $bloqueante = true,
        bool $permiteExcecao = true
    ): int {
        $this->ensureBloqueiosTable();
        $stmt = $this->pdo->prepare("
            INSERT INTO bloqueios_agenda (data, descricao, tipo, bloqueante, permite_excecao)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data,
            $descricao,
            $tipo,
            $bloqueante ? 1 : 0,
            $permiteExcecao ? 1 : 0,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Remove um bloqueio de agenda pelo ID.
     */
    public function deleteBloqueio(int $id): bool
    {
        $this->ensureBloqueiosTable();
        $stmt = $this->pdo->prepare("DELETE FROM bloqueios_agenda WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Retorna todos os bloqueios e feriados em um intervalo de datas, indexados por data.
     */
    public function getBloqueiosForDateRange(string $startDate, string $endDate): array
    {
        $this->ensureBloqueiosTable();
        $stmt = $this->pdo->prepare("
            SELECT * FROM bloqueios_agenda 
            WHERE data BETWEEN ? AND ? 
            ORDER BY data ASC, id ASC
        ");
        $stmt->execute([$startDate, $endDate]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $r) {
            $d = $r['data'];
            if (!isset($result[$d])) {
                $result[$d] = $r;
                $result[$d]['todos'] = [];
            }
            $result[$d]['todos'][] = $r;
        }
        return $result;
    }

    /**
     * Detecta e sugere pontes de feriado não-intrusivas para feriados em terças e quintas-feiras.
     */
    public function checkPonteFeriado(string $date): ?array
    {
        $this->ensureBloqueiosTable();
        $ts = strtotime($date);
        $w = (int)date('w', $ts); // 0=Dom, 1=Seg, 2=Ter, 3=Qua, 4=Qui, 5=Sex, 6=Sáb

        // Se Segunda-feira (1), verificar se a Terça-feira seguinte (+1 dia) é feriado nacional
        if ($w === 1) {
            $terca = date('Y-m-d', strtotime('+1 day', $ts));
            $stmt = $this->pdo->prepare("
                SELECT * FROM bloqueios_agenda 
                WHERE data = ? AND tipo = 'feriado_nacional' AND bloqueante = 1
                LIMIT 1
            ");
            $stmt->execute([$terca]);
            $feriado = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($feriado) {
                $dataFmt = date('d/m/Y', strtotime($terca));
                return [
                    'tipo'         => 'ponte_feriado',
                    'data'         => $date,
                    'data_feriado' => $terca,
                    'feriado'      => $feriado['descricao'],
                    'sugestao'     => "Atenção: A terça-feira seguinte ({$dataFmt}) é o feriado '{$feriado['descricao']}'. Considere se esta segunda-feira será emendada como ponte de feriado antes de confirmar.",
                ];
            }
        }

        // Se Sexta-feira (5), verificar se a Quinta-feira anterior (-1 dia) é feriado nacional
        if ($w === 5) {
            $quinta = date('Y-m-d', strtotime('-1 day', $ts));
            $stmt = $this->pdo->prepare("
                SELECT * FROM bloqueios_agenda 
                WHERE data = ? AND tipo = 'feriado_nacional' AND bloqueante = 1
                LIMIT 1
            ");
            $stmt->execute([$quinta]);
            $feriado = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($feriado) {
                $dataFmt = date('d/m/Y', strtotime($quinta));
                return [
                    'tipo'         => 'ponte_feriado',
                    'data'         => $date,
                    'data_feriado' => $quinta,
                    'feriado'      => $feriado['descricao'],
                    'sugestao'     => "Atenção: A quinta-feira anterior ({$dataFmt}) é o feriado '{$feriado['descricao']}'. Considere se esta sexta-feira será emendada como ponte de feriado antes de confirmar.",
                ];
            }
        }

        return null;
    }

    /**
     * Retorna a configuração acessível para os 4 turnos pedagógicos.
     * Alto contraste WCAG AAA e posições fixas, eliminando confusão para daltônicos.
     */
    public static function getTurnosConfig(): array
    {
        return [
            'M' => [
                'letra'          => 'M',
                'nome'           => 'Matutino',
                'cor_texto'      => '#92400E',
                'cor_fundo'      => '#FEF3C7',
                'cor_borda'      => '#D97706',
                'slot_ordem'     => 1,
                'horario_padrao' => '08:00 - 12:00',
            ],
            'V' => [
                'letra'          => 'V',
                'nome'           => 'Vespertino',
                'cor_texto'      => '#9A3412',
                'cor_fundo'      => '#FFF7ED',
                'cor_borda'      => '#EA580C',
                'slot_ordem'     => 2,
                'horario_padrao' => '14:00 - 18:00',
            ],
            'N' => [
                'letra'          => 'N',
                'nome'           => 'Noturno',
                'cor_texto'      => '#0E7490',
                'cor_fundo'      => '#ECFEFF',
                'cor_borda'      => '#0E7490',
                'slot_ordem'     => 3,
                'horario_padrao' => '19:00 - 22:30',
            ],
            'D' => [
                'letra'          => 'D',
                'nome'           => 'Dia Todo / Integral',
                'cor_texto'      => '#0E7490',
                'cor_fundo'      => '#FAF7F1',
                'cor_borda'      => '#0E7490',
                'slot_ordem'     => 4,
                'horario_padrao' => '08:00 - 17:00',
            ],
        ];
    }

    /**
     * Retorna os nomes dos meses em português.
     */
    public static function getNomesMeses(): array
    {
        return [
            1  => 'Janeiro',
            2  => 'Fevereiro',
            3  => 'Março',
            4  => 'Abril',
            5  => 'Maio',
            6  => 'Junho',
            7  => 'Julho',
            8  => 'Agosto',
            9  => 'Setembro',
            10 => 'Outubro',
            11 => 'Novembro',
            12 => 'Dezembro',
        ];
    }

    /**
     * Indica se um conflito de turno pode prosseguir após confirmação consciente.
     */
    public static function isConfirmableOverlap(?array $conflito): bool
    {
        return in_array(
            $conflito['tipo'] ?? null,
            ['choque_turno', 'choque_deslocamento'],
            true
        );
    }

    /**
     * Consulta encontros ativos em um intervalo de datas e agrupa por dia (YYYY-MM-DD).
     */
    public function getScheduleForDateRange(string $startDate, string $endDate): array
    {
        $sql = "
            SELECT 
                e.id AS encontro_id,
                e.turma_id,
                e.numero_encontro,
                e.data_encontro,
                e.turno,
                e.horario_inicio,
                e.horario_fim,
                e.conteudo_previsto,
                e.conteudo_ministrado,
                e.tipo,
                e.abonado,
                t.codigo_turma,
                t.curso_nome,
                t.cliente_nome,
                t.cidade,
                t.status AS turma_status,
                t.modalidade,
                t.carga_horaria
            FROM encontros e
            INNER JOIN turmas t ON t.id = e.turma_id
            WHERE e.data_encontro BETWEEN :start AND :end
              AND t.status != 'cancelada'
              AND t.deleted_at IS NULL
              AND e.deleted_at IS NULL
            ORDER BY e.data_encontro ASC, e.horario_inicio ASC, e.id ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':start' => $startDate,
            ':end'   => $endDate,
        ]);

        $encontros = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $grouped = [];
        $turnosConfig = self::getTurnosConfig();

        foreach ($encontros as $enc) {
            $data = $enc['data_encontro'];
            if (!isset($grouped[$data])) {
                $grouped[$data] = [];
            }

            $turnoKey = $enc['turno'];
            $cfg = $turnosConfig[$turnoKey] ?? null;

            // Formatação amigável de horários
            $hIni = !empty($enc['horario_inicio']) ? substr($enc['horario_inicio'], 0, 5) : null;
            $hFim = !empty($enc['horario_fim']) ? substr($enc['horario_fim'], 0, 5) : null;
            $horarioFormatado = ($hIni && $hFim) ? "{$hIni} - {$hFim}" : ($cfg['horario_padrao'] ?? '');

            $isDeslocamento = ($enc['tipo'] === 'deslocamento');
            $enc['badge'] = $isDeslocamento ? '✈ Deslocamento' : null;
            $enc['is_deslocamento'] = $isDeslocamento;
            $enc['horario_formatado'] = $horarioFormatado;
            $enc['turno_nome'] = $cfg['nome'] ?? $turnoKey;
            $enc['turno_sigla'] = $turnoKey;
            $enc['turno_config'] = $cfg;

            $grouped[$data][] = $enc;
        }

        return $grouped;
    }

    /**
     * Costura de Teste 5 & Ticket 08: Validação rigorosa de conflito de agenda.
     * Verifica feriados nacionais, bloqueios particulares e choques de horário (aulas e deslocamentos).
     * Retorna null se livre, ou array com mensagem explicativa se houver conflito.
     */
    public function checkConflict(
        string $date,
        string $shift,
        ?int $ignoreEncontroId = null,
        ?int $ignoreTurmaId = null,
        bool $permitirExcecaoFeriado = false
    ): ?array {
        $shift = strtoupper(trim($shift));
        if (!in_array($shift, ['M', 'V', 'N', 'D'], true)) {
            throw new InvalidArgumentException("Turno inválido '{$shift}'. Deve ser M, V, N ou D.");
        }

        $this->ensureBloqueiosTable();

        // 1. Verificação de Bloqueios e Feriados na data
        $stmtB = $this->pdo->prepare("SELECT * FROM bloqueios_agenda WHERE data = ? AND bloqueante = 1");
        $stmtB->execute([$date]);
        $bloqueios = $stmtB->fetchAll(PDO::FETCH_ASSOC);

        foreach ($bloqueios as $b) {
            $permiteExcecao = ((int)$b['permite_excecao'] === 1);
            if ($permitirExcecaoFeriado && $permiteExcecao) {
                continue; // Exceção consciente aceita pelo instrutor
            }
            $dataFmt = date('d/m/Y', strtotime($date));
            $tipoLabel = ($b['tipo'] === 'feriado_nacional') ? 'Feriado Nacional' : 'Bloqueio Pessoal do Instrutor';
            $excecaoMsg = $permiteExcecao
                ? " Para agendar nesta data extraordinariamente, confirme a exceção consciente."
                : " Bloqueio pessoal intransponível.";

            return [
                'tipo'            => $b['tipo'],
                'data'            => $date,
                'turno'           => $shift,
                'bloqueio'        => $b,
                'motivo'          => "{$tipoLabel}: {$b['descricao']}.",
                'mensagem'        => "Bloqueio de agenda no dia {$dataFmt}: {$tipoLabel} ({$b['descricao']}).{$excecaoMsg}",
                'permite_excecao' => $permiteExcecao,
            ];
        }

        $turnosConfig = self::getTurnosConfig();
        $targetTurnoNome = $turnosConfig[$shift]['nome'] ?? $shift;

        // 2. Verificação de Encontros Ativos (Aulas e Deslocamentos Logísticos)
        $query = "
            SELECT 
                e.id AS encontro_id,
                e.turma_id,
                e.numero_encontro,
                e.data_encontro,
                e.turno,
                e.horario_inicio,
                e.horario_fim,
                e.tipo,
                t.codigo_turma,
                t.curso_nome,
                t.cliente_nome,
                t.status AS turma_status
            FROM encontros e
            INNER JOIN turmas t ON t.id = e.turma_id
            WHERE e.data_encontro = :data
              AND t.status != 'cancelada'
              AND t.deleted_at IS NULL
              AND e.deleted_at IS NULL
        ";

        $params = [':data' => $date];

        if ($ignoreEncontroId !== null && $ignoreEncontroId > 0) {
            $query .= " AND e.id != :ignore_id";
            $params[':ignore_id'] = $ignoreEncontroId;
        }

        if ($ignoreTurmaId !== null && $ignoreTurmaId > 0) {
            $query .= " AND e.turma_id != :ignore_turma_id";
            $params[':ignore_turma_id'] = $ignoreTurmaId;
        }

        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);

        $encontrosAtivos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($encontrosAtivos as $ativo) {
            $ativoTurno = $ativo['turno'];
            $ativoTurnoNome = $turnosConfig[$ativoTurno]['nome'] ?? $ativoTurno;

            $colide = false;
            $motivo = '';

            if ($shift === 'D') {
                $colide = true;
                $motivo = "Tentativa de agendamento em turno Dia Todo (D), porém o período {$ativoTurnoNome} já está comprometido.";
            } elseif ($ativoTurno === 'D') {
                $colide = true;
                $motivo = "Tentativa de agendamento no turno {$targetTurnoNome}, porém a data já está totalmente ocupada por Dia Todo (Integral).";
            } elseif ($shift === $ativoTurno) {
                $colide = true;
                $motivo = "Choque de horário no turno {$targetTurnoNome}.";
            }

            if ($colide) {
                $dataFmt = date('d/m/Y', strtotime($date));
                $curso = $ativo['curso_nome'];
                $cliente = $ativo['cliente_nome'] ?: 'Sem cliente especificado';
                $hIni = !empty($ativo['horario_inicio']) ? substr($ativo['horario_inicio'], 0, 5) : '';
                $hFim = !empty($ativo['horario_fim']) ? substr($ativo['horario_fim'], 0, 5) : '';
                $horarioTxt = ($hIni && $hFim) ? " ({$hIni} às {$hFim})" : '';

                if (($ativo['tipo'] ?? '') === 'deslocamento') {
                    $mensagem = "Choque de horário no dia {$dataFmt}: {$motivo} Já existe deslocamento logístico (✈) agendado para a turma '{$curso}' ({$cliente}) no turno {$ativoTurnoNome}.";
                    return [
                        'tipo'         => 'choque_deslocamento',
                        'data'         => $date,
                        'turno'        => $shift,
                        'conflito_com' => $ativo,
                        'motivo'       => $motivo,
                        'mensagem'     => $mensagem,
                    ];
                }

                $mensagem = "Choque de horário no dia {$dataFmt}: {$motivo} Já existe aula agendada de '{$curso}' para o cliente '{$cliente}'{$horarioTxt}.";

                return [
                    'tipo'         => 'choque_turno',
                    'data'         => $date,
                    'turno'        => $shift,
                    'conflito_com' => $ativo,
                    'motivo'       => $motivo,
                    'mensagem'     => $mensagem,
                ];
            }
        }

        return null;
    }

    /**
     * Agenda um encontro, exigindo confirmação explícita para uma sobreposição de turno.
     */
    public function scheduleEncontro(array $data): array
    {
        $turmaId = (int)($data['turma_id'] ?? 0);
        $dataEncontro = trim((string)($data['data_encontro'] ?? ''));
        $turno = strtoupper(trim((string)($data['turno'] ?? 'V')));
        $numeroEncontro = (int)($data['numero_encontro'] ?? 1);
        $horarioInicio = $data['horario_inicio'] ?? null;
        $horarioFim = $data['horario_fim'] ?? null;
        $conteudoPrevisto = $data['conteudo_previsto'] ?? null;
        $permitirExcecao = (bool)($data['confirmar_excecao_feriado'] ?? false);
        $confirmarSobreposicao = (bool)($data['confirmar_sobreposicao'] ?? false);

        if ($turmaId <= 0 || empty($dataEncontro)) {
            throw new InvalidArgumentException("Dados de agendamento incompletos (turma_id e data_encontro obrigatórios).");
        }

        // Validação da Costura de Teste 5 e Feriados
        $conflito = $this->checkConflict($dataEncontro, $turno, null, null, $permitirExcecao);
        if ($conflito !== null && !($confirmarSobreposicao && self::isConfirmableOverlap($conflito))) {
            throw new InvalidArgumentException($conflito['mensagem']);
        }

        // Insere o encontro
        $stmt = $this->pdo->prepare("
            INSERT INTO encontros (
                turma_id, numero_encontro, data_encontro, turno,
                horario_inicio, horario_fim, conteudo_previsto
            ) VALUES (
                :turma_id, :numero_encontro, :data_encontro, :turno,
                :horario_inicio, :horario_fim, :conteudo_previsto
            )
        ");

        $stmt->execute([
            ':turma_id'          => $turmaId,
            ':numero_encontro'   => $numeroEncontro,
            ':data_encontro'     => $dataEncontro,
            ':turno'             => $turno,
            ':horario_inicio'    => $horarioInicio,
            ':horario_fim'       => $horarioFim,
            ':conteudo_previsto' => $conteudoPrevisto,
        ]);

        $encontroId = (int)$this->pdo->lastInsertId();

        return [
            'id'              => $encontroId,
            'turma_id'        => $turmaId,
            'data_encontro'   => $dataEncontro,
            'turno'           => $turno,
            'numero_encontro' => $numeroEncontro,
            'status'          => 'agendado',
        ];
    }

    /**
     * Agenda um bloqueio de deslocamento logístico / viagem para uma turma.
     */
    public function scheduleDeslocamento(
        int $turmaId,
        string $data,
        string $turno,
        string $direcao = 'ida',
        ?string $descricao = null,
        bool $permitirExcecaoFeriado = false,
        bool $confirmarSobreposicao = false
    ): array {
        $data = trim($data);
        $turno = strtoupper(trim($turno));
        if ($turmaId <= 0 || empty($data)) {
            throw new InvalidArgumentException("Dados de agendamento de deslocamento incompletos (turma_id e data obrigatórios).");
        }

        $conflito = $this->checkConflict($data, $turno, null, null, $permitirExcecaoFeriado);
        if ($conflito !== null && !($confirmarSobreposicao && self::isConfirmableOverlap($conflito))) {
            throw new InvalidArgumentException($conflito['mensagem']);
        }

        $stmtNum = $this->pdo->prepare("SELECT COALESCE(MAX(numero_encontro), 0) + 1 FROM encontros WHERE turma_id = ?");
        $stmtNum->execute([$turmaId]);
        $numeroEncontro = (int)$stmtNum->fetchColumn();

        $stmtDesc = $this->pdo->prepare("SELECT curso_nome, cidade FROM turmas WHERE id = ?");
        $stmtDesc->execute([$turmaId]);
        $turma = $stmtDesc->fetch(PDO::FETCH_ASSOC);
        $cidade = $turma['cidade'] ?? 'Destino';
        $descDefault = ($direcao === 'ida')
            ? "Deslocamento Ida (✈): Goiânia -> {$cidade}"
            : "Deslocamento Volta (✈): {$cidade} -> Goiânia";

        $conteudo = $descricao ?: $descDefault;

        $stmt = $this->pdo->prepare("
            INSERT INTO encontros (
                turma_id, numero_encontro, data_encontro, turno,
                tipo, conteudo_previsto, abonado
            ) VALUES (
                :turma_id, :numero_encontro, :data_encontro, :turno,
                'deslocamento', :conteudo_previsto, 0
            )
        ");

        $stmt->execute([
            ':turma_id'          => $turmaId,
            ':numero_encontro'   => $numeroEncontro,
            ':data_encontro'     => $data,
            ':turno'             => $turno,
            ':conteudo_previsto' => $conteudo,
        ]);

        $encontroId = (int)$this->pdo->lastInsertId();

        return [
            'id'              => $encontroId,
            'turma_id'        => $turmaId,
            'data_encontro'   => $data,
            'turno'           => $turno,
            'tipo'            => 'deslocamento',
            'numero_encontro' => $numeroEncontro,
            'descricao'       => $conteudo,
            'status'          => 'agendado',
        ];
    }

    /**
     * Adiciona blocos de deslocamento logístico automáticos (ida e/ou volta) para uma turma fora de Goiânia.
     */
    public function addDeslocamentosParaTurma(
        int $turmaId,
        bool $ida = true,
        bool $volta = true,
        ?string $turno = null,
        ?string $dataIda = null,
        ?string $dataVolta = null,
        bool $permitirExcecaoFeriado = false
    ): array {
        $stmt = $this->pdo->prepare("SELECT * FROM turmas WHERE id = ?");
        $stmt->execute([$turmaId]);
        $turma = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$turma) {
            throw new InvalidArgumentException("Turma ID {$turmaId} não encontrada para inclusão de deslocamentos.");
        }

        $turnoUsado = $turno ? strtoupper(trim($turno)) : $turma['turno_padrao'];
        $cidade = $turma['cidade'] ?? 'Destino';
        $criados = [];

        if ($ida) {
            $dataIdaCalc = $dataIda ?: date('Y-m-d', strtotime('-1 day', strtotime($turma['data_inicio'])));
            $criados[] = $this->scheduleDeslocamento(
                $turmaId,
                $dataIdaCalc,
                $turnoUsado,
                'ida',
                "Deslocamento Ida (✈): Goiânia -> {$cidade}",
                $permitirExcecaoFeriado
            );
        }

        if ($volta) {
            $dataVoltaCalc = $dataVolta ?: date('Y-m-d', strtotime('+1 day', strtotime($turma['data_conclusao'])));
            $criados[] = $this->scheduleDeslocamento(
                $turmaId,
                $dataVoltaCalc,
                $turnoUsado,
                'volta',
                "Deslocamento Volta (✈): {$cidade} -> Goiânia",
                $permitirExcecaoFeriado
            );
        }

        return $criados;
    }

    /**
     * Motor de Adiamento / Remarcação em Bloco com Transação Atômica.
     * Move todos os encontros de aula e deslocamentos vinculados para uma nova data de início.
     * Valida ausência de choques em todas as novas datas antes de modificar o banco.
     */
    public function rescheduleTurma(
        int $turmaId,
        string $novaDataInicio,
        ?array $ajustesFinos = null,
        bool $permitirExcecaoFeriado = false
    ): array {
        $stmt = $this->pdo->prepare("SELECT * FROM turmas WHERE id = ?");
        $stmt->execute([$turmaId]);
        $turma = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$turma) {
            throw new InvalidArgumentException("Turma ID {$turmaId} não encontrada para remarcação.");
        }
        if (!empty($turma['deleted_at'])) {
            throw new InvalidArgumentException("Não é permitido remarcar uma turma na Lixeira. Restaure-a primeiro.");
        }
        if ($turma['status'] === 'cancelada') {
            throw new InvalidArgumentException("Não é permitido remarcar uma turma com status cancelada.");
        }

        // Buscar todos os encontros da turma ordenados
        $stmtEncs = $this->pdo->prepare("SELECT * FROM encontros WHERE turma_id = ? ORDER BY data_encontro ASC, id ASC");
        $stmtEncs->execute([$turmaId]);
        $encs = $stmtEncs->fetchAll(PDO::FETCH_ASSOC);

        if (empty($encs)) {
            throw new InvalidArgumentException("A turma não possui encontros cadastrados para remarcação.");
        }

        // Calcular delta em dias entre a data de início atual e a novaDataInicio
        $dataInicioAtual = $turma['data_inicio'];
        $diffSeconds = strtotime($novaDataInicio) - strtotime($dataInicioAtual);
        $diffDays = (int)round($diffSeconds / 86400);

        // Projetar novas datas para cada encontro
        $projected = [];
        foreach ($encs as $enc) {
            $encId = (int)$enc['id'];
            if ($ajustesFinos !== null && isset($ajustesFinos[$encId])) {
                $projData = (string)$ajustesFinos[$encId]['data'];
                $projTurno = strtoupper((string)($ajustesFinos[$encId]['turno'] ?? $enc['turno']));
            } else {
                $projData = date('Y-m-d', strtotime("{$diffDays} days", strtotime($enc['data_encontro'])));
                $projTurno = (string)$enc['turno'];
            }
            $projected[$encId] = [
                'encontro'   => $enc,
                'nova_data'  => $projData,
                'novo_turno' => $projTurno,
            ];
        }

        // 1. VALIDAÇÃO ATÔMICA ANTECIPADA (Pre-flight atomic seam)
        // Valida se qualquer nova data colide com feriados/bloqueios ou com OUTRAS turmas
        foreach ($projected as $encId => $p) {
            $conflict = $this->checkConflict(
                $p['nova_data'],
                $p['novo_turno'],
                $encId,
                $turmaId,
                $permitirExcecaoFeriado
            );
            if ($conflict !== null) {
                $dataFmt = date('d/m/Y', strtotime($p['nova_data']));
                throw new InvalidArgumentException(
                    "Falha ao remarcar turma '{$turma['curso_nome']}': Choque de horário no dia {$dataFmt} no turno [{$p['novo_turno']}]. {$conflict['mensagem']}"
                );
            }
        }

        // 2. GRAVAÇÃO TRANSACIONAL ATÔMICA
        $this->pdo->beginTransaction();
        try {
            $stmtUpdateEnc = $this->pdo->prepare("
                UPDATE encontros 
                SET data_encontro = ?, turno = ?, updated_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ");

            $classDates = [];
            foreach ($projected as $encId => $p) {
                $stmtUpdateEnc->execute([$p['nova_data'], $p['novo_turno'], $encId]);
                if ($p['encontro']['tipo'] === 'aula') {
                    $classDates[] = $p['nova_data'];
                }
            }

            sort($classDates);
            $newStart = !empty($classDates) ? $classDates[0] : $novaDataInicio;
            $newEnd = !empty($classDates) ? end($classDates) : $novaDataInicio;

            $stmtUpdateTurma = $this->pdo->prepare("
                UPDATE turmas 
                SET data_inicio = ?, data_conclusao = ?, updated_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ");
            $stmtUpdateTurma->execute([$newStart, $newEnd, $turmaId]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        // Verificar alerta de sobrecarga para o novo mês
        $novoAno = (int)date('Y', strtotime($newStart));
        $novoMes = (int)date('m', strtotime($newStart));
        $workload = $this->calculateMonthlyWorkload($novoAno, $novoMes);

        return [
            'success'        => true,
            'turma_id'       => $turmaId,
            'nova_inicio'    => $newStart,
            'nova_conclusao' => $newEnd,
            'total_movidos'  => count($projected),
            'workload_mes'   => $workload,
            'mensagem'       => "Turma '{$turma['curso_nome']}' remarcada com sucesso para {$newStart} a {$newEnd}.",
        ];
    }

    /**
     * Calcula a carga horária mensal acumulada e verifica o teto de 80h.
     * Considera exclusivamente encontros pedagógicos (tipo = 'aula') de turmas ativas.
     */
    public function calculateMonthlyWorkload(int $year, int $month): array
    {
        $startMonth = sprintf('%04d-%02d-01', $year, $month);
        $totalDays = (int)date('t', strtotime($startMonth));
        $endMonth = sprintf('%04d-%02d-%02d', $year, $month, $totalDays);

        $sql = "
            SELECT e.id, e.turma_id, e.turno, e.horario_inicio, e.horario_fim, e.tipo,
                   t.carga_horaria, t.codigo_turma, t.curso_nome,
                   (SELECT COUNT(*) FROM encontros e2 WHERE e2.turma_id = e.turma_id AND e2.tipo = 'aula') AS total_aulas_turma
            FROM encontros e
            INNER JOIN turmas t ON t.id = e.turma_id
            WHERE e.data_encontro BETWEEN :start AND :end
              AND e.tipo = 'aula'
              AND t.status != 'cancelada'
              AND t.deleted_at IS NULL
              AND e.deleted_at IS NULL
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':start' => $startMonth, ':end' => $endMonth]);
        $aulas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalHoras = 0.0;
        foreach ($aulas as $a) {
            $horas = 0.0;
            if (!empty($a['horario_inicio']) && !empty($a['horario_fim'])) {
                $tIni = strtotime($a['horario_inicio']);
                $tFim = strtotime($a['horario_fim']);
                if ($tFim > $tIni) {
                    $horas = ($tFim - $tIni) / 3600.0;
                }
            }
            if ($horas <= 0 && !empty($a['carga_horaria']) && (int)$a['total_aulas_turma'] > 0) {
                $horas = (float)$a['carga_horaria'] / (int)$a['total_aulas_turma'];
            }
            if ($horas <= 0) {
                $horas = match($a['turno']) {
                    'D' => 8.0,
                    'M', 'V', 'N' => 4.0,
                    default => 4.0,
                };
            }
            $totalHoras += $horas;
        }

        $totalHorasInt = (int)round($totalHoras);
        $teto = 80;
        $isSobrecarga = $totalHorasInt > $teto;
        $status = $isSobrecarga ? 'sobrecarga' : ($totalHorasInt >= 64 ? 'atencao' : 'normal');
        $pct = round(($totalHoras / $teto) * 100, 1);
        $msgAviso = $isSobrecarga
            ? "Alerta de Capacidade: A carga horária prevista para este mês ({$totalHorasInt}h) ultrapassa o teto recomendado de {$teto}h mensais."
            : null;

        return [
            'ano'               => $year,
            'mes'               => $month,
            'total_horas'       => $totalHorasInt,
            'teto_horas'        => $teto,
            'porcentagem'       => $pct,
            'is_sobrecarga'     => $isSobrecarga,
            'status_capacidade' => $status,
            'mensagem_aviso'    => $msgAviso,
        ];
    }

    /**
     * Gera a estrutura completa de dados para a Visão Mensal detalhada.
     */
    public function getMonthCalendarData(int $year, int $month, ?string $today = null): array
    {
        $today = $today ?: date('Y-m-d');
        $nomesMeses = self::getNomesMeses();
        $turnosConfig = self::getTurnosConfig();

        $firstDayOfMonth = sprintf('%04d-%02d-01', $year, $month);
        $totalDaysInMonth = (int)date('t', strtotime($firstDayOfMonth));
        $lastDayOfMonth = sprintf('%04d-%02d-%02d', $year, $month, $totalDaysInMonth);

        // Dia da semana do primeiro dia (0 = Domingo, 6 = Sábado)
        $startDayOfWeek = (int)date('w', strtotime($firstDayOfMonth));

        // Calcular intervalo expandido com dias de preenchimento
        $calendarStartDate = date('Y-m-d', strtotime("-{$startDayOfWeek} days", strtotime($firstDayOfMonth)));
        
        // Determinar o último dia para completar grade de semanas completas (42 dias = 6 semanas)
        $totalCells = 42;
        $calendarEndDate = date('Y-m-d', strtotime("+" . ($totalCells - 1) . " days", strtotime($calendarStartDate)));

        // Buscar agendamentos no intervalo expandido
        $schedules = $this->getScheduleForDateRange($calendarStartDate, $calendarEndDate);
        $bloqueios = $this->getBloqueiosForDateRange($calendarStartDate, $calendarEndDate);

        $weeks = [];
        $currentDate = $calendarStartDate;
        $dayIndex = 0;
        $currentWeek = [];

        while ($dayIndex < $totalCells) {
            $curYear = (int)date('Y', strtotime($currentDate));
            $curMonth = (int)date('m', strtotime($currentDate));
            $curDay = (int)date('d', strtotime($currentDate));

            $isCurrentMonth = ($curYear === $year && $curMonth === $month);
            $isPast = ($currentDate < $today);
            $isToday = ($currentDate === $today);

            $encsDoDia = $schedules[$currentDate] ?? [];
            $bloqueioDoDia = $bloqueios[$currentDate] ?? null;
            $turnosOcupados = [];

            foreach ($encsDoDia as $e) {
                if (!in_array($e['turno'], $turnosOcupados, true)) {
                    $turnosOcupados[] = $e['turno'];
                }
            }

            // Calcular turnos disponíveis
            $turnosDisponiveis = [];
            if (in_array('D', $turnosOcupados, true)) {
                // Dia todo ocupado: nenhum livre
                $turnosDisponiveis = [];
            } else {
                foreach (['M', 'V', 'N'] as $t) {
                    if (!in_array($t, $turnosOcupados, true)) {
                        $turnosDisponiveis[] = $t;
                    }
                }
            }

            // Montar dados do popover e pontes
            $ponteDoDia = ($bloqueioDoDia === null) ? $this->checkPonteFeriado($currentDate) : null;
            $popover = null;
            if (!empty($encsDoDia) || $bloqueioDoDia !== null || $ponteDoDia !== null) {
                $popoverEncontros = [];
                foreach ($encsDoDia as $e) {
                    $popoverEncontros[] = [
                        'encontro_id'  => $e['encontro_id'],
                        'turma_id'     => $e['turma_id'],
                        'curso'        => $e['curso_nome'],
                        'cliente'      => $e['cliente_nome'] ?: 'Institucional',
                        'horario'      => $e['horario_formatado'],
                        'turno_sigla'  => $e['turno'],
                        'turno_nome'   => $e['turno_nome'],
                        'tipo'         => $e['tipo'] ?? 'aula',
                        'badge'        => $e['badge'] ?? null,
                        'status_turma' => $e['turma_status'],
                        'conteudo'     => $e['conteudo_previsto'] ?: $e['conteudo_ministrado'],
                    ];
                }

                $popover = [
                    'data'              => $currentDate,
                    'data_formatada'    => date('d/m/Y', strtotime($currentDate)),
                    'dia_semana'        => self::getDiaSemanaExtenso((int)date('w', strtotime($currentDate))),
                    'encontros'         => $popoverEncontros,
                    'turnos_ocupados'   => $turnosOcupados,
                    'turnos_livres'     => $turnosDisponiveis,
                    'bloqueio'          => $bloqueioDoDia,
                    'ponte'             => $ponteDoDia,
                ];
            }

            $currentWeek[] = [
                'data'               => $currentDate,
                'ano'                => $curYear,
                'mes'                => $curMonth,
                'dia'                => $curDay,
                'dia_semana'         => (int)date('w', strtotime($currentDate)),
                'is_current_month'   => $isCurrentMonth,
                'is_past'            => $isPast,
                'is_today'           => $isToday,
                'encontros'          => $encsDoDia,
                'bloqueio'           => $bloqueioDoDia,
                'ponte'              => $ponteDoDia,
                'turnos_ocupados'    => $turnosOcupados,
                'turnos_disponiveis' => $turnosDisponiveis,
                'popover'            => $popover,
            ];

            if (count($currentWeek) === 7) {
                $weeks[] = $currentWeek;
                $currentWeek = [];
            }

            $currentDate = date('Y-m-d', strtotime('+1 day', strtotime($currentDate)));
            $dayIndex++;
        }

        // Navegação de mês
        $prevMonthTimestamp = strtotime('-1 month', strtotime($firstDayOfMonth));
        $nextMonthTimestamp = strtotime('+1 month', strtotime($firstDayOfMonth));

        $workload = $this->calculateMonthlyWorkload($year, $month);

        return [
            'ano'              => $year,
            'mes'              => $month,
            'nome_mes'         => $nomesMeses[$month] ?? "Mês {$month}",
            'hoje'             => $today,
            'semanas'          => $weeks,
            'workload'         => $workload,
            'dias_semana_abrv' => ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'],
            'nav'              => [
                'prev_ano' => (int)date('Y', $prevMonthTimestamp),
                'prev_mes' => (int)date('m', $prevMonthTimestamp),
                'next_ano' => (int)date('Y', $nextMonthTimestamp),
                'next_mes' => (int)date('m', $nextMonthTimestamp),
                'hoje_ano' => (int)date('Y', strtotime($today)),
                'hoje_mes' => (int)date('m', strtotime($today)),
            ],
            'turnos_config'    => $turnosConfig,
        ];
    }

    /**
     * Gera a estrutura completa de dados para a Visão Anual (12 meses lado a lado).
     */
    public function getYearCalendarData(int $year, ?string $today = null): array
    {
        $today = $today ?: date('Y-m-d');
        $nomesMeses = self::getNomesMeses();
        $turnosConfig = self::getTurnosConfig();

        // Buscar todos os encontros do ano de uma vez
        $startOfYear = sprintf('%04d-01-01', $year);
        $endOfYear = sprintf('%04d-12-31', $year);
        $yearSchedules = $this->getScheduleForDateRange($startOfYear, $endOfYear);

        $meses = [];
        $totalAulasAno = 0;

        for ($m = 1; $m <= 12; $m++) {
            $firstDay = sprintf('%04d-%02d-01', $year, $m);
            $totalDays = (int)date('t', strtotime($firstDay));
            $startDayOfWeek = (int)date('w', strtotime($firstDay));

            $diasDoMes = [];
            $totalAulasMes = 0;

            for ($d = 1; $d <= $totalDays; $d++) {
                $dateStr = sprintf('%04d-%02d-%02d', $year, $m, $d);
                $isPast = ($dateStr < $today);
                $isToday = ($dateStr === $today);

                $encs = $yearSchedules[$dateStr] ?? [];
                $totalAulasMes += count($encs);

                $turnosOcupados = [];
                foreach ($encs as $e) {
                    if (!in_array($e['turno'], $turnosOcupados, true)) {
                        $turnosOcupados[] = $e['turno'];
                    }
                }

                $popover = null;
                if (!empty($encs)) {
                    $popoverEncs = [];
                    foreach ($encs as $e) {
                        $popoverEncs[] = [
                            'encontro_id'  => $e['encontro_id'],
                            'turma_id'     => $e['turma_id'],
                            'curso'        => $e['curso_nome'],
                            'cliente'      => $e['cliente_nome'] ?: 'Institucional',
                            'horario'      => $e['horario_formatado'],
                            'turno_sigla'  => $e['turno'],
                            'turno_nome'   => $e['turno_nome'],
                            'status_turma' => $e['turma_status'],
                        ];
                    }
                    $popover = [
                        'data'            => $dateStr,
                        'data_formatada'  => date('d/m/Y', strtotime($dateStr)),
                        'dia_semana'      => self::getDiaSemanaExtenso((int)date('w', strtotime($dateStr))),
                        'encontros'       => $popoverEncs,
                        'turnos_ocupados' => $turnosOcupados,
                    ];
                }

                $diasDoMes[$d] = [
                    'data'            => $dateStr,
                    'dia'             => $d,
                    'dia_semana'      => (int)date('w', strtotime($dateStr)),
                    'is_past'         => $isPast,
                    'is_today'        => $isToday,
                    'turnos_ocupados' => $turnosOcupados,
                    'total_encontros' => count($encs),
                    'popover'         => $popover,
                ];
            }

            $totalAulasAno += $totalAulasMes;

            $workloadMes = $this->calculateMonthlyWorkload($year, $m);

            $meses[$m] = [
                'numero'          => $m,
                'nome'            => $nomesMeses[$m],
                'start_weekday'   => $startDayOfWeek,
                'total_dias'      => $totalDays,
                'total_aulas'     => $totalAulasMes,
                'dias'            => $diasDoMes,
                'workload'        => $workloadMes,
            ];
        }

        return [
            'ano'              => $year,
            'hoje'             => $today,
            'total_aulas_ano'  => $totalAulasAno,
            'meses'            => $meses,
            'turnos_config'    => $turnosConfig,
            'nav'              => [
                'prev_ano' => $year - 1,
                'next_ano' => $year + 1,
                'hoje_ano' => (int)date('Y', strtotime($today)),
            ],
        ];
    }

    /**
     * Retorna o dia da semana por extenso em português.
     */
    public static function getDiaSemanaExtenso(int $dayOfWeek): string
    {
        $dias = [
            0 => 'Domingo',
            1 => 'Segunda-feira',
            2 => 'Terça-feira',
            3 => 'Quarta-feira',
            4 => 'Quinta-feira',
            5 => 'Sexta-feira',
            6 => 'Sábado',
        ];
        return $dias[$dayOfWeek] ?? '';
    }
}
