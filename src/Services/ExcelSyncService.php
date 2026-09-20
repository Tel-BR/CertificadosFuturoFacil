<?php
/**
 * Serviço de Sincronização Bidirecional com Planilhas Excel (.xlsx)
 * Plataforma Integrada de Gestão Pedagógica, Diário e Certificados Futuro Fácil
 */

declare(strict_types=1);

namespace FuturoFacil\Services;

require_once __DIR__ . '/ValidatorService.php';
require_once __DIR__ . '/AttendanceService.php';
require_once __DIR__ . '/CalendarService.php';
require_once __DIR__ . '/TurmaService.php';

use FuturoFacil\Config\Database;
use FuturoFacil\Services\ValidatorService;
use FuturoFacil\Services\CalendarService;
use FuturoFacil\Services\TurmaService;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use SimpleXMLElement;
use Throwable;
use ZipArchive;

class ExcelSyncService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getConnection();
    }

    /**
     * Exporta a planilha completa da turma estruturada rigorosamente em 3 abas:
     * - Aba 1: Alunos e Chamada
     * - Aba 2: Diário e Planos
     * - Aba 3: Dados da Turma
     * 
     * Retorna os bytes binários do arquivo .xlsx.
     */
    public function exportTurmaSpreadsheet(int $turmaId): string
    {
        // 1. Consulta dados da turma
        $stmtTurma = $this->pdo->prepare("SELECT * FROM turmas WHERE id = ?");
        $stmtTurma->execute([$turmaId]);
        $turma = $stmtTurma->fetch(PDO::FETCH_ASSOC);

        if (!$turma) {
            throw new InvalidArgumentException("Turma ID {$turmaId} não encontrada para exportação.");
        }

        // 2. Consulta encontros da turma
        $stmtEnc = $this->pdo->prepare("
            SELECT * FROM encontros 
            WHERE turma_id = ? 
            ORDER BY numero_encontro ASC
        ");
        $stmtEnc->execute([$turmaId]);
        $encontros = $stmtEnc->fetchAll(PDO::FETCH_ASSOC);

        // 3. Consulta alunos da turma
        $stmtAlunos = $this->pdo->prepare("
            SELECT * FROM alunos 
            WHERE turma_id = ? 
            ORDER BY nome_completo ASC
        ");
        $stmtAlunos->execute([$turmaId]);
        $alunos = $stmtAlunos->fetchAll(PDO::FETCH_ASSOC);

        // 4. Consulta matriz de presenças
        $stmtFreq = $this->pdo->prepare("
            SELECT f.encontro_id, f.aluno_id, f.presente 
            FROM frequencias f
            JOIN encontros e ON e.id = f.encontro_id
            WHERE e.turma_id = ?
        ");
        $stmtFreq->execute([$turmaId]);
        $freqRows = $stmtFreq->fetchAll(PDO::FETCH_ASSOC);

        $freqMatrix = [];
        $hasRecordForEnc = [];
        foreach ($freqRows as $f) {
            $encId = (int)$f['encontro_id'];
            $alunoId = (int)$f['aluno_id'];
            $freqMatrix[$encId][$alunoId] = (int)$f['presente'];
            $hasRecordForEnc[$encId] = true;
        }

        // Frequências acumuladas calculadas
        $attendanceService = new AttendanceService($this->pdo);
        $cumulativeFrequencies = $attendanceService->calculateCumulativeFrequencies($turmaId);

        // 5. Constrói conteúdo das 3 abas
        // -------------------------------------------------------------
        // ABA 1: Alunos e Chamada
        // -------------------------------------------------------------
        $sheet1Rows = [];
        $header1 = ['Nº', 'Nome Completo', 'CPF'];
        $aulasEncontros = [];
        foreach ($encontros as $enc) {
            if ($enc['tipo'] === 'aula') {
                $aulasEncontros[] = $enc;
                $dataFmt = date('d/m', strtotime($enc['data_encontro']));
                $header1[] = "Encontro {$enc['numero_encontro']} ({$dataFmt})";
            }
        }
        $header1[] = 'Frequência (%)';
        $sheet1Rows[] = $header1;

        $alunoIndex = 1;
        foreach ($alunos as $a) {
            $alunoId = (int)$a['id'];
            $cpfExibicao = !empty($a['cpf']) ? ValidatorService::formatCpf($a['cpf']) : ($a['cpf_limpo'] ?? '');
            
            $row = [
                $alunoIndex++,
                trim((string)$a['nome_completo']),
                $cpfExibicao,
            ];

            foreach ($aulasEncontros as $enc) {
                $encId = (int)$enc['id'];
                if (isset($hasRecordForEnc[$encId])) {
                    $row[] = (int)($freqMatrix[$encId][$alunoId] ?? 0);
                } else {
                    $row[] = ''; // Encontro pendente de chamada
                }
            }

            $freqPerc = isset($cumulativeFrequencies[$alunoId])
                ? number_format($cumulativeFrequencies[$alunoId]['frequencia_acumulada'], 1, ',', '') . '%'
                : '100,0%';
            $row[] = $freqPerc;

            $sheet1Rows[] = $row;
        }

        // -------------------------------------------------------------
        // ABA 2: Diário e Planos
        // -------------------------------------------------------------
        $sheet2Rows = [
            ['Encontro', 'Data', 'Turno', 'Horário Início', 'Horário Fim', 'Intervalo (min)', 'Tipo', 'Abonado', 'Conteúdo Previsto', 'Conteúdo Ministrado']
        ];
        foreach ($encontros as $enc) {
            $sheet2Rows[] = [
                (int)$enc['numero_encontro'],
                date('d/m/Y', strtotime($enc['data_encontro'])),
                (string)$enc['turno'],
                substr((string)$enc['horario_inicio'], 0, 5),
                substr((string)$enc['horario_fim'], 0, 5),
                (int)($enc['intervalo_minutos'] ?? 0),
                (string)$enc['tipo'],
                ((int)$enc['abonado'] === 1) ? 'Sim' : 'Não',
                (string)($enc['conteudo_previsto'] ?? ''),
                (string)($enc['conteudo_ministrado'] ?? '')
            ];
        }

        // -------------------------------------------------------------
        // ABA 3: Dados da Turma
        // -------------------------------------------------------------
        $sheet3Rows = [
            ['Campo', 'Valor'],
            ['Código da Turma', (string)$turma['codigo_turma']],
            ['Nome do Curso', (string)$turma['curso_nome']],
            ['Cliente', (string)($turma['cliente_nome'] ?? 'Institucional')],
            ['Ordem de Serviço', (string)($turma['ordem_servico'] ?? '')],
            ['Modalidade', (string)($turma['modalidade'] ?? 'Presencial')],
            ['Carga Horária (h)', (int)$turma['carga_horaria']],
            ['Instrutor', (string)($turma['instrutor'] ?? 'Telmo Tropia')],
            ['Data de Início', date('d/m/Y', strtotime($turma['data_inicio']))],
            ['Data de Conclusão', date('d/m/Y', strtotime($turma['data_conclusao']))],
            ['Turno Padrão', (string)($turma['turno_padrao'] ?? 'V')],
            ['Status', (string)$turma['status']],
            ['Cidade / Local', (string)($turma['cidade'] ?? 'Goiânia - GO')],
            ['Chave de Acesso', (string)$turma['chave_acesso']],
            ['Ementa Oficial', (string)($turma['ementa'] ?? '')]
        ];

        // 6. Monta o pacote OpenXML (.xlsx) em memória temporária
        return $this->buildXlsxArchive([
            'Alunos e Chamada' => $sheet1Rows,
            'Diário e Planos'  => $sheet2Rows,
            'Dados da Turma'   => $sheet3Rows,
        ]);
    }

    /**
     * Analisa uma planilha Excel em memória comparando com o estado atual do banco de dados,
     * sem persistir nenhuma alteração. Retorna um relatório estruturado de Diff Visual.
     */
    public function generateDiff(int $turmaId, string $filePathOrContent): array
    {
        $sheetsData = $this->parseXlsx($filePathOrContent);

        $sheetAlunos = null;
        $sheetPlanos = null;
        $sheetDados = null;

        foreach ($sheetsData as $sheetName => $rows) {
            $normName = mb_strtolower(trim($sheetName), 'UTF-8');
            if (str_contains($normName, 'aluno') || str_contains($normName, 'chamada')) {
                $sheetAlunos = $rows;
            } elseif (str_contains($normName, 'plano') || str_contains($normName, 'diario') || str_contains($normName, 'diário')) {
                $sheetPlanos = $rows;
            } elseif (str_contains($normName, 'dado') || str_contains($normName, 'turma')) {
                $sheetDados = $rows;
            }
        }

        if (!$sheetAlunos) {
            $keys = array_keys($sheetsData);
            if (isset($keys[0])) $sheetAlunos = $sheetsData[$keys[0]];
            if (isset($keys[1])) $sheetPlanos = $sheetsData[$keys[1]];
            if (isset($keys[2])) $sheetDados = $sheetsData[$keys[2]];
        }

        // Consulta dados atuais da turma
        $stmtTurma = $this->pdo->prepare("SELECT * FROM turmas WHERE id = ?");
        $stmtTurma->execute([$turmaId]);
        $turmaAtual = $stmtTurma->fetch(PDO::FETCH_ASSOC);
        if (!$turmaAtual) {
            throw new InvalidArgumentException("Turma ID {$turmaId} não encontrada.");
        }

        // -------------------------------------------------------------
        // 1. DIFF ABA 3: Dados da Turma (Metadados)
        // -------------------------------------------------------------
        $metaAlterados = [];
        $metaInalterados = [];
        if (!empty($sheetDados)) {
            $metaMap = [];
            foreach ($sheetDados as $r) {
                if (count($r) >= 2) {
                    $k = mb_strtolower(trim((string)$r[0]), 'UTF-8');
                    $metaMap[$k] = trim((string)$r[1]);
                }
            }

            $fieldsToCheck = [
                'nome do curso'     => ['campo' => 'curso_nome', 'label' => 'Nome do Curso'],
                'cliente'           => ['campo' => 'cliente_nome', 'label' => 'Cliente'],
                'instrutor'         => ['campo' => 'instrutor', 'label' => 'Instrutor'],
                'carga horária (h)' => ['campo' => 'carga_horaria', 'label' => 'Carga Horária (h)', 'type' => 'int'],
                'ementa oficial'    => ['campo' => 'ementa', 'label' => 'Ementa Oficial'],
            ];

            foreach ($fieldsToCheck as $excelKey => $meta) {
                if (isset($metaMap[$excelKey]) && $metaMap[$excelKey] !== '') {
                    $novoVal = $metaMap[$excelKey];
                    $dbVal = (string)($turmaAtual[$meta['campo']] ?? '');
                    if (($meta['type'] ?? '') === 'int') {
                        $novoVal = (int)$novoVal;
                        $dbVal = (int)$dbVal;
                    }
                    if ($novoVal != $dbVal) {
                        $metaAlterados[] = [
                            'campo'    => $meta['label'],
                            'anterior' => $dbVal,
                            'novo'     => $novoVal,
                        ];
                    } else {
                        $metaInalterados[] = [
                            'campo' => $meta['label'],
                            'valor' => $dbVal,
                        ];
                    }
                }
            }
        }

        // -------------------------------------------------------------
        // 2. DIFF ABA 2: Encontros, Planos e Reagendamento Seguro
        // -------------------------------------------------------------
        $stmtEnc = $this->pdo->prepare("
            SELECT * FROM encontros 
            WHERE turma_id = ? 
            ORDER BY numero_encontro ASC
        ");
        $stmtEnc->execute([$turmaId]);
        $currentEncontros = $stmtEnc->fetchAll(PDO::FETCH_ASSOC);

        $encMapByNum = [];
        $encIds = [];
        foreach ($currentEncontros as $e) {
            $encMapByNum[(int)$e['numero_encontro']] = $e;
            $encIds[] = (int)$e['id'];
        }

        // Consulta contagem de presenças já gravadas por encontro
        $freqCountByEnc = [];
        if (!empty($encIds)) {
            $inClause = implode(',', $encIds);
            $stmtFreqCount = $this->pdo->query("
                SELECT encontro_id, COUNT(*) as total_freq, SUM(CASE WHEN presente = 1 THEN 1 ELSE 0 END) as total_presentes
                FROM frequencias 
                WHERE encontro_id IN ({$inClause})
                GROUP BY encontro_id
            ");
            foreach ($stmtFreqCount->fetchAll(PDO::FETCH_ASSOC) as $fc) {
                $freqCountByEnc[(int)$fc['encontro_id']] = [
                    'total'     => (int)$fc['total_freq'],
                    'presentes' => (int)$fc['total_presentes'],
                ];
            }
        }

        $encontrosAlterados = [];
        $encontrosInalterados = [];
        $totalReagendamentosSeguros = 0;
        $matchedEncNums = [];

        if (!empty($sheetPlanos) && count($sheetPlanos) > 1) {
            $headerP = array_map(fn($h) => mb_strtolower(trim((string)$h), 'UTF-8'), $sheetPlanos[0]);
            $colEncIdx = $this->findColumnIndex($headerP, ['encontro', 'nº encontro', 'nº']);
            $colPrevIdx = $this->findColumnIndex($headerP, ['conteúdo previsto', 'conteudo previsto', 'previsto']);
            $colMinIdx = $this->findColumnIndex($headerP, ['conteúdo ministrado', 'conteudo ministrado', 'ministrado']);
            $colDataIdx = $this->findColumnIndex($headerP, ['data', 'data da aula']);
            $colHoraIniIdx = $this->findColumnIndex($headerP, ['horário início', 'horario inicio', 'horário inicio', 'horario início']);
            $colHoraFimIdx = $this->findColumnIndex($headerP, ['horário fim', 'horario fim', 'horário término', 'horario termino']);
            $colIntervaloIdx = $this->findColumnIndex($headerP, ['intervalo (min)', 'intervalo min', 'intervalo minutos', 'intervalo']);

            for ($i = 1; $i < count($sheetPlanos); $i++) {
                $row = $sheetPlanos[$i];
                if (empty($row) || !isset($row[$colEncIdx])) {
                    continue;
                }
                $numEnc = (int)preg_replace('/\D/', '', (string)$row[$colEncIdx]);
                if ($numEnc <= 0 || !isset($encMapByNum[$numEnc])) {
                    continue;
                }

                $matchedEncNums[$numEnc] = true;
                $dbEnc = $encMapByNum[$numEnc];
                $encId = (int)$dbEnc['id'];
                $hasChamadas = isset($freqCountByEnc[$encId]) && $freqCountByEnc[$encId]['total'] > 0;
                $totalChamadas = $hasChamadas ? $freqCountByEnc[$encId]['total'] : 0;
                $totalPresentes = $hasChamadas ? $freqCountByEnc[$encId]['presentes'] : 0;

                $dataEnc = ($colDataIdx !== -1 && isset($row[$colDataIdx])) ? trim((string)$row[$colDataIdx]) : null;
                if ($dataEnc && preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $dataEnc, $m)) {
                    $dataEnc = sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
                }

                $horaIni = ($colHoraIniIdx !== -1 && isset($row[$colHoraIniIdx])) ? trim((string)$row[$colHoraIniIdx]) : null;
                if ($horaIni && preg_match('/^(\d{1,2}):(\d{2})/', $horaIni, $m)) {
                    $horaIni = sprintf('%02d:%02d:00', (int)$m[1], (int)$m[2]);
                }

                $horaFim = ($colHoraFimIdx !== -1 && isset($row[$colHoraFimIdx])) ? trim((string)$row[$colHoraFimIdx]) : null;
                if ($horaFim && preg_match('/^(\d{1,2}):(\d{2})/', $horaFim, $m)) {
                    $horaFim = sprintf('%02d:%02d:00', (int)$m[1], (int)$m[2]);
                }
                $intervaloMinutos = ($colIntervaloIdx !== -1 && isset($row[$colIntervaloIdx]) && $row[$colIntervaloIdx] !== '')
                    ? max(0, (int)$row[$colIntervaloIdx])
                    : null;

                $conteudoPrevisto = ($colPrevIdx !== -1 && isset($row[$colPrevIdx])) ? trim((string)$row[$colPrevIdx]) : null;
                $conteudoMinistrado = ($colMinIdx !== -1 && isset($row[$colMinIdx])) ? trim((string)$row[$colMinIdx]) : null;

                $mudancasEnc = [];
                $dataMudou = false;
                $horarioMudou = false;

                if ($dataEnc !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataEnc) && $dataEnc !== $dbEnc['data_encontro']) {
                    $mudancasEnc[] = [
                        'campo'    => 'Data',
                        'anterior' => $dbEnc['data_encontro'],
                        'novo'     => $dataEnc,
                    ];
                    $dataMudou = true;
                }

                if ($horaIni !== null && !empty($horaIni) && substr((string)$dbEnc['horario_inicio'], 0, 5) !== substr($horaIni, 0, 5)) {
                    $mudancasEnc[] = [
                        'campo'    => 'Horário Início',
                        'anterior' => substr((string)$dbEnc['horario_inicio'], 0, 5),
                        'novo'     => substr($horaIni, 0, 5),
                    ];
                    $horarioMudou = true;
                }

                if ($horaFim !== null && !empty($horaFim) && substr((string)$dbEnc['horario_fim'], 0, 5) !== substr($horaFim, 0, 5)) {
                    $mudancasEnc[] = [
                        'campo'    => 'Horário Fim',
                        'anterior' => substr((string)$dbEnc['horario_fim'], 0, 5),
                        'novo'     => substr($horaFim, 0, 5),
                    ];
                    $horarioMudou = true;
                }

                if ($intervaloMinutos !== null && $intervaloMinutos !== (int)($dbEnc['intervalo_minutos'] ?? 0)) {
                    $mudancasEnc[] = [
                        'campo' => 'Intervalo (min)',
                        'anterior' => (int)($dbEnc['intervalo_minutos'] ?? 0),
                        'novo' => $intervaloMinutos,
                    ];
                }

                if ($conteudoPrevisto !== null && $conteudoPrevisto !== '' && $conteudoPrevisto !== (string)$dbEnc['conteudo_previsto']) {
                    $mudancasEnc[] = [
                        'campo'    => 'Conteúdo Previsto',
                        'anterior' => (string)$dbEnc['conteudo_previsto'],
                        'novo'     => $conteudoPrevisto,
                    ];
                }

                if ($conteudoMinistrado !== null && $conteudoMinistrado !== '' && $conteudoMinistrado !== (string)$dbEnc['conteudo_ministrado']) {
                    $mudancasEnc[] = [
                        'campo'    => 'Conteúdo Ministrado',
                        'anterior' => (string)$dbEnc['conteudo_ministrado'],
                        'novo'     => $conteudoMinistrado,
                    ];
                }

                if (!empty($mudancasEnc)) {
                    $isReagendamentoSeguro = ($dataMudou || $horarioMudou) && $hasChamadas;
                    if ($isReagendamentoSeguro) {
                        $totalReagendamentosSeguros++;
                    }

                    $aviso = null;
                    if ($isReagendamentoSeguro) {
                        $aviso = "Reagendamento Seguro: Aula com chamada já realizada ({$totalChamadas} presenças registradas). As presenças e conteúdos ministrados serão 100% preservados na nova data.";
                    }

                    $encontrosAlterados[] = [
                        'id'                        => $encId,
                        'numero'                    => $numEnc,
                        'data_atual'                => $dbEnc['data_encontro'],
                        'data_nova'                 => $dataEnc ?? $dbEnc['data_encontro'],
                        'horario_atual'             => substr((string)$dbEnc['horario_inicio'], 0, 5) . ' - ' . substr((string)$dbEnc['horario_fim'], 0, 5),
                        'horario_novo'              => ($horaIni ? substr($horaIni, 0, 5) : substr((string)$dbEnc['horario_inicio'], 0, 5)) . ' - ' . ($horaFim ? substr($horaFim, 0, 5) : substr((string)$dbEnc['horario_fim'], 0, 5)),
                        'reagendamento_com_chamada' => $isReagendamentoSeguro,
                        'total_presencas'           => $totalChamadas,
                        'total_presentes'           => $totalPresentes,
                        'aviso'                     => $aviso,
                        'mudancas'                  => $mudancasEnc,
                    ];
                } else {
                    $encontrosInalterados[] = [
                        'id'      => $encId,
                        'numero'  => $numEnc,
                        'data'    => $dbEnc['data_encontro'],
                        'horario' => substr((string)$dbEnc['horario_inicio'], 0, 5) . ' - ' . substr((string)$dbEnc['horario_fim'], 0, 5),
                    ];
                }
            }
        }

        foreach ($currentEncontros as $dbEnc) {
            $n = (int)$dbEnc['numero_encontro'];
            if (!isset($matchedEncNums[$n])) {
                $encontrosInalterados[] = [
                    'id'      => (int)$dbEnc['id'],
                    'numero'  => $n,
                    'data'    => $dbEnc['data_encontro'],
                    'horario' => substr((string)$dbEnc['horario_inicio'], 0, 5) . ' - ' . substr((string)$dbEnc['horario_fim'], 0, 5),
                ];
            }
        }

        // -------------------------------------------------------------
        // 3. DIFF ABA 1: Alunos, Presenças e Regra de Preservação
        // -------------------------------------------------------------
        $stmtCurrentAlunos = $this->pdo->prepare("
            SELECT id, nome_completo, cpf, cpf_limpo 
            FROM alunos 
            WHERE turma_id = ?
            ORDER BY nome_completo ASC
        ");
        $stmtCurrentAlunos->execute([$turmaId]);
        $currentAlunos = $stmtCurrentAlunos->fetchAll(PDO::FETCH_ASSOC);

        $alunosById = [];
        $alunosByCpf = [];
        $alunosByNome = [];
        $alunoIdsList = [];
        foreach ($currentAlunos as $ca) {
            $aId = (int)$ca['id'];
            $alunosById[$aId] = $ca;
            $alunoIdsList[] = $aId;
            if (!empty($ca['cpf_limpo'])) {
                $alunosByCpf[$ca['cpf_limpo']] = $aId;
            }
            $alunosByNome[mb_strtolower(trim((string)$ca['nome_completo']), 'UTF-8')] = $aId;
        }

        $currentFreqMatrix = [];
        if (!empty($alunoIdsList)) {
            $inAlunos = implode(',', $alunoIdsList);
            $stmtFreqs = $this->pdo->query("
                SELECT encontro_id, aluno_id, presente 
                FROM frequencias 
                WHERE aluno_id IN ({$inAlunos})
            ");
            foreach ($stmtFreqs->fetchAll(PDO::FETCH_ASSOC) as $fr) {
                $currentFreqMatrix[(int)$fr['aluno_id']][(int)$fr['encontro_id']] = (int)$fr['presente'];
            }
        }

        $isAlunosEmpty = true;
        if (!empty($sheetAlunos) && count($sheetAlunos) > 1) {
            $headerTmp = $sheetAlunos[0];
            $colNomeTmp = $this->findColumnIndex(array_map('strval', $headerTmp), ['nome']);
            if ($colNomeTmp === -1) $colNomeTmp = 1;
            for ($k = 1; $k < count($sheetAlunos); $k++) {
                $row = $sheetAlunos[$k];
                if (!empty($row) && isset($row[$colNomeTmp])) {
                    $nomeVal = preg_replace('/\s+/', ' ', trim((string)$row[$colNomeTmp]));
                    if ($nomeVal !== '') {
                        $isAlunosEmpty = false;
                        break;
                    }
                }
            }
        }

        $alunosNovos = [];
        $alunosAtualizar = [];
        $alunosInalterados = [];
        $alunosAusentesPreservados = [];
        $inconformidades = [];
        $matchedAlunoIds = [];
        $abaAlunosVazia = $isAlunosEmpty;
        $preservacaoAlunosAtiva = $isAlunosEmpty;
        $preservacaoAviso = null;

        if ($isAlunosEmpty) {
            $preservacaoAviso = "Aba de alunos vazia detectada. Regra Estrita de Preservação ativada: 100% dos " . count($currentAlunos) . " aluno(s) cadastrados no banco de dados e seus históricos de chamada serão mantidos intactos.";
            foreach ($currentAlunos as $ca) {
                $alunosAusentesPreservados[] = [
                    'id'   => (int)$ca['id'],
                    'nome' => $ca['nome_completo'],
                    'cpf'  => $ca['cpf'],
                ];
                $alunosInalterados[] = [
                    'id'   => (int)$ca['id'],
                    'nome' => $ca['nome_completo'],
                    'cpf'  => $ca['cpf'],
                ];
            }
        } else {
            $headerA = $sheetAlunos[0];
            $colIdIdx = -1;
            $colNomeIdx = -1;
            $colCpfIdx = -1;
            $encontroCols = [];

            foreach ($headerA as $idx => $colHeader) {
                $normCol = mb_strtolower(trim((string)$colHeader), 'UTF-8');
                if ($normCol === 'id' || str_contains($normCol, 'id aluno') || str_contains($normCol, 'id banco')) {
                    $colIdIdx = $idx;
                } elseif (str_contains($normCol, 'nome')) {
                    $colNomeIdx = $idx;
                } elseif (str_contains($normCol, 'cpf')) {
                    $colCpfIdx = $idx;
                } elseif (preg_match('/(?:encontro|aula)\s*#?(\d+)/i', (string)$colHeader, $m)) {
                    $num = (int)$m[1];
                    if (isset($encMapByNum[$num])) {
                        $encontroCols[$idx] = (int)$encMapByNum[$num]['id'];
                    }
                } elseif (str_contains($normCol, 'freq') || str_contains($normCol, 'frequência')) {
                    // informativa
                } elseif ($idx >= 3 && count($currentEncontros) > 0) {
                    $offset = $idx - 3;
                    if (isset($currentEncontros[$offset])) {
                        $encontroCols[$idx] = (int)$currentEncontros[$offset]['id'];
                    }
                }
            }

            if ($colNomeIdx === -1) $colNomeIdx = 1;
            if ($colCpfIdx === -1) $colCpfIdx = 2;

            for ($rowIdx = 1; $rowIdx < count($sheetAlunos); $rowIdx++) {
                $row = $sheetAlunos[$rowIdx];
                $excelLineNum = $rowIdx + 1;

                if (empty($row) || !isset($row[$colNomeIdx])) {
                    continue;
                }

                $rawNome = (string)($row[$colNomeIdx] ?? '');
                $nomeSanitizado = preg_replace('/\s+/', ' ', trim($rawNome));
                if (empty($nomeSanitizado)) {
                    continue;
                }

                $rawCpf = (string)($row[$colCpfIdx] ?? '');
                $cpfSanitizado = trim($rawCpf);
                $cpfLimpo = ValidatorService::cleanCpf($cpfSanitizado);

                $cpfValido = true;
                if (!empty($cpfLimpo)) {
                    if (strlen($cpfLimpo) !== 11 || !ValidatorService::validateCpf($cpfLimpo)) {
                        $cpfValido = false;
                        $inconformidades[] = [
                            'linha'   => $excelLineNum,
                            'nome'    => $nomeSanitizado,
                            'cpf'     => $cpfSanitizado,
                            'motivo'  => "CPF '{$cpfSanitizado}' matematicamente inválido (dígitos verificadores inconsistentes pelo algoritmo módulo 11).",
                        ];
                    }
                }

                $alunoId = null;
                if ($colIdIdx !== -1 && isset($row[$colIdIdx]) && is_numeric($row[$colIdIdx])) {
                    $possibleId = (int)$row[$colIdIdx];
                    if (isset($alunosById[$possibleId])) {
                        $alunoId = $possibleId;
                    }
                }

                if ($alunoId === null && !empty($cpfLimpo) && isset($alunosByCpf[$cpfLimpo])) {
                    $alunoId = $alunosByCpf[$cpfLimpo];
                }

                if ($alunoId === null) {
                    $normBusca = mb_strtolower($nomeSanitizado, 'UTF-8');
                    if (isset($alunosByNome[$normBusca])) {
                        $alunoId = $alunosByNome[$normBusca];
                    }
                }

                $cpfFormatado = (!empty($cpfLimpo) && $cpfValido) ? ValidatorService::formatCpf($cpfLimpo) : $cpfSanitizado;

                if ($alunoId !== null) {
                    $matchedAlunoIds[$alunoId] = true;
                    $dbAluno = $alunosById[$alunoId];
                    $mudancasAluno = [];

                    if ($nomeSanitizado !== $dbAluno['nome_completo']) {
                        $mudancasAluno[] = [
                            'campo'    => 'Nome Completo',
                            'anterior' => $dbAluno['nome_completo'],
                            'novo'     => $nomeSanitizado,
                        ];
                    }

                    if ($cpfValido && !empty($cpfLimpo) && $cpfLimpo !== ($dbAluno['cpf_limpo'] ?? '')) {
                        $mudancasAluno[] = [
                            'campo'    => 'CPF',
                            'anterior' => $dbAluno['cpf'] ?? '—',
                            'novo'     => $cpfFormatado,
                        ];
                    }

                    $presencasAlteradas = [];
                    foreach ($encontroCols as $cIdx => $encId) {
                        if (!isset($row[$cIdx])) {
                            continue;
                        }
                        $rawVal = trim((string)$row[$cIdx]);
                        if ($rawVal === '') {
                            continue;
                        }
                        $novoStatus = $this->normalizePresenceValue($rawVal);
                        $statusAtual = $currentFreqMatrix[$alunoId][$encId] ?? null;

                        if ($statusAtual === null || $novoStatus !== $statusAtual) {
                            $presencasAlteradas[] = [
                                'encontro_id' => $encId,
                                'anterior'    => $statusAtual === 1 ? 'Presente' : ($statusAtual === 0 ? 'Falta' : 'Não registrada'),
                                'novo'        => $novoStatus === 1 ? 'Presente' : 'Falta',
                            ];
                        }
                    }

                    if (!empty($mudancasAluno) || !empty($presencasAlteradas)) {
                        $alunosAtualizar[] = [
                            'id'                  => $alunoId,
                            'nome_atual'          => $dbAluno['nome_completo'],
                            'nome_novo'           => $nomeSanitizado,
                            'cpf_atual'           => $dbAluno['cpf'],
                            'cpf_novo'            => $cpfFormatado,
                            'mudancas'            => $mudancasAluno,
                            'presencas_alteradas' => $presencasAlteradas,
                        ];
                    } else {
                        $alunosInalterados[] = [
                            'id'   => $alunoId,
                            'nome' => $dbAluno['nome_completo'],
                            'cpf'  => $dbAluno['cpf'],
                        ];
                    }
                } else {
                    if (!$cpfValido) {
                        continue;
                    }

                    $alunosNovos[] = [
                        'linha' => $excelLineNum,
                        'nome'  => $nomeSanitizado,
                        'cpf'   => $cpfFormatado,
                    ];
                }
            }

            foreach ($currentAlunos as $ca) {
                $aId = (int)$ca['id'];
                if (!isset($matchedAlunoIds[$aId])) {
                    $alunosAusentesPreservados[] = [
                        'id'   => $aId,
                        'nome' => $ca['nome_completo'],
                        'cpf'  => $ca['cpf'],
                    ];
                    $alunosInalterados[] = [
                        'id'   => $aId,
                        'nome' => $ca['nome_completo'],
                        'cpf'  => $ca['cpf'],
                    ];
                }
            }
        }

        return [
            'turma_id' => $turmaId,
            'resumo'   => [
                'total_novos_alunos'          => count($alunosNovos),
                'total_atualizar_alunos'      => count($alunosAtualizar),
                'total_inalterados_alunos'    => count($alunosInalterados),
                'total_ausentes_preservados'  => count($alunosAusentesPreservados),
                'total_encontros_alterados'   => count($encontrosAlterados),
                'total_encontros_inalterados' => count($encontrosInalterados),
                'total_reagendamentos_seguros'=> $totalReagendamentosSeguros,
                'total_inconformidades'       => count($inconformidades),
                'aba_alunos_vazia'            => $abaAlunosVazia,
                'preservacao_alunos_ativa'    => $preservacaoAlunosAtiva,
                'preservacao_aviso'           => $preservacaoAviso,
            ],
            'alunos' => [
                'novos'                => $alunosNovos,
                'atualizar'            => $alunosAtualizar,
                'inalterados'          => $alunosInalterados,
                'ausentes_preservados' => $alunosAusentesPreservados,
            ],
            'encontros' => [
                'alterados'   => $encontrosAlterados,
                'inalterados' => $encontrosInalterados,
            ],
            'turma_metadados' => [
                'alterados'   => $metaAlterados,
                'inalterados' => $metaInalterados,
            ],
            'inconformidades' => $inconformidades,
        ];
    }

    /**
     * Importa e sincroniza a planilha Excel de volta para a aplicação.
     * Aplica sanitização estrita com trim() em nomes e CPFs.
     * Garante idempotência sem duplicação de alunos ou registros de frequência.
     * Aplica a Regra Estrita de Preservação para seções vazias.
     * Aplica Reagendamento Seguro preservando histórico de chamadas e planos.
     * Executa todas as atualizações sob transação atômica única no banco.
     */
    public function importTurmaSpreadsheet(int $turmaId, string $filePathOrContent): array
    {
        // 1. Extrai planilhas do arquivo .xlsx
        $sheetsData = $this->parseXlsx($filePathOrContent);

        // Identifica as abas pelo nome normalizado
        $sheetAlunos = null;
        $sheetPlanos = null;
        $sheetDados = null;

        foreach ($sheetsData as $sheetName => $rows) {
            $normName = mb_strtolower(trim($sheetName), 'UTF-8');
            if (str_contains($normName, 'aluno') || str_contains($normName, 'chamada')) {
                $sheetAlunos = $rows;
            } elseif (str_contains($normName, 'plano') || str_contains($normName, 'diario') || str_contains($normName, 'diário')) {
                $sheetPlanos = $rows;
            } elseif (str_contains($normName, 'dado') || str_contains($normName, 'turma')) {
                $sheetDados = $rows;
            }
        }

        if (!$sheetAlunos) {
            $keys = array_keys($sheetsData);
            if (isset($keys[0])) $sheetAlunos = $sheetsData[$keys[0]];
            if (isset($keys[1])) $sheetPlanos = $sheetsData[$keys[1]];
            if (isset($keys[2])) $sheetDados = $sheetsData[$keys[2]];
        }

        $isAlunosEmpty = true;
        if (!empty($sheetAlunos) && count($sheetAlunos) > 1) {
            $headerTmp = $sheetAlunos[0];
            $colNomeTmp = $this->findColumnIndex(array_map('strval', $headerTmp), ['nome']);
            if ($colNomeTmp === -1) $colNomeTmp = 1;
            for ($k = 1; $k < count($sheetAlunos); $k++) {
                $row = $sheetAlunos[$k];
                if (!empty($row) && isset($row[$colNomeTmp])) {
                    $nomeVal = preg_replace('/\s+/', ' ', trim((string)$row[$colNomeTmp]));
                    if ($nomeVal !== '') {
                        $isAlunosEmpty = false;
                        break;
                    }
                }
            }
        }

        $inconformidades = [];
        $alunosAtualizados = 0;
        $alunosInseridos = 0;
        $presencasGravadas = 0;
        $encontrosAtualizados = 0;

        $this->pdo->beginTransaction();

        try {
            // Verifica existência da turma
            $stmtCheck = $this->pdo->prepare("SELECT id FROM turmas WHERE id = ?");
            $stmtCheck->execute([$turmaId]);
            if (!$stmtCheck->fetch()) {
                throw new InvalidArgumentException("Turma ID {$turmaId} não encontrada no banco de dados.");
            }

            // -------------------------------------------------------------
            // PASSO A: Atualiza Metadados da Turma (Aba 3)
            // -------------------------------------------------------------
            $dataInicioDefinidaAba3 = false;
            $dataFimDefinidaAba3 = false;
            if (!empty($sheetDados)) {
                $metaMap = [];
                foreach ($sheetDados as $r) {
                    if (count($r) >= 2) {
                        $key = mb_strtolower(trim((string)$r[0]), 'UTF-8');
                        $val = trim((string)$r[1]);
                        $metaMap[$key] = $val;
                    }
                }

                $updates = [];
                $params = [];

                if (isset($metaMap['nome do curso']) && $metaMap['nome do curso'] !== '') {
                    $updates[] = "curso_nome = ?";
                    $params[] = $metaMap['nome do curso'];
                }
                if (isset($metaMap['cliente']) && $metaMap['cliente'] !== '') {
                    $updates[] = "cliente_nome = ?";
                    $params[] = $metaMap['cliente'];
                }
                if (isset($metaMap['instrutor']) && $metaMap['instrutor'] !== '') {
                    $updates[] = "instrutor = ?";
                    $params[] = $metaMap['instrutor'];
                }
                if (isset($metaMap['carga horária (h)']) && is_numeric($metaMap['carga horária (h)'])) {
                    $updates[] = "carga_horaria = ?";
                    $params[] = (int)$metaMap['carga horária (h)'];
                }
                if (isset($metaMap['ementa oficial']) && $metaMap['ementa oficial'] !== '') {
                    $updates[] = "ementa = ?";
                    $params[] = $metaMap['ementa oficial'];
                }

                $dataInicioDefinidaAba3 = false;
                $dataInicio = $metaMap['data de início'] ?? $metaMap['data de inicio'] ?? null;
                if ($dataInicio !== null && $dataInicio !== '') {
                    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $dataInicio, $m)) {
                        $dataInicio = sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
                    }
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataInicio)) {
                        $updates[] = "data_inicio = ?";
                        $params[] = $dataInicio;
                        $dataInicioDefinidaAba3 = true;
                    }
                }

                $dataFimDefinidaAba3 = false;
                $dataFim = $metaMap['data de conclusão'] ?? $metaMap['data de conclusao'] ?? null;
                if ($dataFim !== null && $dataFim !== '') {
                    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $dataFim, $m)) {
                        $dataFim = sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
                    }
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataFim)) {
                        $updates[] = "data_conclusao = ?";
                        $params[] = $dataFim;
                        $dataFimDefinidaAba3 = true;
                    }
                }

                if (!empty($updates)) {
                    $updates[] = "updated_at = CURRENT_TIMESTAMP";
                    $params[] = $turmaId;
                    $sqlTurma = "UPDATE turmas SET " . implode(', ', $updates) . " WHERE id = ?";
                    $stmtUpTurma = $this->pdo->prepare($sqlTurma);
                    $stmtUpTurma->execute($params);
                }
            }

            // -------------------------------------------------------------
            // PASSO B: Atualiza Planos e Diário de Aulas (Aba 2)
            // -------------------------------------------------------------
            if (!empty($sheetPlanos) && count($sheetPlanos) > 1) {
                $headerP = array_map(fn($h) => mb_strtolower(trim((string)$h), 'UTF-8'), $sheetPlanos[0]);
                
                $colEncIdx = $this->findColumnIndex($headerP, ['encontro', 'nº encontro', 'nº']);
                $colPrevIdx = $this->findColumnIndex($headerP, ['conteúdo previsto', 'conteudo previsto', 'previsto']);
                $colMinIdx = $this->findColumnIndex($headerP, ['conteúdo ministrado', 'conteudo ministrado', 'ministrado']);
                $colDataIdx = $this->findColumnIndex($headerP, ['data', 'data da aula']);
                $colHoraIniIdx = $this->findColumnIndex($headerP, ['horário início', 'horario inicio', 'horário inicio', 'horario início']);
                $colHoraFimIdx = $this->findColumnIndex($headerP, ['horário fim', 'horario fim', 'horário término', 'horario termino']);
                $colIntervaloIdx = $this->findColumnIndex($headerP, ['intervalo (min)', 'intervalo min', 'intervalo minutos', 'intervalo']);

                for ($i = 1; $i < count($sheetPlanos); $i++) {
                    $row = $sheetPlanos[$i];
                    if (empty($row) || !isset($row[$colEncIdx])) {
                        continue;
                    }

                    $numEnc = (int)preg_replace('/\D/', '', (string)$row[$colEncIdx]);
                    if ($numEnc <= 0) {
                        continue;
                    }

                    $conteudoPrevisto = ($colPrevIdx !== -1 && isset($row[$colPrevIdx])) ? trim((string)$row[$colPrevIdx]) : null;
                    $conteudoMinistrado = ($colMinIdx !== -1 && isset($row[$colMinIdx])) ? trim((string)$row[$colMinIdx]) : null;
                    $dataEnc = ($colDataIdx !== -1 && isset($row[$colDataIdx])) ? trim((string)$row[$colDataIdx]) : null;
                    $horaIni = ($colHoraIniIdx !== -1 && isset($row[$colHoraIniIdx])) ? trim((string)$row[$colHoraIniIdx]) : null;
                    $horaFim = ($colHoraFimIdx !== -1 && isset($row[$colHoraFimIdx])) ? trim((string)$row[$colHoraFimIdx]) : null;
                    $intervaloMinutos = ($colIntervaloIdx !== -1 && isset($row[$colIntervaloIdx]) && $row[$colIntervaloIdx] !== '')
                        ? max(0, (int)$row[$colIntervaloIdx])
                        : null;

                    if ($dataEnc && preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $dataEnc, $m)) {
                        $dataEnc = sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
                    }

                    $stmtFindEnc = $this->pdo->prepare("SELECT id, horario_inicio, horario_fim FROM encontros WHERE turma_id = ? AND numero_encontro = ?");
                    $stmtFindEnc->execute([$turmaId, $numEnc]);
                    $encRow = $stmtFindEnc->fetch(PDO::FETCH_ASSOC);

                    if ($encRow) {
                        $encId = (int)$encRow['id'];
                        $encUpdates = [];
                        $encParams = [];

                        if ($conteudoPrevisto !== null) {
                            $encUpdates[] = "conteudo_previsto = ?";
                            $encParams[] = $conteudoPrevisto;
                        }
                        if ($conteudoMinistrado !== null && $conteudoMinistrado !== '') {
                            $encUpdates[] = "conteudo_ministrado = ?";
                            $encParams[] = $conteudoMinistrado;
                        }
                        if ($dataEnc !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataEnc)) {
                            $encUpdates[] = "data_encontro = ?";
                            $encParams[] = $dataEnc;
                        }
                        if ($horaIni !== null && preg_match('/^(\d{1,2}):(\d{2})/', $horaIni, $m)) {
                            $encUpdates[] = "horario_inicio = ?";
                            $encParams[] = sprintf('%02d:%02d:00', (int)$m[1], (int)$m[2]);
                        }
                        if ($horaFim !== null && preg_match('/^(\d{1,2}):(\d{2})/', $horaFim, $m)) {
                            $encUpdates[] = "horario_fim = ?";
                            $encParams[] = sprintf('%02d:%02d:00', (int)$m[1], (int)$m[2]);
                        }
                        if ($intervaloMinutos !== null) {
                            $encUpdates[] = "intervalo_minutos = ?";
                            $encParams[] = $intervaloMinutos;
                        }
                        if ($horaIni !== null || $horaFim !== null) {
                            $hIniEfetivo = ($horaIni && preg_match('/^(\d{1,2}):(\d{2})/', $horaIni, $m)) ? sprintf('%02d:%02d:00', (int)$m[1], (int)$m[2]) : ($encRow['horario_inicio'] ?? '14:00:00');
                            $hFimEfetivo = ($horaFim && preg_match('/^(\d{1,2}):(\d{2})/', $horaFim, $m)) ? sprintf('%02d:%02d:00', (int)$m[1], (int)$m[2]) : ($encRow['horario_fim'] ?? '18:00:00');
                            $encUpdates[] = "turno = ?";
                            $encParams[] = TurmaService::inferTurnoFromHorarios($hIniEfetivo, $hFimEfetivo);
                        }

                        if (!empty($encUpdates)) {
                            $encUpdates[] = "updated_at = CURRENT_TIMESTAMP";
                            $encParams[] = $encId;
                            $stmtUpEnc = $this->pdo->prepare("UPDATE encontros SET " . implode(', ', $encUpdates) . " WHERE id = ?");
                            $stmtUpEnc->execute($encParams);
                            $encontrosAtualizados++;
                        }
                    }
                }

                if ($encontrosAtualizados > 0 && (!$dataInicioDefinidaAba3 && !$dataFimDefinidaAba3)) {
                    $turmaService = new TurmaService($this->pdo);
                    $turmaService->recalculateTurmaDates($turmaId);
                }
            }

            // -------------------------------------------------------------
            // PASSO C: Atualiza Alunos e Presenças (Aba 1)
            // -------------------------------------------------------------
            if (!$isAlunosEmpty && !empty($sheetAlunos)) {
                $stmtAllEnc = $this->pdo->prepare("
                    SELECT id, numero_encontro, data_encontro 
                    FROM encontros 
                    WHERE turma_id = ? 
                    ORDER BY numero_encontro ASC
                ");
                $stmtAllEnc->execute([$turmaId]);
                $turmaEncontros = $stmtAllEnc->fetchAll(PDO::FETCH_ASSOC);

                $encByNumero = [];
                foreach ($turmaEncontros as $e) {
                    $encByNumero[(int)$e['numero_encontro']] = (int)$e['id'];
                }

                $headerA = $sheetAlunos[0];
                $colIdIdx = -1;
                $colNomeIdx = -1;
                $colCpfIdx = -1;
                $encontroCols = [];

                foreach ($headerA as $idx => $colHeader) {
                    $normCol = mb_strtolower(trim((string)$colHeader), 'UTF-8');
                    if ($normCol === 'id' || str_contains($normCol, 'id aluno') || str_contains($normCol, 'id banco')) {
                        $colIdIdx = $idx;
                    } elseif (str_contains($normCol, 'nome')) {
                        $colNomeIdx = $idx;
                    } elseif (str_contains($normCol, 'cpf')) {
                        $colCpfIdx = $idx;
                    } elseif (preg_match('/(?:encontro|aula)\s*#?(\d+)/i', (string)$colHeader, $m)) {
                        $num = (int)$m[1];
                        if (isset($encByNumero[$num])) {
                            $encontroCols[$idx] = $encByNumero[$num];
                        }
                    } elseif (str_contains($normCol, 'freq') || str_contains($normCol, 'frequência')) {
                        // Ignora coluna de percentual de frequência informativa
                    } elseif ($idx >= 3 && count($turmaEncontros) > 0) {
                        $offset = $idx - 3;
                        if (isset($turmaEncontros[$offset])) {
                            $encontroCols[$idx] = (int)$turmaEncontros[$offset]['id'];
                        }
                    }
                }

                if ($colNomeIdx === -1) {
                    $colNomeIdx = 1;
                }
                if ($colCpfIdx === -1) {
                    $colCpfIdx = 2;
                }

                $stmtCurrentAlunos = $this->pdo->prepare("
                    SELECT id, nome_completo, cpf, cpf_limpo 
                    FROM alunos 
                    WHERE turma_id = ?
                ");
                $stmtCurrentAlunos->execute([$turmaId]);
                $currentAlunos = $stmtCurrentAlunos->fetchAll(PDO::FETCH_ASSOC);

                $alunosById = [];
                $alunosByCpf = [];
                $alunosByNome = [];
                foreach ($currentAlunos as $ca) {
                    $alunosById[(int)$ca['id']] = (int)$ca['id'];
                    if (!empty($ca['cpf_limpo'])) {
                        $alunosByCpf[$ca['cpf_limpo']] = (int)$ca['id'];
                    }
                    $normNome = mb_strtolower(trim((string)$ca['nome_completo']), 'UTF-8');
                    $alunosByNome[$normNome] = (int)$ca['id'];
                }

                for ($rowIdx = 1; $rowIdx < count($sheetAlunos); $rowIdx++) {
                    $row = $sheetAlunos[$rowIdx];
                    $excelLineNum = $rowIdx + 1;

                    if (empty($row) || !isset($row[$colNomeIdx])) {
                        continue;
                    }

                    // Sanitização estrita (.strip() / trim()) em Nome e CPF
                    $rawNome = (string)($row[$colNomeIdx] ?? '');
                    $nomeSanitizado = preg_replace('/\s+/', ' ', trim($rawNome));

                    if (empty($nomeSanitizado)) {
                        continue;
                    }

                    $rawCpf = (string)($row[$colCpfIdx] ?? '');
                    $cpfSanitizado = trim($rawCpf);
                    $cpfLimpo = ValidatorService::cleanCpf($cpfSanitizado);

                    // Validação matemática do CPF
                    $cpfValido = true;
                    if (!empty($cpfLimpo)) {
                        if (strlen($cpfLimpo) !== 11 || !ValidatorService::validateCpf($cpfLimpo)) {
                            $cpfValido = false;
                            $inconformidades[] = [
                                'linha'   => $excelLineNum,
                                'nome'    => $nomeSanitizado,
                                'cpf'     => $cpfSanitizado,
                                'motivo'  => "CPF '{$cpfSanitizado}' matematicamente inválido (dígitos verificadores inconsistentes pelo algoritmo módulo 11).",
                            ];
                        }
                    }

                    // Correspondência de aluno existente para evitar duplicidade
                    $alunoId = null;
                    if ($colIdIdx !== -1 && isset($row[$colIdIdx]) && is_numeric($row[$colIdIdx])) {
                        $posId = (int)$row[$colIdIdx];
                        if (isset($alunosById[$posId])) {
                            $alunoId = $posId;
                        }
                    }
                    if ($alunoId === null && !empty($cpfLimpo) && isset($alunosByCpf[$cpfLimpo])) {
                        $alunoId = $alunosByCpf[$cpfLimpo];
                    }
                    if ($alunoId === null) {
                        $normBusca = mb_strtolower($nomeSanitizado, 'UTF-8');
                        if (isset($alunosByNome[$normBusca])) {
                            $alunoId = $alunosByNome[$normBusca];
                        }
                    }

                $cpfFormatado = (!empty($cpfLimpo) && $cpfValido) ? ValidatorService::formatCpf($cpfLimpo) : null;
                $cpfMascarado = (!empty($cpfLimpo) && $cpfValido) ? ValidatorService::maskCpf($cpfLimpo) : '—';
                $cpfLimpoSalvar = $cpfValido ? $cpfLimpo : null;

                if ($alunoId !== null) {
                    // Atualiza aluno existente
                    if ($cpfValido && !empty($cpfLimpoSalvar)) {
                        $stmtUpAluno = $this->pdo->prepare("
                            UPDATE alunos 
                            SET nome_completo = ?, 
                                cpf = ?, 
                                cpf_limpo = ?, 
                                cpf_mascarado = ?,
                                updated_at = CURRENT_TIMESTAMP
                            WHERE id = ?
                        ");
                        $stmtUpAluno->execute([$nomeSanitizado, $cpfFormatado, $cpfLimpoSalvar, $cpfMascarado, $alunoId]);
                    } else {
                        $stmtUpAluno = $this->pdo->prepare("
                            UPDATE alunos 
                            SET nome_completo = ?, 
                                updated_at = CURRENT_TIMESTAMP
                            WHERE id = ?
                        ");
                        $stmtUpAluno->execute([$nomeSanitizado, $alunoId]);
                    }
                    $alunosAtualizados++;
                } else {
                    if (!$cpfValido) {
                        // Não insere novo aluno com dados cadastrais inválidos/corrompidos
                        continue;
                    }

                    // Insere novo aluno
                    $stmtInsAluno = $this->pdo->prepare("
                        INSERT INTO alunos (turma_id, nome_completo, cpf, cpf_limpo, cpf_mascarado)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmtInsAluno->execute([$turmaId, $nomeSanitizado, $cpfFormatado, $cpfLimpoSalvar, $cpfMascarado]);
                    $alunoId = (int)$this->pdo->lastInsertId();
                    $alunosInseridos++;

                    if (!empty($cpfLimpoSalvar)) {
                        $alunosByCpf[$cpfLimpoSalvar] = $alunoId;
                    }
                    $alunosByNome[mb_strtolower($nomeSanitizado, 'UTF-8')] = $alunoId;
                }

                // Sincroniza presenças deste aluno
                foreach ($encontroCols as $cIdx => $encId) {
                    if (!isset($row[$cIdx])) {
                        continue;
                    }

                    $rawVal = trim((string)$row[$cIdx]);
                    if ($rawVal === '') {
                        // Célula vazia indica encontro futuro/pendente ou chamada não realizada
                        continue;
                    }

                    $presente = $this->normalizePresenceValue($rawVal);

                    // Idempotência na tabela frequencias
                    $stmtCheckFreq = $this->pdo->prepare("
                        SELECT id FROM frequencias WHERE encontro_id = ? AND aluno_id = ?
                    ");
                    $stmtCheckFreq->execute([$encId, $alunoId]);
                    $freqExist = $stmtCheckFreq->fetch(PDO::FETCH_ASSOC);

                    if ($freqExist) {
                        $stmtUpFreq = $this->pdo->prepare("
                            UPDATE frequencias 
                            SET presente = ?, updated_at = CURRENT_TIMESTAMP 
                            WHERE id = ?
                        ");
                        $stmtUpFreq->execute([$presente, (int)$freqExist['id']]);
                    } else {
                        $stmtInsFreq = $this->pdo->prepare("
                            INSERT INTO frequencias (encontro_id, aluno_id, presente)
                            VALUES (?, ?, ?)
                        ");
                        $stmtInsFreq->execute([$encId, $alunoId, $presente]);
                    }
                    $presencasGravadas++;
                }
            }
        }

            $this->pdo->commit();

            return [
                'success'               => true,
                'turma_id'              => $turmaId,
                'alunos_atualizados'    => $alunosAtualizados,
                'alunos_inseridos'      => $alunosInseridos,
                'presencas_atualizadas' => $presencasGravadas,
                'encontros_atualizados' => $encontrosAtualizados,
                'inconformidades'       => $inconformidades,
                'total_inconformidades' => count($inconformidades),
                'preservacao_ativa'     => $isAlunosEmpty,
                'mensagem'              => 'Sincronização bidirecional concluída com sucesso!'
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Normaliza valores variados de presença do Excel para 1 ou 0.
     */
    private function normalizePresenceValue(string $val): int
    {
        $v = mb_strtoupper(trim($val), 'UTF-8');
        if ($v === '1' || $v === 'P' || $v === 'PRESENTE' || $v === 'SIM' || $v === 'TRUE' || $v === 'V') {
            return 1;
        }
        if ($v === '0' || $v === 'F' || $v === 'FALTA' || $v === 'NÃO' || $v === 'NAO' || $v === 'FALSE') {
            return 0;
        }
        return (is_numeric($v) && ((int)$v > 0)) ? 1 : 0;
    }

    /**
     * Localiza índice da coluna baseado em variações de nomes possíveis.
     */
    private function findColumnIndex(array $headers, array $candidates): int
    {
        foreach ($headers as $idx => $h) {
            foreach ($candidates as $cand) {
                if (str_contains($h, $cand)) {
                    return $idx;
                }
            }
        }
        return -1;
    }

    /**
     * Constrói o arquivo OpenXML (.xlsx) em memória com suporte a múltiplas abas.
     */
    private function buildXlsxArchive(array $sheets): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'ff_xlsx_');
        $zip = new ZipArchive();

        if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Não foi possível criar o arquivo temporário .xlsx no sistema.");
        }

        // 1. [Content_Types].xml
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $contentTypes .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' . "\n";
        $contentTypes .= '  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' . "\n";
        $contentTypes .= '  <Default Extension="xml" ContentType="application/xml"/>' . "\n";
        $contentTypes .= '  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' . "\n";
        $contentTypes .= '  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' . "\n";
        
        $sheetIndex = 1;
        foreach ($sheets as $name => $rows) {
            $contentTypes .= "  <Override PartName=\"/xl/worksheets/sheet{$sheetIndex}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>\n";
            $sheetIndex++;
        }
        $contentTypes .= '</Types>';
        $zip->addFromString('[Content_Types].xml', $contentTypes);

        // 2. _rels/.rels
        $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $rootRels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n";
        $rootRels .= '  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' . "\n";
        $rootRels .= '</Relationships>';
        $zip->addFromString('_rels/.rels', $rootRels);

        // 3. xl/_rels/workbook.xml.rels
        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $wbRels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n";
        $sheetIndex = 1;
        foreach ($sheets as $name => $rows) {
            $wbRels .= "  <Relationship Id=\"rId{$sheetIndex}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet{$sheetIndex}.xml\"/>\n";
            $sheetIndex++;
        }
        $stylesRId = "rId{$sheetIndex}";
        $wbRels .= "  <Relationship Id=\"{$stylesRId}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles\" Target=\"styles.xml\"/>\n";
        $wbRels .= '</Relationships>';
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);

        // 4. xl/workbook.xml
        $wbXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $wbXml .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' . "\n";
        $wbXml .= '  <sheets>' . "\n";
        $sheetIndex = 1;
        foreach ($sheets as $name => $rows) {
            $escapedName = htmlspecialchars($name, ENT_XML1, 'UTF-8');
            $wbXml .= "    <sheet name=\"{$escapedName}\" sheetId=\"{$sheetIndex}\" r:id=\"rId{$sheetIndex}\"/>\n";
            $sheetIndex++;
        }
        $wbXml .= '  </sheets>' . "\n";
        $wbXml .= '</workbook>';
        $zip->addFromString('xl/workbook.xml', $wbXml);

        // 5. xl/styles.xml
        $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $stylesXml .= '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . "\n";
        $stylesXml .= '  <fonts count="2">' . "\n";
        $stylesXml .= '    <font><name val="Calibri"/><sz val="11"/></font>' . "\n";
        $stylesXml .= '    <font><b/><name val="Calibri"/><sz val="11"/></font>' . "\n";
        $stylesXml .= '  </fonts>' . "\n";
        $stylesXml .= '  <fills count="2">' . "\n";
        $stylesXml .= '    <fill><patternFill patternType="none"/></fill>' . "\n";
        $stylesXml .= '    <fill><patternFill patternType="gray125"/></fill>' . "\n";
        $stylesXml .= '  </fills>' . "\n";
        $stylesXml .= '  <borders count="1">' . "\n";
        $stylesXml .= '    <border><left/><right/><top/><bottom/><diagonal/></border>' . "\n";
        $stylesXml .= '  </borders>' . "\n";
        $stylesXml .= '  <cellStyleXfs count="1">' . "\n";
        $stylesXml .= '    <xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>' . "\n";
        $stylesXml .= '  </cellStyleXfs>' . "\n";
        $stylesXml .= '  <cellXfs count="2">' . "\n";
        $stylesXml .= '    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' . "\n";
        $stylesXml .= '    <xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>' . "\n";
        $stylesXml .= '  </cellXfs>' . "\n";
        $stylesXml .= '</styleSheet>';
        $zip->addFromString('xl/styles.xml', $stylesXml);

        // 6. Worksheets (sheet1.xml, sheet2.xml, sheet3.xml)
        $sheetIndex = 1;
        foreach ($sheets as $name => $rows) {
            $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
            $sheetXml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . "\n";
            $sheetXml .= '  <sheetData>' . "\n";

            $rowNum = 1;
            foreach ($rows as $row) {
                $sheetXml .= "    <row r=\"{$rowNum}\">\n";
                $colIndex = 1;
                foreach ($row as $cellVal) {
                    $cellRef = $this->colIndexToLetter($colIndex) . $rowNum;
                    $styleAttr = ($rowNum === 1) ? ' s="1"' : '';

                    if (is_int($cellVal) || (is_numeric($cellVal) && !str_starts_with((string)$cellVal, '0') && !str_contains((string)$cellVal, '%'))) {
                        $sheetXml .= "      <c r=\"{$cellRef}\"{$styleAttr}><v>{$cellVal}</v></c>\n";
                    } else {
                        $strVal = htmlspecialchars((string)$cellVal, ENT_XML1, 'UTF-8');
                        $sheetXml .= "      <c r=\"{$cellRef}\"{$styleAttr} t=\"inlineStr\"><is><t>{$strVal}</t></is></c>\n";
                    }
                    $colIndex++;
                }
                $sheetXml .= "    </row>\n";
                $rowNum++;
            }

            $sheetXml .= '  </sheetData>' . "\n";
            $sheetXml .= '</worksheet>';

            $zip->addFromString("xl/worksheets/sheet{$sheetIndex}.xml", $sheetXml);
            $sheetIndex++;
        }

        $zip->close();

        $content = file_get_contents($tempFile);
        @unlink($tempFile);

        if ($content === false) {
            throw new RuntimeException("Falha ao ler os bytes do arquivo .xlsx gerado.");
        }

        return $content;
    }

    /**
     * Faz o parsing de um arquivo ou conteúdo .xlsx e extrai as abas e matriz de células.
     */
    public function parseXlsx(string $filePathOrContent): array
    {
        $tempFile = null;
        if (strlen($filePathOrContent) < 1024 && file_exists($filePathOrContent)) {
            $archivePath = $filePathOrContent;
        } else {
            $tempFile = tempnam(sys_get_temp_dir(), 'ff_imp_');
            file_put_contents($tempFile, $filePathOrContent);
            $archivePath = $tempFile;
        }

        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) {
            if ($tempFile) @unlink($tempFile);
            throw new InvalidArgumentException("O arquivo enviado não é um arquivo .xlsx válido ou está corrompido.");
        }

        // 1. Lê sharedStrings.xml (se existir)
        $sharedStrings = [];
        $sharedXmlContent = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXmlContent !== false) {
            $sxml = simplexml_load_string($sharedXmlContent);
            if ($sxml && isset($sxml->si)) {
                foreach ($sxml->si as $si) {
                    if (isset($si->t)) {
                        $sharedStrings[] = (string)$si->t;
                    } elseif (isset($si->r)) {
                        $tParts = '';
                        foreach ($si->r as $r) {
                            $tParts .= (string)($r->t ?? '');
                        }
                        $sharedStrings[] = $tParts;
                    } else {
                        $sharedStrings[] = '';
                    }
                }
            }
        }

        // 2. Lê workbook.xml para mapear sheetId / r:id para nomes amigáveis
        $wbXmlContent = $zip->getFromName('xl/workbook.xml');
        $wbRelsContent = $zip->getFromName('xl/_rels/workbook.xml.rels');
        
        $sheetTargets = [];
        if ($wbXmlContent !== false) {
            $wbXml = simplexml_load_string($wbXmlContent);
            $relsMap = [];

            if ($wbRelsContent !== false) {
                $wbRels = simplexml_load_string($wbRelsContent);
                if ($wbRels && isset($wbRels->Relationship)) {
                    foreach ($wbRels->Relationship as $rel) {
                        $relsMap[(string)$rel['Id']] = (string)$rel['Target'];
                    }
                }
            }

            if ($wbXml && isset($wbXml->sheets->sheet)) {
                foreach ($wbXml->sheets->sheet as $sheet) {
                    $name = (string)$sheet['name'];
                    $rId = (string)$sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                    $target = $relsMap[$rId] ?? '';
                    if ($target) {
                        $cleanTarget = ltrim(str_replace('\\', '/', $target), '/');
                        if (!str_starts_with($cleanTarget, 'xl/')) {
                            $cleanTarget = 'xl/' . $cleanTarget;
                        }
                        $sheetTargets[$name] = $cleanTarget;
                    }
                }
            }
        }

        if (empty($sheetTargets)) {
            for ($i = 1; $i <= 5; $i++) {
                $path = "xl/worksheets/sheet{$i}.xml";
                if ($zip->locateName($path) !== false) {
                    $sheetTargets["Sheet{$i}"] = $path;
                }
            }
        }

        $resultSheets = [];

        foreach ($sheetTargets as $sheetName => $sheetXmlPath) {
            $sheetXmlContent = $zip->getFromName($sheetXmlPath);
            if ($sheetXmlContent === false) {
                continue;
            }

            $sheetXml = simplexml_load_string($sheetXmlContent);
            if (!$sheetXml || !isset($sheetXml->sheetData->row)) {
                $resultSheets[$sheetName] = [];
                continue;
            }

            $sheetRows = [];
            foreach ($sheetXml->sheetData->row as $row) {
                $rowNum = (int)$row['r'];
                $rowData = [];

                foreach ($row->c as $cell) {
                    $cellRef = (string)$cell['r'];
                    $colLetters = preg_replace('/\d/', '', $cellRef);
                    $colIdx = $this->letterToColIndex($colLetters);

                    $type = (string)($cell['t'] ?? '');
                    $val = '';

                    if ($type === 's') {
                        $sIndex = (int)$cell->v;
                        $val = $sharedStrings[$sIndex] ?? '';
                    } elseif ($type === 'inlineStr') {
                        $val = (string)($cell->is->t ?? '');
                    } elseif ($type === 'b') {
                        $val = ((string)$cell->v === '1') ? '1' : '0';
                    } else {
                        $val = (string)($cell->v ?? '');
                    }

                    $rowData[$colIdx - 1] = $val;
                }

                if (!empty($rowData)) {
                    $maxIdx = max(array_keys($rowData));
                    $normalizedRow = [];
                    for ($k = 0; $k <= $maxIdx; $k++) {
                        $normalizedRow[$k] = $rowData[$k] ?? '';
                    }
                    $sheetRows[] = $normalizedRow;
                }
            }

            $resultSheets[$sheetName] = $sheetRows;
        }

        $zip->close();
        if ($tempFile) @unlink($tempFile);

        return $resultSheets;
    }

    /**
     * Converte índice 1-based para letras de coluna do Excel (1 -> A, 28 -> AB).
     */
    private function colIndexToLetter(int $col): string
    {
        $letter = '';
        while ($col > 0) {
            $mod = ($col - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $col = (int)(($col - $mod) / 26);
        }
        return $letter;
    }

    /**
     * Converte letras de coluna do Excel para índice 1-based (A -> 1, AB -> 28).
     */
    private function letterToColIndex(string $letter): int
    {
        $letter = strtoupper($letter);
        $col = 0;
        $len = strlen($letter);
        for ($i = 0; $i < $len; $i++) {
            $col = $col * 26 + (ord($letter[$i]) - 64);
        }
        return $col;
    }
}
