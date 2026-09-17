<?php
/**
 * Suíte de Testes Automatizados — Ticket 08: Gestão Avançada de Capacidade
 * Plataforma Integrada de Gestão Pedagógica, Diário e Certificados Futuro Fácil
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Config/Database.php';
require_once __DIR__ . '/../src/Services/CalendarService.php';
require_once __DIR__ . '/../src/Services/AttendanceService.php';
require_once __DIR__ . '/../src/Services/CertificateService.php';
require_once __DIR__ . '/../src/Services/AuthService.php';

use FuturoFacil\Config\Database;
use FuturoFacil\Services\CalendarService;
use FuturoFacil\Services\AttendanceService;
use FuturoFacil\Services\CertificateService;

$verde = "\033[32m";
$vermelho = "\033[31m";
$amarelo = "\033[33m";
$azul = "\033[36m";
$reset = "\033[0m";

echo "{$azul}======================================================================\n";
echo " Executando Suíte de Testes — Ticket 08: Gestão Avançada de Capacidade\n";
echo "======================================================================{$reset}\n\n";

$testDbPath = __DIR__ . '/test_temp_ticket_08.db';
if (file_exists($testDbPath)) {
    unlink($testDbPath);
}

$passedCount = 0;
$totalCount = 0;

function assertTest(bool $condition, string $description, ?string $detail = null): void
{
    global $verde, $vermelho, $reset, $passedCount, $totalCount;
    $totalCount++;
    if ($condition) {
        $passedCount++;
        echo "  {$verde}✓ [OK]{$reset} {$description}\n";
    } else {
        echo "  {$vermelho}✗ [FALHA]{$reset} {$description}";
        if ($detail) {
            echo " ({$detail})";
        }
        echo "\n";
    }
}

try {
    $pdo = new PDO("sqlite:{$testDbPath}", null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $schemaSql = file_get_contents(__DIR__ . '/../database/schema_sqlite.sql');
    $pdo->exec($schemaSql);

    Database::setConfig([
        'driver'   => 'sqlite',
        'database' => $testDbPath,
    ]);

    $calendarService = new CalendarService($pdo);
    $attendanceService = new AttendanceService($pdo);
    $certificateService = new CertificateService($pdo, $attendanceService);

    // =========================================================================
    // SEÇÃO 1: Schema e Estrutura de Bloqueios e Feriados
    // =========================================================================
    echo "{$amarelo}-> 1. Testando Schema e Tabela de Bloqueios de Agenda...{$reset}\n";

    $stmtTable = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='bloqueios_agenda'");
    $tableExists = (bool)$stmtTable->fetchColumn();
    assertTest($tableExists, "Tabela 'bloqueios_agenda' criada com sucesso no schema");

    // =========================================================================
    // SEÇÃO 2: Feriados Nacionais e Bloqueios Pessoais
    // =========================================================================
    echo "{$amarelo}-> 2. Testando Feriados Nacionais e Bloqueios Pessoais...{$reset}\n";

    // 2.1 Carga automática de feriados oficiais de 2026
    $totalFeriados2026 = $calendarService->seedFeriadosNacionais(2026);
    assertTest($totalFeriados2026 >= 11, "seedFeriadosNacionais cadastrou feriados oficiais de 2026 (total: {$totalFeriados2026})");

    // Idempotência do seed
    $totalReexec = $calendarService->seedFeriadosNacionais(2026);
    assertTest($totalReexec === 0, "seedFeriadosNacionais é idempotente e não duplica feriados existentes");

    // Feriados específicos de 2026
    $bloqueiosAbril = $calendarService->getBloqueiosForDateRange('2026-04-01', '2026-04-30');
    assertTest(isset($bloqueiosAbril['2026-04-21']), "Feriado de Tiradentes (21/04/2026) identificado no calendário");
    assertTest(isset($bloqueiosAbril['2026-04-03']), "Sexta-feira Santa (03/04/2026) calculada dinamicamente a partir da Páscoa");

    // Feriado de Consciência Negra (20/11)
    $bloqueiosNov = $calendarService->getBloqueiosForDateRange('2026-11-01', '2026-11-30');
    assertTest(isset($bloqueiosNov['2026-11-20']), "Feriado Nacional de Consciência Negra (20/11/2026) presente");

    // Bloqueio Pessoal do Instrutor
    $bloqueioId = $calendarService->addBloqueio('2026-07-15', 'Férias do Instrutor', 'bloqueio_pessoal', true, false);
    assertTest($bloqueioId > 0, "addBloqueio cadastrou bloqueio pessoal com sucesso (ID: {$bloqueioId})");

    // 2.2 Prevenção de agendamento em feriado sem confirmação
    $conflitoFeriado = $calendarService->checkConflict('2026-04-21', 'V');
    assertTest($conflitoFeriado !== null, "checkConflict detecta colisão impeditiva com feriado nacional por padrão");
    assertTest(
        str_contains(strtolower($conflitoFeriado['mensagem'] ?? ''), 'feriado'),
        "Mensagem de conflito especifica feriado nacional"
    );

    // 2.3 Exceção consciente confirmada pelo instrutor
    $conflitoExcecao = $calendarService->checkConflict('2026-04-21', 'V', null, null, true);
    assertTest($conflitoExcecao === null, "checkConflict com confirmação explícita de exceção consciente libera agendamento no feriado");

    // 2.4 Bloqueio pessoal intransponível (permite_excecao = 0)
    $conflitoPessoal = $calendarService->checkConflict('2026-07-15', 'M', null, null, true);
    assertTest($conflitoPessoal !== null, "Bloqueio pessoal não-excepcionável impede agendamento mesmo com flag de exceção");

    // =========================================================================
    // SEÇÃO 3: Sugestão Não-Intrusiva de Pontes de Feriado (Terças e Quintas)
    // =========================================================================
    echo "{$amarelo}-> 3. Testando Sugestão de Pontes de Feriado...{$reset}\n";

    // 21/04/2026 é Terça-feira -> Segunda 20/04/2026 deve sugerir ponte
    $ponteSegunda = $calendarService->checkPonteFeriado('2026-04-20');
    assertTest($ponteSegunda !== null, "checkPonteFeriado identifica segunda-feira vizinha de terça com feriado");
    assertTest(
        str_contains(strtolower($ponteSegunda['sugestao'] ?? ''), 'terça-feira') && str_contains(strtolower($ponteSegunda['sugestao'] ?? ''), 'tiradentes'),
        "Sugestão de ponte detalha a terça-feira de Tiradentes"
    );

    // 04/06/2026 é Corpus Christi (Quinta-feira) -> Sexta 05/06/2026 deve sugerir ponte
    $ponteSexta = $calendarService->checkPonteFeriado('2026-06-05');
    assertTest($ponteSexta !== null, "checkPonteFeriado identifica sexta-feira vizinha de quinta de Corpus Christi");
    assertTest(
        str_contains(strtolower($ponteSexta['sugestao'] ?? ''), 'quinta-feira'),
        "Sugestão de ponte detalha a quinta-feira com feriado"
    );

    // Quarta-feira sem feriado vizinho
    $ponteQuarta = $calendarService->checkPonteFeriado('2026-04-22');
    assertTest($ponteQuarta === null, "Quarta-feira sem ponte não dispara sugestão");

    // =========================================================================
    // SEÇÃO 4: Deslocamento Logístico / Viagem Fora de Goiânia
    // =========================================================================
    echo "{$amarelo}-> 4. Testando Deslocamento Logístico Fora de Goiânia...{$reset}\n";

    // Criar turma presencial fora de Goiânia (Rio Verde - GO)
    $stmtTurma = $pdo->prepare("
        INSERT INTO turmas (
            codigo_turma, curso_nome, cliente_nome, cidade, carga_horaria,
            data_inicio, data_conclusao, turno_padrao, status, chave_acesso, modalidade
        ) VALUES (
            'TURMA-RV-01', 'Gestão Financeira Cooperativa', 'Sicoob Credisul', 'Rio Verde - GO', 16,
            '2026-05-18', '2026-05-19', 'D', 'prevista', 'chave-rv-01', 'Presencial'
        )
    ");
    $stmtTurma->execute();
    $turmaIdRV = (int)$pdo->lastInsertId();

    // Inserir 2 encontros de aula regulares (Segunda 18/05 e Terça 19/05, Turno D)
    $pdo->prepare("
        INSERT INTO encontros (turma_id, numero_encontro, data_encontro, turno, tipo, conteudo_previsto)
        VALUES (?, 1, '2026-05-18', 'D', 'aula', 'Módulo 1: Controladoria e Riscos')
    ")->execute([$turmaIdRV]);

    $pdo->prepare("
        INSERT INTO encontros (turma_id, numero_encontro, data_encontro, turno, tipo, conteudo_previsto)
        VALUES (?, 2, '2026-05-19', 'D', 'aula', 'Módulo 2: Fechamento Contábil')
    ")->execute([$turmaIdRV]);

    // Cadastrar Deslocamento de Ida no Domingo (17/05/2026, Turno V) e Volta na Quarta (20/05/2026, Turno M)
    $deslocIda = $calendarService->scheduleDeslocamento($turmaIdRV, '2026-05-17', 'V', 'ida', 'Deslocamento Goiânia -> Rio Verde');
    assertTest($deslocIda['id'] > 0, "scheduleDeslocamento gravou deslocamento de ida com sucesso (ID: {$deslocIda['id']})");
    assertTest($deslocIda['tipo'] === 'deslocamento', "Deslocamento gravado com tipo = 'deslocamento'");

    $deslocVolta = $calendarService->scheduleDeslocamento($turmaIdRV, '2026-05-20', 'M', 'volta', 'Deslocamento Rio Verde -> Goiânia');
    assertTest($deslocVolta['id'] > 0, "scheduleDeslocamento gravou deslocamento de volta no dia seguinte (ID: {$deslocVolta['id']})");

    // Verificar que o deslocamento no domingo ocupou o turno V e previne choques
    $conflitoDesloc = $calendarService->checkConflict('2026-05-17', 'V');
    assertTest($conflitoDesloc !== null, "checkConflict bloqueia agendamento colidente com turno de deslocamento logístico");
    assertTest(
        str_contains($conflitoDesloc['mensagem'] ?? '', '✈') || str_contains(strtolower($conflitoDesloc['mensagem'] ?? ''), 'deslocamento'),
        "Mensagem de conflito traz o ícone ✈ ou termo explicativo de deslocamento logístico"
    );

    // Turno M do domingo continua livre
    $livreDomingoM = $calendarService->checkConflict('2026-05-17', 'M');
    assertTest($livreDomingoM === null, "Turno diferente no mesmo dia do deslocamento permanece livre para agendamento");

    // Testar helper addDeslocamentosParaTurma
    $stmtTurma2 = $pdo->prepare("
        INSERT INTO turmas (
            codigo_turma, curso_nome, cliente_nome, cidade, carga_horaria,
            data_inicio, data_conclusao, turno_padrao, status, chave_acesso, modalidade
        ) VALUES (
            'TURMA-ANAP-01', 'Power BI Avançado', 'Grupo Caoa', 'Anápolis - GO', 8,
            '2026-06-15', '2026-06-15', 'D', 'prevista', 'chave-anap-01', 'Presencial'
        )
    ");
    $stmtTurma2->execute();
    $turmaIdAnap = (int)$pdo->lastInsertId();

    $pdo->prepare("
        INSERT INTO encontros (turma_id, numero_encontro, data_encontro, turno, tipo, conteudo_previsto)
        VALUES (?, 1, '2026-06-15', 'D', 'aula', 'Imersão em DAX')
    ")->execute([$turmaIdAnap]);

    $deslocsAuto = $calendarService->addDeslocamentosParaTurma($turmaIdAnap, true, true, 'V');
    assertTest(count($deslocsAuto) === 2, "addDeslocamentosParaTurma gerou blocos de ida e volta automaticamente");
    assertTest($deslocsAuto[0]['data_encontro'] === '2026-06-14', "Data de ida sugerida para 1 dia antes (Domingo 14/06/2026)");
    assertTest($deslocsAuto[1]['data_encontro'] === '2026-06-16', "Data de volta sugerida para 1 dia depois (Terça 16/06/2026)");

    // =========================================================================
    // SEÇÃO 5: Isolamento Estrito do Deslocamento em Relação a Chamada e Certificados
    // =========================================================================
    echo "{$amarelo}-> 5. Testando Isolamento Estrito do Deslocamento...{$reset}\n";

    // Inserir alunos na turma de Rio Verde
    $stmtAluno1 = $pdo->prepare("INSERT INTO alunos (turma_id, nome_completo, cpf) VALUES (?, 'Carlos Eduardo Mendes', '11122233344')");
    $stmtAluno1->execute([$turmaIdRV]);
    $aluno1Id = (int)$pdo->lastInsertId();

    // 5.1 Rejeição de chamada no Modo Aula para encontro de deslocamento
    $excecaoChamada = false;
    try {
        $attendanceService->saveAttendance((int)$deslocIda['id'], 'Tentativa indevida de conteúdo', [
            $aluno1Id => 1
        ]);
    } catch (Throwable $e) {
        $excecaoChamada = true;
    }
    assertTest($excecaoChamada, "AttendanceService::saveAttendance rejeita lançamento de presenças em encontro de deslocamento");

    // 5.2 getAttendanceList rejeita ou retorna vazio para deslocamento
    $listaPresencaDesloc = $attendanceService->getAttendanceList((int)$deslocIda['id']);
    assertTest(empty($listaPresencaDesloc), "getAttendanceList retorna lista vazia para deslocamento logístico");

    // 5.3 Lançar presenças nos 2 encontros de aula normais
    $attendanceService->saveAttendance(1, 'Módulo 1 Concluído', [$aluno1Id => 1]);
    $attendanceService->saveAttendance(2, 'Módulo 2 Concluído', [$aluno1Id => 1]);

    // Frequência acumulada deve considerar estritamente 2 aulas (100%), ignorando os 2 deslocamentos
    $cumulativo = $attendanceService->calculateCumulativeFrequencies($turmaIdRV);
    assertTest($cumulativo[$aluno1Id]['total_aulas'] === 2, "calculateCumulativeFrequencies contabiliza apenas as 2 aulas pedagógicas");
    assertTest($cumulativo[$aluno1Id]['total_presencas'] === 2, "Presenças acumuladas não sofrem interferência de deslocamentos");

    // 5.4 CertificateService não inclui datas de deslocamento no verso do certificado / NFS-e
    $fechamentoData = $certificateService->getTurmaFechamentoData($turmaIdRV);
    assertTest(
        !str_contains($fechamentoData['nfse_description'] ?? '', '17/05/2026'),
        "Data de deslocamento de ida (17/05/2026) ausente da relação de datas pedagógicas do certificado / NFS-e"
    );
    assertTest(
        !str_contains($fechamentoData['nfse_description'] ?? '', '20/05/2026'),
        "Data de deslocamento de volta (20/05/2026) ausente da relação de datas pedagógicas do certificado / NFS-e"
    );

    // =========================================================================
    // SEÇÃO 6: Motor Atômico de Adiamento / Remarcação em Bloco
    // =========================================================================
    echo "{$amarelo}-> 6. Testando Motor Atômico de Adiamento / Remarcação em Bloco...{$reset}\n";

    // 6.1 Remarcação bem-sucedida da turma de Rio Verde de 18/05 para 25/05 (uma semana depois)
    $resultadoRemarcacao = $calendarService->rescheduleTurma($turmaIdRV, '2026-05-25');
    assertTest($resultadoRemarcacao['success'] === true, "rescheduleTurma executado com sucesso");

    // Verificar se as datas dos encontros no banco foram atualizadas
    $stmtEncs = $pdo->prepare("SELECT numero_encontro, data_encontro, tipo FROM encontros WHERE turma_id = ? ORDER BY id ASC");
    $stmtEncs->execute([$turmaIdRV]);
    $encsNovos = $stmtEncs->fetchAll();

    $mapDatas = [];
    foreach ($encsNovos as $e) {
        $mapDatas[$e['tipo'] . '_' . $e['numero_encontro']] = $e['data_encontro'];
    }

    assertTest(($mapDatas['aula_1'] ?? '') === '2026-05-25', "Aula 1 movida para 25/05/2026 (+7 dias)");
    assertTest(($mapDatas['aula_2'] ?? '') === '2026-05-26', "Aula 2 movida para 26/05/2026 (+7 dias)");

    // Deslocamentos devem ter sido movidos atomicamente junto (+7 dias)
    $datasDeslocNovas = array_filter($encsNovos, fn($e) => $e['tipo'] === 'deslocamento');
    $datasDeslocList = array_values(array_column($datasDeslocNovas, 'data_encontro'));
    assertTest(in_array('2026-05-24', $datasDeslocList, true), "Deslocamento de ida movido atomicamente para 24/05/2026");
    assertTest(in_array('2026-05-27', $datasDeslocList, true), "Deslocamento de volta movido atomicamente para 27/05/2026");

    // Verificar atualização dos metadados da turma
    $stmtTurmaAtual = $pdo->prepare("SELECT data_inicio, data_conclusao FROM turmas WHERE id = ?");
    $stmtTurmaAtual->execute([$turmaIdRV]);
    $turmaAtual = $stmtTurmaAtual->fetch();
    assertTest($turmaAtual['data_inicio'] === '2026-05-25', "turmas.data_inicio atualizada para a nova data de início");
    assertTest($turmaAtual['data_conclusao'] === '2026-05-26', "turmas.data_conclusao recalculada com base no último encontro pedagógico");

    // 6.2 Teste de Atomicidade e Rollback em caso de colisão:
    // Criar turma conflitante exatamente no dia 2026-06-02 no Turno D
    $stmtConflitante = $pdo->prepare("
        INSERT INTO turmas (
            codigo_turma, curso_nome, cliente_nome, data_inicio, data_conclusao, turno_padrao, status, chave_acesso
        ) VALUES (
            'TURMA-OBSTACULO-01', 'Curso Bloqueante', 'Cliente Conflito', '2026-06-02', '2026-06-02', 'D', 'prevista', 'chave-obstaculo'
        )
    ");
    $stmtConflitante->execute();
    $turmaObstaculoId = (int)$pdo->lastInsertId();

    $pdo->prepare("
        INSERT INTO encontros (turma_id, numero_encontro, data_encontro, turno, tipo)
        VALUES (?, 1, '2026-06-02', 'D', 'aula')
    ")->execute([$turmaObstaculoId]);

    // Tentar remarcar a turma de Rio Verde para 2026-06-01:
    // Ida seria 31/05, Aula 1 seria 01/06, Aula 2 seria 02/06 (COLIDE COM TURMA-OBSTACULO-01!)
    $falhouPorColisao = false;
    $mensagemErroColisao = '';
    try {
        $calendarService->rescheduleTurma($turmaIdRV, '2026-06-01');
    } catch (InvalidArgumentException $e) {
        $falhouPorColisao = true;
        $mensagemErroColisao = $e->getMessage();
    }

    assertTest($falhouPorColisao, "rescheduleTurma abortou a operação devido a choque de horário no dia 02/06");
    assertTest(str_contains(strtolower($mensagemErroColisao), 'choque') || str_contains(strtolower($mensagemErroColisao), 'conflito') || str_contains(strtolower($mensagemErroColisao), 'ocupad'), "Exceção detalha o choque impeditivo");

    // Validação da Atomicidade: NENHUM encontro da turma RV deve ter saído de 25/05 e 26/05!
    $stmtEncsPreservados = $pdo->prepare("SELECT data_encontro FROM encontros WHERE turma_id = ? AND tipo = 'aula' ORDER BY numero_encontro ASC");
    $stmtEncsPreservados->execute([$turmaIdRV]);
    $datasPreservadas = $stmtEncsPreservados->fetchAll(PDO::FETCH_COLUMN);

    assertTest(
        $datasPreservadas === ['2026-05-25', '2026-05-26'],
        "Atomicidade absoluta confirmada: todas as datas originais permaneceram 100% intactas após o rollback"
    );

    // =========================================================================
    // SEÇÃO 7: Teto Mensal de 80h e Medidor de Sobrecarga de Capacidade
    // =========================================================================
    echo "{$amarelo}-> 7. Testando Teto Mensal de 80h e Medidor de Sobrecarga...{$reset}\n";

    // Criar turmas em Agosto de 2026 totalizando exatamente 64 horas
    for ($i = 1; $i <= 4; $i++) {
        $stmtT = $pdo->prepare("
            INSERT INTO turmas (
                codigo_turma, curso_nome, cliente_nome, carga_horaria, data_inicio, data_conclusao, turno_padrao, status, chave_acesso
            ) VALUES (
                ?, 'Curso Capacidade', 'Cliente Teste', 16, ?, ?, 'V', 'prevista', ?
            )
        ");
        $dIni = sprintf('2026-08-%02d', 3 + ($i * 5));
        $dFim = sprintf('2026-08-%02d', 4 + ($i * 5));
        $stmtT->execute(["TURMA-CAP-{$i}", $dIni, $dFim, "chave-cap-{$i}"]);
        $tId = (int)$pdo->lastInsertId();

        $pdo->prepare("INSERT INTO encontros (turma_id, numero_encontro, data_encontro, turno, tipo, horario_inicio, horario_fim) VALUES (?, 1, ?, 'V', 'aula', '14:00:00', '18:00:00')")->execute([$tId, $dIni]);
        $pdo->prepare("INSERT INTO encontros (turma_id, numero_encontro, data_encontro, turno, tipo, horario_inicio, horario_fim) VALUES (?, 2, ?, 'V', 'aula', '14:00:00', '18:00:00')")->execute([$tId, $dFim]);
        $pdo->prepare("INSERT INTO encontros (turma_id, numero_encontro, data_encontro, turno, tipo, horario_inicio, horario_fim) VALUES (?, 3, ?, 'M', 'aula', '08:00:00', '12:00:00')")->execute([$tId, $dIni]);
        $pdo->prepare("INSERT INTO encontros (turma_id, numero_encontro, data_encontro, turno, tipo, horario_inicio, horario_fim) VALUES (?, 4, ?, 'M', 'aula', '08:00:00', '12:00:00')")->execute([$tId, $dFim]);
    }

    // Calcular workload de Agosto/2026
    $workloadAgosto = $calendarService->calculateMonthlyWorkload(2026, 8);
    assertTest((int)$workloadAgosto['total_horas'] === 64, "calculateMonthlyWorkload aponta exatamente 64h em Agosto de 2026");
    assertTest($workloadAgosto['teto_horas'] === 80, "Teto padrão de 80h registrado");
    assertTest($workloadAgosto['porcentagem'] === 80.0, "Porcentagem calculada em 80.0%");
    assertTest($workloadAgosto['is_sobrecarga'] === false, "is_sobrecarga = false quando dentro do teto de 80h");

    // Adicionar mais uma turma de 24h em Agosto/2026 (64h + 24h = 88h -> ultrapassa 80h)
    $stmtExtra = $pdo->prepare("
        INSERT INTO turmas (
            codigo_turma, curso_nome, cliente_nome, carga_horaria, data_inicio, data_conclusao, turno_padrao, status, chave_acesso
        ) VALUES (
            'TURMA-EXTRA-88H', 'Formação Especial', 'Cliente Sobrecarga', 24, '2026-08-28', '2026-08-30', 'N', 'prevista', 'chave-extra'
        )
    ");
    $stmtExtra->execute();
    $extraId = (int)$pdo->lastInsertId();

    for ($d = 28; $d <= 30; $d++) {
        $pdo->prepare("INSERT INTO encontros (turma_id, numero_encontro, data_encontro, turno, tipo, horario_inicio, horario_fim) VALUES (?, ?, ?, 'N', 'aula', '18:30:00', '22:30:00')")->execute([$extraId, $d - 27, "2026-08-{$d}"]);
        $pdo->prepare("INSERT INTO encontros (turma_id, numero_encontro, data_encontro, turno, tipo, horario_inicio, horario_fim) VALUES (?, ?, ?, 'V', 'aula', '14:00:00', '18:00:00')")->execute([$extraId, $d - 24, "2026-08-{$d}"]);
    }

    $workloadSobrecarga = $calendarService->calculateMonthlyWorkload(2026, 8);
    assertTest((int)$workloadSobrecarga['total_horas'] === 88, "calculateMonthlyWorkload aponta 88h após nova turma");
    assertTest($workloadSobrecarga['is_sobrecarga'] === true, "is_sobrecarga = true ao ultrapassar 80h");
    assertTest($workloadSobrecarga['status_capacidade'] === 'sobrecarga', "status_capacidade classificado como 'sobrecarga'");
    assertTest(
        str_contains(strtolower($workloadSobrecarga['mensagem_aviso'] ?? ''), '88h') && str_contains(strtolower($workloadSobrecarga['mensagem_aviso'] ?? ''), '80h'),
        "Mensagem de aviso severo de sobrecarga detalha 88h e teto de 80h"
    );

    // =========================================================================
    // SEÇÃO 8: Dados Integrados do Calendário Mensal e Anual
    // =========================================================================
    echo "{$amarelo}-> 8. Testando Integração com Matriz Mensal e Anual...{$reset}\n";

    $mesAgostoData = $calendarService->getMonthCalendarData(2026, 8);
    assertTest(isset($mesAgostoData['workload']), "getMonthCalendarData inclui bloco 'workload' nos metadados");
    assertTest($mesAgostoData['workload']['total_horas'] === 88, "Metadados da visão mensal informam 88h de carga");
    assertTest($mesAgostoData['workload']['is_sobrecarga'] === true, "Metadados da visão mensal sinalizam sobrecarga");

    $anoData = $calendarService->getYearCalendarData(2026);
    assertTest(isset($anoData['meses'][8]['workload']), "getYearCalendarData inclui workload no mês de Agosto");
    assertTest($anoData['meses'][8]['workload']['total_horas'] === 88, "Cartão anual de Agosto exibe 88h");
    assertTest($anoData['meses'][8]['workload']['is_sobrecarga'] === true, "Cartão anual de Agosto marcado com sobrecarga");

    // Verificar se deslocamentos possuem badge ✈ nos dados de encontro e popover
    $mesMaioData = $calendarService->getMonthCalendarData(2026, 5);
    $dia24Maio = null;
    foreach ($mesMaioData['semanas'] as $sem) {
        foreach ($sem as $dia) {
            if ($dia['data'] === '2026-05-24') {
                $dia24Maio = $dia;
                break 2;
            }
        }
    }
    assertTest($dia24Maio !== null, "Dia 24/05/2026 localizado na visão mensal");
    assertTest(!empty($dia24Maio['encontros']), "Dia 24/05 possui encontro registrado");
    assertTest(($dia24Maio['encontros'][0]['tipo'] ?? '') === 'deslocamento', "Encontro identificado com tipo = 'deslocamento'");
    assertTest(str_contains($dia24Maio['encontros'][0]['badge'] ?? '', '✈'), "Encontro possui badge de deslocamento com ícone ✈");

} catch (Throwable $e) {
    echo "\n{$vermelho}Exceção fatal durante a execução dos testes: {$e->getMessage()}{$reset}\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
} finally {
    if (file_exists($testDbPath)) {
        @unlink($testDbPath);
    }
}

echo "\n{$azul}======================================================================{$reset}\n";
if ($passedCount === $totalCount && $totalCount > 0) {
    echo "{$verde} Suíte de Testes Ticket 08 Finalizada: {$passedCount}/{$totalCount} testes passaram com sucesso!{$reset}\n";
    echo "{$azul}======================================================================{$reset}\n";
    exit(0);
} else {
    echo "{$vermelho} Suíte de Testes Ticket 08 com Falhas: {$passedCount}/{$totalCount} testes passaram.{$reset}\n";
    echo "{$azul}======================================================================{$reset}\n";
    exit(1);
}
