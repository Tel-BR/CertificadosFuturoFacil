<?php
/**
 * Suíte de Testes Automatizados — Ticket 09: Gerenciamento Completo de Turmas,
 * Modo Multi-Seleção no Calendário, Override de Encontros e Bidirecionalidade
 * Plataforma Integrada de Gestão Pedagógica, Diário e Certificados Futuro Fácil
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Config/Database.php';
require_once __DIR__ . '/../src/Services/CalendarService.php';
require_once __DIR__ . '/../src/Services/AttendanceService.php';
require_once __DIR__ . '/../src/Services/CertificateService.php';
require_once __DIR__ . '/../src/Services/AuthService.php';
require_once __DIR__ . '/../src/Services/TurmaService.php';

use FuturoFacil\Config\Database;
use FuturoFacil\Services\CalendarService;
use FuturoFacil\Services\AttendanceService;
use FuturoFacil\Services\CertificateService;
use FuturoFacil\Services\AuthService;
use FuturoFacil\Services\TurmaService;

$verde = "\033[32m";
$vermelho = "\033[31m";
$amarelo = "\033[33m";
$azul = "\033[36m";
$reset = "\033[0m";

echo "{$azul}======================================================================\n";
echo " Executando Suíte de Testes — Ticket 09: Turmas, Modo Seleção & Overrides\n";
echo "======================================================================{$reset}\n\n";

$testDbPath = __DIR__ . '/test_temp_ticket_09.db';
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
    $turmaService = new TurmaService($pdo, $calendarService);
    $authService = new AuthService($pdo);

    // =========================================================================
    // SEÇÃO 1: Bidirecionalidade Inteligente de Horários e Turnos
    // =========================================================================
    echo "{$amarelo}-> 1. Testando Bidirecionalidade Inteligente de Horários e Turnos...{$reset}\n";

    // 1.1 Inferência de horários livres para turnos
    assertTest(
        TurmaService::inferTurnoFromHorarios('08:00', '12:00') === 'M',
        "Horário 08:00 - 12:00 infere turno Matutino [M]"
    );
    assertTest(
        TurmaService::inferTurnoFromHorarios('09:00', '12:30') === 'M',
        "Horário livre matutino 09:00 - 12:30 infere turno Matutino [M]"
    );
    assertTest(
        TurmaService::inferTurnoFromHorarios('13:00', '17:00') === 'V',
        "Horário livre vespertino 13:00 - 17:00 infere turno Vespertino [V] (Ticket Exemplo)"
    );
    assertTest(
        TurmaService::inferTurnoFromHorarios('14:00', '16:00') === 'V',
        "Horário livre reduzido 14:00 - 16:00 infere turno Vespertino [V] (Ticket Exemplo)"
    );
    assertTest(
        TurmaService::inferTurnoFromHorarios('14:00', '18:00') === 'V',
        "Horário padrão 14:00 - 18:00 infere turno Vespertino [V]"
    );
    assertTest(
        TurmaService::inferTurnoFromHorarios('18:30', '22:30') === 'N',
        "Horário noturno 18:30 - 22:30 infere turno Noturno [N]"
    );
    assertTest(
        TurmaService::inferTurnoFromHorarios('19:00', '22:00') === 'N',
        "Horário noturno livre 19:00 - 22:00 infere turno Noturno [N]"
    );
    assertTest(
        TurmaService::inferTurnoFromHorarios('08:00', '17:00') === 'D',
        "Horário integral 08:00 - 17:00 infere turno Dia Todo [D]"
    );
    assertTest(
        TurmaService::inferTurnoFromHorarios('08:30', '16:30') === 'D',
        "Horário integral livre 08:30 - 16:30 infere turno Dia Todo [D]"
    );

    // 1.2 Horários padrões pré-carregados a partir do turno
    $padraoM = TurmaService::getTurnoDefaultHorarios('M');
    assertTest(
        $padraoM['horario_inicio'] === '08:00' && $padraoM['horario_fim'] === '12:00',
        "Turno [M] pré-carrega horário padrão 08:00 - 12:00"
    );

    $padraoV = TurmaService::getTurnoDefaultHorarios('V');
    assertTest(
        $padraoV['horario_inicio'] === '14:00' && $padraoV['horario_fim'] === '18:00',
        "Turno [V] pré-carrega horário padrão 14:00 - 18:00"
    );

    $padraoN = TurmaService::getTurnoDefaultHorarios('N');
    assertTest(
        $padraoN['horario_inicio'] === '18:30' && $padraoN['horario_fim'] === '22:30',
        "Turno [N] pré-carrega horário padrão 18:30 - 22:30"
    );

    $padraoD = TurmaService::getTurnoDefaultHorarios('D');
    assertTest(
        $padraoD['horario_inicio'] === '08:00' && $padraoD['horario_fim'] === '17:00',
        "Turno [D] pré-carrega horário padrão 08:00 - 17:00"
    );

    // =========================================================================
    // SEÇÃO 2: Criação de Turma Desacoplada com 0 Alunos
    // =========================================================================
    echo "{$amarelo}-> 2. Testando Desacoplamento e Criação com 0 Alunos...{$reset}\n";

    $dadosTurma0 = [
        'curso_nome'    => 'Power BI Corporativo Sem Alunos',
        'cliente_nome'  => 'Cooperativa Aurora',
        'modalidade'    => 'Presencial',
        'cidade'        => 'Goiânia - GO',
        'carga_horaria' => 16,
        'turno_padrao'  => 'V',
    ];

    $turmaId0 = $turmaService->createTurma($dadosTurma0, []);
    assertTest($turmaId0 > 0, "Turma criada com sucesso sem alunos matriculados (ID {$turmaId0})");

    $turmaCriada = $turmaService->getTurmaById($turmaId0);
    assertTest($turmaCriada !== null, "Turma recuperada do banco de dados");
    assertTest(!empty($turmaCriada['codigo_turma']), "Código da turma gerado automaticamente: " . ($turmaCriada['codigo_turma'] ?? ''));
    assertTest(!empty($turmaCriada['chave_acesso']), "Chave de acesso gerada automaticamente: " . ($turmaCriada['chave_acesso'] ?? ''));

    // Verifica que total de alunos é 0
    $stmtCountAlunos = $pdo->prepare("SELECT COUNT(*) FROM alunos WHERE turma_id = ?");
    $stmtCountAlunos->execute([$turmaId0]);
    $totalAlunos = (int)$stmtCountAlunos->fetchColumn();
    assertTest($totalAlunos === 0, "Turma possui exatamente 0 alunos cadastrados, pronta para uso pedagógico e agenda");

    // =========================================================================
    // SEÇÃO 3: Grade Individual de Encontros com Override de Dias e Horários
    // =========================================================================
    echo "{$amarelo}-> 3. Testando Grade de Encontros com Overrides Individuais...{$reset}\n";

    // Criar uma turma vespertina com 3 encontros, sendo o terceiro no sábado de manhã (override clássico)
    $dadosTurmaOverride = [
        'codigo_turma'  => 'TURMA-2026-OVERRIDE',
        'curso_nome'    => 'Excel Avançado & Dashboards',
        'cliente_nome'  => 'Banco Sicoob Cerrado',
        'modalidade'    => 'Presencial',
        'cidade'        => 'Goiânia - GO',
        'carga_horaria' => 12,
        'turno_padrao'  => 'V',
        'chave_acesso'  => 'excel-sicoob-override',
    ];

    $gradeEncontros = [
        [
            'numero_encontro'   => 1,
            'data_encontro'     => '2026-11-04',
            'turno'             => 'V',
            'horario_inicio'    => '14:00',
            'horario_fim'       => '18:00',
            'conteudo_previsto' => 'Módulo 1: Fórmulas Avançadas',
            'tipo'              => 'aula',
        ],
        [
            'numero_encontro'   => 2,
            'data_encontro'     => '2026-11-05',
            'turno'             => 'V',
            'horario_inicio'    => '13:00', // Override de horário no vespertino
            'horario_fim'       => '17:00',
            'conteudo_previsto' => 'Módulo 2: Power Query e ETL',
            'tipo'              => 'aula',
        ],
        [
            'numero_encontro'   => 3,
            'data_encontro'     => '2026-11-07', // Sábado (override de data, turno e horário!)
            'turno'             => 'M',          // Matutino
            'horario_inicio'    => '08:00',
            'horario_fim'       => '12:00',
            'conteudo_previsto' => 'Módulo 3: Reposição de Sábado & Dashboards',
            'tipo'              => 'aula',
        ],
    ];

    $turmaIdOverride = $turmaService->createTurma($dadosTurmaOverride, $gradeEncontros);
    assertTest($turmaIdOverride > 0, "Turma com grade individual e overrides criada com sucesso (ID {$turmaIdOverride})");

    // Consulta encontros criados
    $stmtEnc = $pdo->prepare("SELECT * FROM encontros WHERE turma_id = ? ORDER BY numero_encontro ASC");
    $stmtEnc->execute([$turmaIdOverride]);
    $encontrosGravados = $stmtEnc->fetchAll(PDO::FETCH_ASSOC);

    assertTest(count($encontrosGravados) === 3, "Exatamente 3 encontros persistidos na tabela encontros");

    // Valida Encontro 1
    assertTest(
        $encontrosGravados[0]['data_encontro'] === '2026-11-04' && $encontrosGravados[0]['turno'] === 'V',
        "Encontro 1: Quarta-feira 04/11/2026 no turno Vespertino [V]"
    );

    // Valida Encontro 2 (Horário Livre 13:00 - 17:00)
    assertTest(
        $encontrosGravados[1]['horario_inicio'] === '13:00' && $encontrosGravados[1]['horario_fim'] === '17:00',
        "Encontro 2: Horário específico customizado para 13:00 - 17:00"
    );

    // Valida Encontro 3 (Sábado Matutino [M])
    assertTest(
        $encontrosGravados[2]['data_encontro'] === '2026-11-07' &&
        $encontrosGravados[2]['turno'] === 'M' &&
        $encontrosGravados[2]['horario_inicio'] === '08:00',
        "Encontro 3 (Override): Sábado 07/11/2026 no turno Matutino [M] das 08:00 às 12:00"
    );

    // Valida cálculo de data_inicio e data_conclusao da turma
    $turmaRecarregada = $turmaService->getTurmaById($turmaIdOverride);
    assertTest(
        $turmaRecarregada['data_inicio'] === '2026-11-04',
        "data_inicio da turma ajustada para a data do primeiro encontro: 2026-11-04"
    );
    assertTest(
        $turmaRecarregada['data_conclusao'] === '2026-11-07',
        "data_conclusao da turma ajustada para a data do último encontro: 2026-11-07"
    );

    // =========================================================================
    // SEÇÃO 4: Atualização Individual de Encontro e Adição de Reposição
    // =========================================================================
    echo "{$amarelo}-> 4. Testando Edição Individual de Encontro e Adição de Reposição...{$reset}\n";

    // 4.1 Atualizar o encontro 2 para outro horário e turno
    $encontroId2 = (int)$encontrosGravados[1]['id'];
    $okUpdate = $turmaService->updateEncontro($encontroId2, [
        'data_encontro'     => '2026-11-05',
        'horario_inicio'    => '14:00',
        'horario_fim'       => '18:00',
        'turno'             => 'V',
        'conteudo_previsto' => 'Conteúdo Reajustado: Power Query Avançado',
    ]);
    assertTest($okUpdate, "Encontro 2 atualizado com sucesso via updateEncontro");

    $stmtE2 = $pdo->prepare("SELECT * FROM encontros WHERE id = ?");
    $stmtE2->execute([$encontroId2]);
    $e2Atualizado = $stmtE2->fetch(PDO::FETCH_ASSOC);
    assertTest(
        $e2Atualizado['horario_inicio'] === '14:00' && $e2Atualizado['conteudo_previsto'] === 'Conteúdo Reajustado: Power Query Avançado',
        "Encontro 2 reflete novos horários e plano de aula no banco"
    );

    // 4.2 Adicionar aula de reposição extra
    $novoEncId = $turmaService->addEncontro($turmaIdOverride, [
        'data_encontro'     => '2026-11-09',
        'turno'             => 'N',
        'horario_inicio'    => '18:30',
        'horario_fim'       => '22:30',
        'tipo'              => 'aula',
        'conteudo_previsto' => 'Encontro 4: Reposição Noturna de Exercícios',
    ]);
    assertTest($novoEncId > 0, "Aula extra de reposição adicionada com sucesso (ID {$novoEncId})");

    $stmtCountTotal = $pdo->prepare("SELECT COUNT(*) FROM encontros WHERE turma_id = ?");
    $stmtCountTotal->execute([$turmaIdOverride]);
    assertTest((int)$stmtCountTotal->fetchColumn() === 4, "Turma agora conta com 4 encontros");

    // data_conclusao deve ter sido atualizada para 2026-11-09
    $turmaComReposicao = $turmaService->getTurmaById($turmaIdOverride);
    assertTest(
        $turmaComReposicao['data_conclusao'] === '2026-11-09',
        "data_conclusao recalculada para 2026-11-09 após inserção de reposição"
    );

    // =========================================================================
    // SEÇÃO 5: Exclusão de Encontros Pendentes vs Blindagem com Chamada
    // =========================================================================
    echo "{$amarelo}-> 5. Testando Exclusão de Encontros Pendentes e Blindagem com Presença...{$reset}\n";

    // 5.1 Excluir o encontro 4 que ainda está pendente (sem chamadas)
    $resDelPendente = $turmaService->deleteEncontro($novoEncId);
    assertTest($resDelPendente['success'], "Encontro pendente sem chamadas excluído com sucesso");

    $stmtCheckDel = $pdo->prepare("SELECT COUNT(*) FROM encontros WHERE id = ?");
    $stmtCheckDel->execute([$novoEncId]);
    assertTest((int)$stmtCheckDel->fetchColumn() === 0, "Encontro removido da base de dados");

    // 5.2 Cadastrar um aluno e lançar presença no Encontro 1
    $stmtCadAluno = $pdo->prepare("INSERT INTO alunos (turma_id, nome_completo) VALUES (?, 'Carlos da Silva')");
    $stmtCadAluno->execute([$turmaIdOverride]);
    $alunoId = (int)$pdo->lastInsertId();

    $encontroId1 = (int)$encontrosGravados[0]['id'];
    $stmtFreq = $pdo->prepare("INSERT INTO frequencias (encontro_id, aluno_id, presente) VALUES (?, ?, 1)");
    $stmtFreq->execute([$encontroId1, $alunoId]);

    // Tentar excluir o Encontro 1 com presença gravada
    $resDelComChamada = $turmaService->deleteEncontro($encontroId1);
    assertTest(
        $resDelComChamada['success'] === false,
        "Exclusão de encontro com chamadas gravadas foi bloqueada"
    );
    assertTest(
        str_contains(strtolower($resDelComChamada['message']), 'chamada') || str_contains(strtolower($resDelComChamada['message']), 'frequência') || str_contains(strtolower($resDelComChamada['message']), 'presença'),
        "Mensagem de erro alerta sobre a presença de chamadas registradas no encontro"
    );

    // =========================================================================
    // SEÇÃO 6: Transição Automática de Ciclo de Vida (prevista -> em_andamento)
    // =========================================================================
    echo "{$amarelo}-> 6. Testando Transição Automática de Ciclo de Vida...{$reset}\n";

    // 6.1 Turma com data de primeiro encontro no futuro (ex: 2026-12-01) deve manter prevista
    $dadosTurmaFutura = [
        'codigo_turma'  => 'TURMA-2026-FUTURA',
        'curso_nome'    => 'Gestão Ágil Futura',
        'cliente_nome'  => 'Sebrae GO',
        'carga_horaria' => 8,
        'status'        => 'prevista',
    ];
    $encFuturo = [
        [
            'numero_encontro'   => 1,
            'data_encontro'     => '2026-12-01',
            'turno'             => 'M',
            'horario_inicio'    => '08:00',
            'horario_fim'       => '12:00',
            'tipo'              => 'aula',
        ],
    ];
    $turmaIdFutura = $turmaService->createTurma($dadosTurmaFutura, $encFuturo);

    $tFuturaAntes = $turmaService->getTurmaById($turmaIdFutura);
    assertTest($tFuturaAntes['status'] === 'prevista', "Turma futura cadastrada com status 'prevista'");

    // Executa verificação com data anterior ao início (2026-11-20)
    $qtdTransicoes = $turmaService->checkAndTransitionLifecycle($turmaIdFutura, '2026-11-20');
    assertTest($qtdTransicoes === 0, "Nenhuma transição efetuada antes da data de início");
    $tFuturaAinda = $turmaService->getTurmaById($turmaIdFutura);
    assertTest($tFuturaAinda['status'] === 'prevista', "Status permanece 'prevista' antes da data do primeiro encontro");

    // Executa verificação simulando o dia do primeiro encontro (2026-12-01)
    $qtdTransicoesHoje = $turmaService->checkAndTransitionLifecycle($turmaIdFutura, '2026-12-01');
    assertTest($qtdTransicoesHoje === 1, "checkAndTransitionLifecycle retornou 1 transição efetuada");

    $tFuturaEmAndamento = $turmaService->getTurmaById($turmaIdFutura);
    assertTest(
        $tFuturaEmAndamento['status'] === 'em_andamento',
        "Status da turma transicionado automaticamente de 'prevista' para 'em_andamento' na data do primeiro encontro"
    );

    // =========================================================================
    // SEÇÃO 7: Roteamento Web e Renderização do Formulário Web
    // =========================================================================
    echo "{$amarelo}-> 7. Testando Roteamento Web e Renderização de Formulário...{$reset}\n";

    // 7.1 Testa se os arquivos public/diario/turma_form.php existem
    $formFile = __DIR__ . '/../public/diario/turma_form.php';
    assertTest(file_exists($formFile), "Arquivo public/diario/turma_form.php existe");

    // 7.2 Testa parsing de datas múltiplas do Modo Seleção
    $datasQuery = '2026-11-10,2026-11-12,2026-11-11';
    $datasOrdenadas = TurmaService::parseSelectedDates($datasQuery);
    assertTest(
        $datasOrdenadas === ['2026-11-10', '2026-11-11', '2026-11-12'],
        "Datas informadas desordenadas via query string são normalizadas em ordem cronológica"
    );

    // 7.3 Renderização estática de elementos do formulário
    $formContent = file_exists($formFile) ? file_get_contents($formFile) : '';
    assertTest(
        str_contains($formContent, 'form-turma') || str_contains($formContent, 'curso_nome'),
        "Formulário web disponibiliza campo para nome do curso"
    );
    assertTest(
        str_contains($formContent, 'carga_horaria'),
        "Formulário web disponibiliza campo de carga horária"
    );
    assertTest(
        str_contains($formContent, 'turno_padrao'),
        "Formulário web disponibiliza seletor de turno padrão"
    );
    assertTest(
        str_contains($formContent, 'grade-encontros') || str_contains($formContent, 'tabela-encontros'),
        "Formulário web disponibiliza grade editável de encontros"
    );
    assertTest(
        str_contains($formContent, 'inferTurno') || str_contains($formContent, 'inferirTurno'),
        "Formulário frontend contém lógica de inferência bidirecional inteligente de turno e horário"
    );

    // 7.4 Verifica botão [ Modo Seleção ] no calendário
    $calContent = file_get_contents(__DIR__ . '/../public/diario/calendario.php');
    assertTest(
        str_contains($calContent, 'Modo Seleção') || str_contains($calContent, 'modo-selecao'),
        "Calendário mensal disponibiliza botão '[ Modo Seleção ]'"
    );
    assertTest(
        str_contains($calContent, 'Agendar Turma nestas Datas') || str_contains($calContent, 'agendar-turma-datas'),
        "Calendário mensal possui barra flutuante de ação rápida com botão de agendamento em lote"
    );

    // 7.5 Verifica botão [ + Nova Turma ] na listagem
    $turmasContent = file_get_contents(__DIR__ . '/../public/diario/turmas.php');
    assertTest(
        str_contains($turmasContent, '/diario/turmas/novo'),
        "Tela /diario/turmas contém link ou botão para '/diario/turmas/novo'"
    );

    // 7.6 Verifica botão [ ✏ Editar Turma ] nos detalhes
    $turmaViewContent = file_get_contents(__DIR__ . '/../public/diario/turma.php');
    assertTest(
        str_contains($turmaViewContent, '/diario/turma/editar'),
        "Tela /diario/turma contém link ou botão para '/diario/turma/editar'"
    );

} catch (Throwable $e) {
    echo "{$vermelho}Erro fatal na execução dos testes: {$e->getMessage()}{$reset}\n";
    echo $e->getTraceAsString() . "\n";
} finally {
    Database::resetConnection();
    unset($pdo);
    if (file_exists($testDbPath)) {
        @unlink($testDbPath);
    }
}

echo "\n{$azul}======================================================================{$reset}\n";
echo " Suíte Ticket 09 Finalizada: {$passedCount}/{$totalCount} asserções passaram.\n";
if ($passedCount === $totalCount && $totalCount > 0) {
    echo " {$verde}100% DOS TESTES DO TICKET 09 FORAM APROVADOS!{$reset}\n";
} else {
    echo " {$vermelho}Houve falhas na suíte de testes. Verifique os erros acima.{$reset}\n";
}
echo "{$azul}======================================================================{$reset}\n";
