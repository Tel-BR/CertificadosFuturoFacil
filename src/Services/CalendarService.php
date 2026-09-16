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
                'cor_texto'      => '#B45309', // Âmbar escuro
                'cor_fundo'      => '#FEF3C7', // Âmbar pastel
                'cor_borda'      => '#F59E0B',
                'slot_ordem'     => 1,
                'horario_padrao' => '08:00 - 12:00',
            ],
            'V' => [
                'letra'          => 'V',
                'nome'           => 'Vespertino',
                'cor_texto'      => '#0E7490', // Ciano / Petróleo escuro
                'cor_fundo'      => '#ECFEFF', // Ciano pastel
                'cor_borda'      => '#06B6D4',
                'slot_ordem'     => 2,
                'horario_padrao' => '14:00 - 18:00',
            ],
            'N' => [
                'letra'          => 'N',
                'nome'           => 'Noturno',
                'cor_texto'      => '#1E293B', // Grafite / Slate escuro
                'cor_fundo'      => '#F1F5F9', // Cinza neutro
                'cor_borda'      => '#94A3B8',
                'slot_ordem'     => 3,
                'horario_padrao' => '19:00 - 22:30',
            ],
            'D' => [
                'letra'          => 'D',
                'nome'           => 'Dia Todo / Integral',
                'cor_texto'      => '#065F46', // Esmeralda escuro
                'cor_fundo'      => '#D1FAE5', // Verde menta pastel
                'cor_borda'      => '#10B981',
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
                e.abonado,
                t.codigo_turma,
                t.curso_nome,
                t.cliente_nome,
                t.status AS turma_status,
                t.modalidade,
                t.carga_horaria
            FROM encontros e
            INNER JOIN turmas t ON t.id = e.turma_id
            WHERE e.data_encontro BETWEEN :start AND :end
              AND t.status != 'cancelada'
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

            $enc['horario_formatado'] = $horarioFormatado;
            $enc['turno_nome'] = $cfg['nome'] ?? $turnoKey;
            $enc['turno_sigla'] = $turnoKey;
            $enc['turno_config'] = $cfg;

            $grouped[$data][] = $enc;
        }

        return $grouped;
    }

    /**
     * Costura de Teste 5: Validação rigorosa de choque de horário.
     * Retorna null se livre, ou array com mensagem explicativa se houver conflito.
     */
    public function checkConflict(string $date, string $shift, ?int $ignoreEncontroId = null): ?array
    {
        $shift = strtoupper(trim($shift));
        if (!in_array($shift, ['M', 'V', 'N', 'D'], true)) {
            throw new InvalidArgumentException("Turno inválido '{$shift}'. Deve ser M, V, N ou D.");
        }

        $turnosConfig = self::getTurnosConfig();
        $targetTurnoNome = $turnosConfig[$shift]['nome'] ?? $shift;

        // Regra de colisão:
        // - Se shift = 'D' (Dia Todo): colide se houver qualquer encontro ativo no dia ('M', 'V', 'N', 'D').
        // - Se shift in ('M', 'V', 'N'): colide se houver encontro no mesmo turno OU encontro 'D' (Dia Todo).
        $query = "
            SELECT 
                e.id AS encontro_id,
                e.turma_id,
                e.numero_encontro,
                e.data_encontro,
                e.turno,
                e.horario_inicio,
                e.horario_fim,
                t.codigo_turma,
                t.curso_nome,
                t.cliente_nome,
                t.status AS turma_status
            FROM encontros e
            INNER JOIN turmas t ON t.id = e.turma_id
            WHERE e.data_encontro = :data
              AND t.status != 'cancelada'
        ";

        if ($ignoreEncontroId !== null && $ignoreEncontroId > 0) {
            $query .= " AND e.id != :ignore_id";
        }

        $stmt = $this->pdo->prepare($query);
        $params = [':data' => $date];
        if ($ignoreEncontroId !== null && $ignoreEncontroId > 0) {
            $params[':ignore_id'] = $ignoreEncontroId;
        }
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
                $motivo = "Tentativa de agendamento no turno {$targetTurnoNome}, porém a data já está totalmente ocupada por turma de Dia Todo (Integral).";
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
     * Agenda um encontro com validação impeditiva de choque de horários.
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

        if ($turmaId <= 0 || empty($dataEncontro)) {
            throw new InvalidArgumentException("Dados de agendamento incompletos (turma_id e data_encontro obrigatórios).");
        }

        // Validação da Costura de Teste 5
        $conflito = $this->checkConflict($dataEncontro, $turno);
        if ($conflito !== null) {
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

            // Montar dados do popover
            $popover = null;
            if (!empty($encsDoDia)) {
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

        return [
            'ano'              => $year,
            'mes'              => $month,
            'nome_mes'         => $nomesMeses[$month] ?? "Mês {$month}",
            'hoje'             => $today,
            'semanas'          => $weeks,
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

            $meses[$m] = [
                'numero'          => $m,
                'nome'            => $nomesMeses[$m],
                'start_weekday'   => $startDayOfWeek,
                'total_dias'      => $totalDays,
                'total_aulas'     => $totalAulasMes,
                'dias'            => $diasDoMes,
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
