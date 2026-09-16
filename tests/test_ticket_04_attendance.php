<?php
/**
 * Suíte de Testes Automatizados — Ticket 04: Modo Aula e Diário de Frequência
 * Costura de Teste 3 (Testing Seam 3): Borda Transacional de Chamada e Fechamento
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Config/Database.php';
require_once __DIR__ . '/../src/Services/AttendanceService.php';
require_once __DIR__ . '/../src/Services/AuthService.php';

use FuturoFacil\Config\Database;
use FuturoFacil\Services\AttendanceService;
use FuturoFacil\Services\AuthService;

$verde = "\033[32m";
$vermelho = "\033[31m";
$amarelo = "\033[33m";
$azul = "\033[36m";
$reset = "\033[0m";

echo "{$azul}======================================================================\n";
echo " Executando Suíte de Testes — Ticket 04: Modo Aula e Costura de Teste 3\n";
echo "======================================================================{$reset}\n\n";

$testDbPath = __DIR__ . '/test_temp_attendance.db';
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
    // 1. Inicializa banco SQLite isolado para os testes
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

    // 2. Popula turma de teste com 30 alunos e 4 encontros
    $pdo->exec("
        INSERT INTO turmas (
            codigo_turma, curso_nome, cliente_nome, ordem_servico,
            data_inicio, data_conclusao, turno_padrao, status, chave_acesso,
            carga_horaria, instrutor, cidade, ementa
        ) VALUES (
            'TURMA-MODO-AULA-TEST', 'Excel Corporativo Especialista', 'Sicoob Credisul', 'OS-2026-004',
            '2026-09-14', '2026-09-17', 'V', 'em_andamento', 'CHAVE-AULA-TEST-2026',
            16, 'Telmo Tropia', 'Goiânia - GO', 'Fórmulas, Dinâmicas, Dashboards e Power Query'
        )
    ");
    $turmaId = (int)$pdo->lastInsertId();

    $encontrosData = [
        [1, '2026-09-14', '14:00', '18:00', 'Introdução ao Excel Avançado e PROCV', null, 'aula', 0],
        [2, '2026-09-15', '14:00', '18:00', 'Tabelas Dinâmicas e Fórmulas Matriciais', null, 'aula', 0],
        [3, '2026-09-16', '14:00', '18:00', 'Segmentação de Dados e Dashboards', null, 'aula', 0],
        [4, '2026-09-17', '14:00', '18:00', 'Automação com Power Query', null, 'aula', 0],
    ];
    $stmtEnc = $pdo->prepare("
        INSERT INTO encontros (
            turma_id, numero_encontro, data_encontro, turno, horario_inicio, horario_fim,
            conteudo_previsto, conteudo_ministrado, tipo, abonado
        ) VALUES (
            ?, ?, ?, 'V', ?, ?,
            ?, ?, ?, ?
        )
    ");
    $encontroIds = [];
    foreach ($encontrosData as $enc) {
        $stmtEnc->execute([
            $turmaId, $enc[0], $enc[1], $enc[2], $enc[3],
            $enc[4], $enc[5], $enc[6], $enc[7]
        ]);
        $encontroIds[$enc[0]] = (int)$pdo->lastInsertId();
    }

    $stmtAluno = $pdo->prepare("
        INSERT INTO alunos (turma_id, nome_completo, cpf, cpf_limpo, cpf_mascarado)
        VALUES (?, ?, ?, ?, ?)
    ");
    $alunoIds = [];
    for ($i = 1; $i <= 30; $i++) {
        $nome = "Aluno Teste " . str_pad((string)$i, 2, '0', STR_PAD_LEFT);
        $cpfLimpo = str_pad((string)$i, 11, '1', STR_PAD_LEFT);
        $cpfFormatado = substr($cpfLimpo, 0, 3) . '.' . substr($cpfLimpo, 3, 3) . '.' . substr($cpfLimpo, 6, 3) . '-' . substr($cpfLimpo, 9, 2);
        $cpfMascarado = '***.' . substr($cpfLimpo, 3, 3) . '.' . substr($cpfLimpo, 6, 3) . '-**';
        $stmtAluno->execute([$turmaId, $nome, $cpfFormatado, $cpfLimpo, $cpfMascarado]);
        $alunoIds[] = (int)$pdo->lastInsertId();
    }

    $service = new AttendanceService($pdo);

    // =========================================================================
    // SEÇÃO 1: Detalhes do Encontro e Plano de Aula Dinâmico
    // =========================================================================
    echo "{$amarelo}-> 1. Testando Detalhes do Encontro e Plano Dinâmico...{$reset}\n";

    $details = $service->getEncontroDetails($encontroIds[1]);
    assertTest($details !== null, "Encontro 1 localizado com sucesso");
    assertTest($details['turma_id'] === $turmaId, "turma_id corresponde à turma criada");
    assertTest($details['curso_nome'] === 'Excel Corporativo Especialista', "Nome do curso correto nos detalhes");
    assertTest($details['cliente_nome'] === 'Sicoob Credisul', "Cliente correto nos detalhes");
    assertTest($details['numero_encontro'] === 1, "Número do encontro é 1");
    assertTest($details['total_encontros'] === 4, "Total de encontros da turma é 4");
    assertTest($details['turno'] === 'V', "Turno Vespertino [V] identificado");
    assertTest(isset($details['turno_config']['letra']) && $details['turno_config']['letra'] === 'V', "Configuração visual acessível do turno presente");
    assertTest($details['conteudo_previsto'] === 'Introdução ao Excel Avançado e PROCV', "Conteúdo previsto carregado fielmente");
    assertTest($details['conteudo_sugerido'] === 'Introdução ao Excel Avançado e PROCV', "Conteúdo sugerido para edição pré-carrega o previsto quando ministrado está nulo");

    // =========================================================================
    // SEÇÃO 2: Lista de Chamada e Alunos
    // =========================================================================
    echo "\n{$amarelo}-> 2. Testando Lista de Chamada e Alunos da Turma...{$reset}\n";

    $attendanceList = $service->getAttendanceList($encontroIds[1]);
    assertTest(count($attendanceList) === 30, "Lista de chamada contém exatamente os 30 alunos matriculados");
    assertTest($attendanceList[0]['aluno_id'] === $alunoIds[0], "ID do primeiro aluno correto");
    assertTest($attendanceList[0]['nome_completo'] === 'Aluno Teste 01', "Nome do aluno correto");
    assertTest(isset($attendanceList[0]['cpf_mascarado']), "CPF mascarado exibido para conferência LGPD");
    assertTest($attendanceList[0]['presente'] === 1, "Presença inicial padrão é 1 (Presente)");
    assertTest(isset($attendanceList[0]['frequencia_acumulada']), "Campo frequencia_acumulada presente");
    assertTest(isset($attendanceList[0]['is_risk']), "Flag is_risk presente");

    // =========================================================================
    // SEÇÃO 3: Costura de Teste 3 — Gravação Transacional Atômica
    // =========================================================================
    echo "\n{$amarelo}-> 3. Testando Costura de Teste 3 (Gravação Transacional Atômica)...{$reset}\n";

    // Simula: 1 clique em Marcar Todos Presentes e 2 faltas desmarcadas (Aluno 10 e Aluno 20)
    $presencasEncontro1 = [];
    foreach ($alunoIds as $aId) {
        $presencasEncontro1[$aId] = 1;
    }
    $presencasEncontro1[$alunoIds[9]] = 0;  // Aluno 10 faltou
    $presencasEncontro1[$alunoIds[19]] = 0; // Aluno 20 faltou

    $conteudoReal1 = "Aula ministrada: Introdução a Fórmulas, PROCV e PROCH com exercícios práticos aplicados ao financeiro.";
    $saveResult = $service->saveAttendance($encontroIds[1], $conteudoReal1, $presencasEncontro1);

    assertTest($saveResult['success'] === true, "saveAttendance retornou sucesso");
    assertTest($saveResult['total_registros'] === 30, "30 registros de presença persistidos");
    assertTest($saveResult['total_presentes'] === 28, "Contador de presentes igual a 28");
    assertTest($saveResult['total_faltas'] === 2, "Contador de faltas igual a 2");

    $stmtCheckEnc = $pdo->prepare("SELECT conteudo_ministrado FROM encontros WHERE id = ?");
    $stmtCheckEnc->execute([$encontroIds[1]]);
    $savedConteudo = $stmtCheckEnc->fetchColumn();
    assertTest($savedConteudo === $conteudoReal1, "Conteúdo ministrado gravado corretamente no banco");

    $stmtCheckFreq = $pdo->prepare("SELECT aluno_id, presente FROM frequencias WHERE encontro_id = ? ORDER BY aluno_id ASC");
    $stmtCheckFreq->execute([$encontroIds[1]]);
    $savedFreqs = $stmtCheckFreq->fetchAll();
    assertTest(count($savedFreqs) === 30, "30 linhas persistidas na tabela frequencias");

    $freqMap = [];
    foreach ($savedFreqs as $row) {
        $freqMap[(int)$row['aluno_id']] = (int)$row['presente'];
    }
    assertTest($freqMap[$alunoIds[0]] === 1, "Aluno 01 com presente = 1 no banco");
    assertTest($freqMap[$alunoIds[9]] === 0, "Aluno 10 com falta (presente = 0) no banco");
    assertTest($freqMap[$alunoIds[19]] === 0, "Aluno 20 com falta (presente = 0) no banco");
    assertTest($freqMap[$alunoIds[29]] === 1, "Aluno 30 com presente = 1 no banco");

    $detailsAtualizado = $service->getEncontroDetails($encontroIds[1]);
    assertTest($detailsAtualizado['conteudo_sugerido'] === $conteudoReal1, "conteudo_sugerido preserva o texto editado pelo professor");

    // =========================================================================
    // SEÇÃO 4: Garantia de Rollback Transacional em Caso de Erro
    // =========================================================================
    echo "\n{$amarelo}-> 4. Testando Garantia de Rollback Transacional...{$reset}\n";

    $rollbackTestSuccess = false;
    $presencasInvalidas = $presencasEncontro1;
    $presencasInvalidas[999999] = 1;

    $conteudoTentativa = "Tentativa corrompida que deve sofrer rollback";
    try {
        $service->saveAttendance($encontroIds[1], $conteudoTentativa, $presencasInvalidas);
    } catch (Throwable $e) {
        $rollbackTestSuccess = true;
    }

    assertTest($rollbackTestSuccess, "saveAttendance lançou exceção com dados inválidos");
    
    $stmtCheckEnc->execute([$encontroIds[1]]);
    assertTest($stmtCheckEnc->fetchColumn() === $conteudoReal1, "Rollback garantiu integridade do conteúdo ministrado sem gravação parcial");

    // =========================================================================
    // SEÇÃO 5: Cálculo de Frequência Acumulada e Detecção de Risco (< 75%)
    // =========================================================================
    echo "\n{$amarelo}-> 5. Testando Frequência Acumulada e Detecção de Risco (< 75%)...{$reset}\n";

    $list1 = $service->getAttendanceList($encontroIds[1]);
    $mapAlunos1 = [];
    foreach ($list1 as $a) {
        $mapAlunos1[$a['aluno_id']] = $a;
    }
    assertTest($mapAlunos1[$alunoIds[0]]['frequencia_acumulada'] === 100.0, "Aluno 01 com 100% de frequência após encontro 1");
    assertTest($mapAlunos1[$alunoIds[0]]['is_risk'] === false, "Aluno 01 com is_risk = false (apto)");
    assertTest($mapAlunos1[$alunoIds[9]]['frequencia_acumulada'] === 0.0, "Aluno 10 com 0% de frequência após encontro 1");
    assertTest($mapAlunos1[$alunoIds[9]]['is_risk'] === true, "Aluno 10 com is_risk = true (em risco)");

    $presencasEncontro2 = [];
    foreach ($alunoIds as $aId) {
        $presencasEncontro2[$aId] = 1;
    }
    $service->saveAttendance($encontroIds[2], "Tabelas Dinâmicas avançadas", $presencasEncontro2);

    $list2 = $service->getAttendanceList($encontroIds[2]);
    $mapAlunos2 = [];
    foreach ($list2 as $a) {
        $mapAlunos2[$a['aluno_id']] = $a;
    }
    assertTest($mapAlunos2[$alunoIds[9]]['frequencia_acumulada'] === 50.0, "Aluno 10 com 50% após 1 presença e 1 falta em 2 encontros");
    assertTest($mapAlunos2[$alunoIds[9]]['is_risk'] === true, "Aluno 10 permanece em risco (50% < 75%)");

    $presencasEncontro3 = [];
    foreach ($alunoIds as $aId) {
        $presencasEncontro3[$aId] = 1;
    }
    $service->saveAttendance($encontroIds[3], "Segmentação de Dashboards", $presencasEncontro3);

    $list3 = $service->getAttendanceList($encontroIds[3]);
    $mapAlunos3 = [];
    foreach ($list3 as $a) {
        $mapAlunos3[$a['aluno_id']] = $a;
    }
    assertTest($mapAlunos3[$alunoIds[9]]['frequencia_acumulada'] === 66.7, "Aluno 10 com 66.7% de frequência acumulada");
    assertTest($mapAlunos3[$alunoIds[9]]['is_risk'] === true, "Aluno 10 permanece em risco (66.7% < 75%)");

    $presencasEncontro4 = [];
    foreach ($alunoIds as $aId) {
        $presencasEncontro4[$aId] = 1;
    }
    $service->saveAttendance($encontroIds[4], "Power Query e Fechamento", $presencasEncontro4);

    $list4 = $service->getAttendanceList($encontroIds[4]);
    $mapAlunos4 = [];
    foreach ($list4 as $a) {
        $mapAlunos4[$a['aluno_id']] = $a;
    }
    assertTest($mapAlunos4[$alunoIds[9]]['frequencia_acumulada'] === 75.0, "Aluno 10 atingiu exatamente 75.0% de frequência acumulada");
    assertTest($mapAlunos4[$alunoIds[9]]['is_risk'] === false, "Aluno 10 superou a zona de risco no 75% exato (is_risk = false)");

    // =========================================================================
    // SEÇÃO 6: Exclusão de Deslocamento e Tratamento de Abono Coletivo
    // =========================================================================
    echo "\n{$amarelo}-> 6. Testando Exclusão de Deslocamento e Abono Coletivo...{$reset}\n";

    $stmtEnc->execute([
        $turmaId, 5, '2026-09-18', '14:00', '18:00',
        'Viagem de retorno', null, 'deslocamento', 0
    ]);
    $encDeslocamentoId = (int)$pdo->lastInsertId();

    $freqsTurma = $service->calculateCumulativeFrequencies($turmaId);
    assertTest($freqsTurma[$alunoIds[0]]['total_aulas'] === 4, "Encontro de deslocamento não é contabilizado como aula");

    $service->abonarEncontro($encontroIds[1], true);
    $freqsAbonadas = $service->calculateCumulativeFrequencies($turmaId);
    assertTest($freqsAbonadas[$alunoIds[9]]['frequencia_acumulada'] === 100.0, "Aula abonada coletivamente garante 100% de presença mesmo para alunos que faltaram");

    // =========================================================================
    // SEÇÃO 7: Interface Web (/diario/aula.php e /diario/turmas.php)
    // =========================================================================
    echo "\n{$amarelo}-> 7. Testando Interface Web e Roteamento Amigável...{$reset}\n";

    $aulaScript = __DIR__ . '/../public/diario/aula.php';
    assertTest(file_exists($aulaScript), "Arquivo public/diario/aula.php existe");

    $turmasScript = __DIR__ . '/../public/diario/turmas.php';
    assertTest(file_exists($turmasScript), "Arquivo public/diario/turmas.php existe");

    $htaccess = file_get_contents(__DIR__ . '/../public/diario/.htaccess');
    assertTest(strpos($htaccess, 'aula.php') !== false, "Regra de roteamento para /diario/aula presente no .htaccess");
    assertTest(strpos($htaccess, 'turmas.php') !== false, "Regra de roteamento para /diario/turmas presente no .htaccess");

    // Simular autenticação administrativa legítima para renderizar a página
    $_SESSION['usuario_admin'] = [
        'id'       => 1,
        'username' => 'admin',
        'nome'     => 'Operador Teste',
        'email'    => 'admin@futurofacil.com.br'
    ];
    $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
    $_GET['encontro_id'] = $encontroIds[2];

    ob_start();
    require $aulaScript;
    $htmlAula = ob_get_clean();

    assertTest(strpos($htmlAula, 'Modo Aula') !== false, "Página contém o título Modo Aula");
    assertTest(strpos($htmlAula, 'Excel Corporativo Especialista') !== false, "Página exibe o nome do curso");
    assertTest(strpos($htmlAula, 'Sicoob Credisul') !== false, "Página exibe o cliente");
    assertTest(strpos($htmlAula, 'Encontro 2 de 4') !== false || strpos($htmlAula, 'Encontro 2') !== false, "Página exibe numeração do encontro");
    assertTest(strpos($htmlAula, 'Marcar Todos Presentes') !== false, "Página contém o botão de 1 toque 'Marcar Todos Presentes'");
    assertTest(strpos($htmlAula, 'conteudo_ministrado') !== false, "Página contém campo de conteúdo ministrado da aula");
    assertTest(strpos($htmlAula, 'Salvar Diário & Chamada') !== false || strpos($htmlAula, 'Salvar') !== false, "Página contém botão de salvar");
    assertTest(strpos($htmlAula, 'csrf_token') !== false, "Formulário protegido por token anti-CSRF");
    assertTest(strpos($htmlAula, 'viewport') !== false, "Página contém meta tag de viewport para mobile");

    // 7.2 Renderização da tela de turmas (/diario/turmas.php)
    unset($_GET['encontro_id']);
    ob_start();
    require $turmasScript;
    $htmlTurmas = ob_get_clean();

    assertTest(strpos($htmlTurmas, 'Diário & Turmas') !== false, "Página de turmas contém o título Diário & Turmas");
    assertTest(strpos($htmlTurmas, 'Excel Corporativo Especialista') !== false, "Página de turmas lista o curso cadastrado");
    assertTest(strpos($htmlTurmas, '/diario/aula?encontro_id=') !== false, "Página de turmas contém link direto para abrir o Modo Aula");

} catch (Throwable $e) {
    echo "\n{$vermelho}Erro fatal durante execução dos testes: " . $e->getMessage() . "{$reset}\n";
    echo $e->getTraceAsString() . "\n";
} finally {
    if (file_exists($testDbPath)) {
        @unlink($testDbPath);
    }
}

echo "\n----------------------------------------------------------------------\n";
if ($passedCount === $totalCount && $totalCount > 0) {
    echo "{$verde}RESULTADO: 100% DE SUCESSO! ({$passedCount}/{$totalCount} verificações executadas sem falhas).\n";
    echo "Ticket 04 (Modo Aula, Costura 3 e Frequência Dinâmica) APROVADO!{$reset}\n";
} else {
    echo "{$vermelho}RESULTADO: {$passedCount} de {$totalCount} verificações passaram. Falhas encontradas.{$reset}\n";
}
echo "----------------------------------------------------------------------\n";

if ($passedCount !== $totalCount || $totalCount === 0) {
    exit(1);
}
