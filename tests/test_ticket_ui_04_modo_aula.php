<?php
/**
 * Suíte de Testes Automatizados — Ticket UI 04
 * Modo Aula Mobile: Chamada Tátil, Plano Dinâmico e Auto-Save (ADR-0006)
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Config/Database.php';
require_once __DIR__ . '/../src/Services/AttendanceService.php';
require_once __DIR__ . '/../src/Services/AuthService.php';

use FuturoFacil\Config\Database;
use FuturoFacil\Services\AttendanceService;
use FuturoFacil\Services\AuthService;

// Todo o script roda dentro de um buffer de saída próprio para que o endpoint
// JSON testado (que usa http_response_code()) nunca veja "headers already
// sent" por causa dos echos de progresso deste executor de testes.
ob_start();

$verde = "\033[32m";
$vermelho = "\033[31m";
$amarelo = "\033[33m";
$azul = "\033[36m";
$reset = "\033[0m";

echo "{$azul}======================================================================\n";
echo " Executando Suíte de Testes — Ticket UI 04: Modo Aula Mobile\n";
echo "======================================================================{$reset}\n\n";

$passou = true;
$totalAsserts = 0;

function afirme(bool $condicao, string $descricao): void
{
    global $passou, $totalAsserts, $verde, $vermelho, $reset;
    $totalAsserts++;
    if ($condicao) {
        echo "  {$verde}✓ [OK]{$reset} {$descricao}\n";
    } else {
        echo "  {$vermelho}✗ [FALHA]{$reset} {$descricao}\n";
        $passou = false;
    }
}

// =============================================================================
// 1. Análise Estática do Modo Aula (public/diario/aula.php) — Design & Ergonomia
// =============================================================================
echo "{$amarelo}-> 1. Análise Estática do Modo Aula: Design System e Ergonomia Tátil...{$reset}\n";

$aulaFile = __DIR__ . '/../public/diario/aula.php';
afirme(file_exists($aulaFile), "Arquivo public/diario/aula.php existe");
$aulaSource = file_get_contents($aulaFile);

afirme(str_contains($aulaSource, '#FAF7F1') || str_contains($aulaSource, '--ff-paper'), "Token institucional papel marfim consolidado (ADR-0006)");
afirme(str_contains($aulaSource, '#1B1918') || str_contains($aulaSource, '--ff-ink'), "Token institucional carvão consolidado (ADR-0006)");
afirme(str_contains($aulaSource, '#0E7490') || str_contains($aulaSource, '--ff-cyan'), "Token institucional Petróleo Tech consolidado (ADR-0006)");
afirme(str_contains($aulaSource, '#E2DFDA') || str_contains($aulaSource, '--ff-line'), "Token institucional de bordas nítidas de 1px consolidado (ADR-0006)");

afirme(str_contains($aulaSource, '#EDF3EC'), "Selo tátil de Presença em verde marfim suave (#EDF3EC) configurado");
afirme(str_contains($aulaSource, '#FDEBEC'), "Selo tátil de Falta em vermelho marfim (#FDEBEC) configurado");
afirme(str_contains($aulaSource, '#FBF3DB'), "Pílula de retenção offline em amarelo marfim (#FBF3DB) configurada");

afirme(str_contains($aulaSource, 'min-height: 52px'), "Linha do aluno com altura mínima de 52px");
afirme(
    preg_match('/\.presenca-controls\s*\{[^}]*gap:\s*0\.5rem/s', $aulaSource) === 1,
    "Espaçamento entre os controles de presença é de ao menos 8px (gap: 0.5rem)"
);
afirme(
    preg_match('/\.btn-toggle\s*\{[^}]*min-width:\s*44px[^}]*min-height:\s*44px/s', $aulaSource) === 1,
    "Alvo de toque do segmented control é de ao menos 44x44px"
);

afirme(str_contains($aulaSource, 'Marcar Todos Presentes'), "Botão de ação em massa 'Marcar Todos Presentes' presente");
afirme(str_contains($aulaSource, 'Em Risco'), "Indicador agregado de alunos em risco (<75%) presente no placar");

// Plano de Aula Dinâmico: card colapsável com resumo de 1 linha
afirme(str_contains($aulaSource, 'id="planoToggle"') && str_contains($aulaSource, 'aria-expanded'), "Cabeçalho do Plano de Aula funciona como controle colapsável acessível (aria-expanded)");
afirme(str_contains($aulaSource, 'id="planoResumo"'), "Resumo de 1 linha do Plano de Aula presente quando colapsado");
afirme(str_contains($aulaSource, 'id="planoBody"') && str_contains($aulaSource, 'class="plano-body"'), "Corpo de edição do Plano de Aula é uma região colapsável independente");
afirme(str_contains($aulaSource, 'id="conteudoMinistrado"'), "Textarea do conteúdo ministrado é referenciável via JS para autosave e resumo dinâmico");

// Auto-Save assíncrono com debounce de 600ms
afirme(str_contains($aulaSource, 'aula_autosave.php'), "Modo Aula consome o endpoint de auto-save assíncrono dedicado");
afirme(
    preg_match('/DEBOUNCE_MS\s*=\s*600/', $aulaSource) === 1,
    "Debounce do auto-save configurado em 600ms"
);
afirme(str_contains($aulaSource, 'Salvando...'), "Indicador de estado 'Salvando...' presente");
afirme(str_contains($aulaSource, "'Salvo no banco às '"), "Indicador de confirmação 'Salvo no banco às HH:MM:SS' presente");
afirme(str_contains($aulaSource, 'id="autosaveStatus"') && str_contains($aulaSource, 'aria-live'), "Pílula de status do auto-save é anunciada via aria-live para leitores de tela");

// Retenção local preventiva e reconexão automática
afirme(str_contains($aulaSource, 'localStorage'), "Mecanismo de retenção local (localStorage) implementado para quedas de conexão");
afirme(str_contains($aulaSource, 'ff_aula_pending_'), "Chave de cache local isolada por encontro para evitar mistura entre aulas");
afirme(str_contains($aulaSource, "addEventListener('online'"), "Reconexão automática escutando o evento 'online' do navegador");
afirme(str_contains($aulaSource, 'scheduleRetry'), "Nova tentativa automática agendada em caso de falha de rede");

// Fallback sem JavaScript
afirme(str_contains($aulaSource, '<noscript>'), "Formulário clássico preservado como fallback via <noscript> para operação sem JavaScript");
afirme(str_contains($aulaSource, 'name="csrf_token"'), "Proteção anti-CSRF mantida no formulário");

// =============================================================================
// 2. Análise Estática do Endpoint de Auto-Save (public/diario/aula_autosave.php)
// =============================================================================
echo "\n{$amarelo}-> 2. Análise Estática do Endpoint de Auto-Save...{$reset}\n";

$autosaveFile = __DIR__ . '/../public/diario/aula_autosave.php';
afirme(file_exists($autosaveFile), "Arquivo public/diario/aula_autosave.php existe");
$autosaveSource = file_get_contents($autosaveFile);

afirme(str_contains($autosaveSource, 'AuthService::isAuthenticated()'), "Endpoint exige sessão administrativa autenticada");
afirme(str_contains($autosaveSource, 'AuthService::validateCsrfToken'), "Endpoint valida token anti-CSRF antes de persistir");
afirme(str_contains($autosaveSource, 'application/json'), "Endpoint responde no formato JSON");
afirme(str_contains($autosaveSource, 'new AttendanceService()') && str_contains($autosaveSource, 'saveAttendance('), "Endpoint reaproveita a gravação transacional atômica de AttendanceService::saveAttendance");
afirme(str_contains($autosaveSource, 'getAttendanceList('), "Endpoint recalcula frequência acumulada e risco por aluno após salvar");

// =============================================================================
// 3. Suíte Funcional: Fixture de Turma, Encontros e Alunos
// =============================================================================
echo "\n{$amarelo}-> 3. Preparando Fixture de Turma para Testes Funcionais...{$reset}\n";

$testDbPath = __DIR__ . '/test_temp_ui04_modo_aula.db';
if (file_exists($testDbPath)) {
    unlink($testDbPath);
}

try {
    // Importante: obtemos o PDO através de Database::getConnection() (e não um
    // "new PDO(...)" isolado) para que este seja exatamente o MESMO handle de
    // conexão que o endpoint aula_autosave.php reutilizará internamente via
    // "new AttendanceService()" (singleton em FuturoFacil\Config\Database).
    // Duas conexões SQLite distintas para o mesmo arquivo, escrevendo em
    // sequência dentro do mesmo teste, produzem "database is locked".
    Database::setConfig([
        'driver'   => 'sqlite',
        'database' => $testDbPath,
    ]);
    $pdo = Database::getConnection();

    $schemaSql = file_get_contents(__DIR__ . '/../database/schema_sqlite.sql');
    $pdo->exec($schemaSql);

    $pdo->exec("
        INSERT INTO turmas (
            codigo_turma, curso_nome, cliente_nome, ordem_servico,
            data_inicio, data_conclusao, turno_padrao, status, chave_acesso,
            carga_horaria, instrutor, cidade, ementa
        ) VALUES (
            'TURMA-UI04-TEST', 'Modo Aula Mobile', 'Sicredi Rio Verde', 'OS-2026-UI04',
            '2026-09-14', '2026-09-21', 'N', 'em_andamento', 'CHAVE-UI04-TEST-2026',
            8, 'Telmo Tropia', 'Rio Verde - GO', 'Chamada tátil e auto-save'
        )
    ");
    $turmaId = (int)$pdo->lastInsertId();

    $stmtEnc = $pdo->prepare("
        INSERT INTO encontros (
            turma_id, numero_encontro, data_encontro, turno, horario_inicio, horario_fim,
            conteudo_previsto, conteudo_ministrado, tipo, abonado
        ) VALUES (?, ?, ?, 'N', '19:00', '22:00', ?, NULL, 'aula', 0)
    ");
    $encontrosData = [
        [1, '2026-09-14', 'Introdução ao Modo Aula'],
        [2, '2026-09-15', 'Chamada tátil e frequência acumulada'],
        [3, '2026-09-16', 'Plano de aula dinâmico'],
        [4, '2026-09-17', 'Auto-save e retenção offline'],
    ];
    $encontroIds = [];
    foreach ($encontrosData as $enc) {
        $stmtEnc->execute([$turmaId, $enc[0], $enc[1], $enc[2]]);
        $encontroIds[$enc[0]] = (int)$pdo->lastInsertId();
    }

    $stmtAluno = $pdo->prepare("
        INSERT INTO alunos (turma_id, nome_completo, cpf, cpf_limpo, cpf_mascarado)
        VALUES (?, ?, ?, ?, ?)
    ");
    $alunoIds = [];
    for ($i = 1; $i <= 8; $i++) {
        $nome = "Aluno UI04 " . str_pad((string)$i, 2, '0', STR_PAD_LEFT);
        $cpfLimpo = str_pad((string)$i, 11, '2', STR_PAD_LEFT);
        $cpfFormatado = substr($cpfLimpo, 0, 3) . '.' . substr($cpfLimpo, 3, 3) . '.' . substr($cpfLimpo, 6, 3) . '-' . substr($cpfLimpo, 9, 2);
        $cpfMascarado = '***.' . substr($cpfLimpo, 3, 3) . '.' . substr($cpfLimpo, 6, 3) . '-**';
        $stmtAluno->execute([$turmaId, $nome, $cpfFormatado, $cpfLimpo, $cpfMascarado]);
        $alunoIds[] = (int)$pdo->lastInsertId();
    }

    afirme(count($alunoIds) === 8, "Fixture com 8 alunos matriculados criada com sucesso");
    afirme(count($encontroIds) === 4, "Fixture com 4 encontros de aula criada com sucesso");

    // Encontro 1: aluno 8 falta (usado depois para forçar risco < 75%)
    $service = new AttendanceService($pdo);
    $presencasEnc1 = [];
    foreach ($alunoIds as $aId) {
        $presencasEnc1[$aId] = 1;
    }
    $presencasEnc1[$alunoIds[7]] = 0;
    $service->saveAttendance($encontroIds[1], 'Conteúdo do encontro 1', $presencasEnc1);

    // Encontro 2: aluno 8 falta novamente (2 faltas em 2 aulas realizadas = risco)
    $presencasEnc2 = $presencasEnc1;
    $service->saveAttendance($encontroIds[2], 'Conteúdo do encontro 2', $presencasEnc2);

    // =========================================================================
    // 4. Suíte Funcional: Round-trip do Auto-Save via Endpoint HTTP
    // =========================================================================
    echo "\n{$amarelo}-> 4. Testando Round-Trip do Endpoint de Auto-Save (aula_autosave.php)...{$reset}\n";

    function simulaRequisicaoAutosave(string $autosaveFile, array $post, array $session): array
    {
        $_POST = $post;
        $_SESSION = $session;
        $_SERVER['REQUEST_METHOD'] = 'POST';

        ob_start();
        require $autosaveFile;
        $output = ob_get_clean();

        return [
            'status' => http_response_code(),
            'body'   => json_decode($output, true),
            'raw'    => $output,
        ];
    }

    $sessaoAutenticada = [
        'usuario_admin' => [
            'id'       => 1,
            'username' => 'admin',
            'nome'     => 'Operador Teste UI04',
            'email'    => 'admin@futurofacil.com.br',
        ],
        'admin_csrf_token' => bin2hex(random_bytes(32)),
    ];

    // 4.1 Requisição não autenticada deve ser recusada com JSON 401 (não redirect)
    $respostaSemAuth = simulaRequisicaoAutosave($autosaveFile, [
        'csrf_token'          => 'qualquer',
        'encontro_id'         => (string)$encontroIds[3],
        'conteudo_ministrado' => 'Tentativa sem sessão',
        'presencas'           => [],
    ], []);
    afirme($respostaSemAuth['status'] === 401, "Requisição sem autenticação retorna HTTP 401");
    afirme(($respostaSemAuth['body']['success'] ?? true) === false, "Resposta sem autenticação indica success=false");
    afirme(($respostaSemAuth['body']['error'] ?? '') === 'auth_required', "Resposta sem autenticação identifica o erro como auth_required");

    // 4.2 CSRF inválido deve ser recusado com JSON 403
    $respostaCsrfInvalido = simulaRequisicaoAutosave($autosaveFile, [
        'csrf_token'          => 'token-forjado-invalido',
        'encontro_id'         => (string)$encontroIds[3],
        'conteudo_ministrado' => 'Tentativa com CSRF inválido',
        'presencas'           => [],
    ], $sessaoAutenticada);
    afirme($respostaCsrfInvalido['status'] === 403, "Requisição com CSRF inválido retorna HTTP 403");
    afirme(($respostaCsrfInvalido['body']['error'] ?? '') === 'csrf_invalid', "Resposta com CSRF inválido identifica o erro como csrf_invalid");

    // 4.3 Auto-save legítimo: marca aluno 8 presente (rompendo a sequência de faltas)
    $presencasEnc3 = [];
    foreach ($alunoIds as $aId) {
        $presencasEnc3[(string)$aId] = '1';
    }
    $conteudoEnc3 = 'Plano de aula ministrado no encontro 3 via auto-save.';

    $respostaSucesso = simulaRequisicaoAutosave($autosaveFile, [
        'csrf_token'          => $sessaoAutenticada['admin_csrf_token'],
        'encontro_id'         => (string)$encontroIds[3],
        'conteudo_ministrado' => $conteudoEnc3,
        'presencas'           => $presencasEnc3,
        'abonado'             => '0',
    ], $sessaoAutenticada);

    afirme($respostaSucesso['status'] === 200, "Auto-save legítimo retorna HTTP 200");
    afirme(($respostaSucesso['body']['success'] ?? false) === true, "Auto-save legítimo retorna success=true");
    afirme((int)($respostaSucesso['body']['encontro_id'] ?? 0) === $encontroIds[3], "Resposta do auto-save referencia o encontro correto");
    afirme(
        preg_match('/^\d{2}:\d{2}:\d{2}$/', (string)($respostaSucesso['body']['saved_at'] ?? '')) === 1,
        "Resposta do auto-save inclui horário de gravação no formato HH:MM:SS"
    );
    afirme((int)($respostaSucesso['body']['total_presentes'] ?? 0) === 8, "Auto-save contabiliza 8 presentes após marcar todos presentes");
    afirme((int)($respostaSucesso['body']['total_faltas'] ?? -1) === 0, "Auto-save contabiliza 0 faltas após marcar todos presentes");
    afirme(is_array($respostaSucesso['body']['students'] ?? null) && count($respostaSucesso['body']['students']) === 8, "Auto-save devolve frequência recalculada para os 8 alunos");

    // Verifica persistência real no banco (mesmo caminho transacional do formulário clássico)
    $stmtVerifica = $pdo->prepare("SELECT conteudo_ministrado FROM encontros WHERE id = ?");
    $stmtVerifica->execute([$encontroIds[3]]);
    afirme($stmtVerifica->fetchColumn() === $conteudoEnc3, "Conteúdo ministrado enviado via auto-save foi persistido no banco de dados");

    $stmtFreqAluno8 = null;
    foreach ($respostaSucesso['body']['students'] as $alunoResp) {
        if ($alunoResp['aluno_id'] === $alunoIds[7]) {
            $stmtFreqAluno8 = $alunoResp;
            break;
        }
    }
    afirme($stmtFreqAluno8 !== null, "Aluno com histórico de faltas está presente na resposta do auto-save");
    afirme(
        $stmtFreqAluno8 !== null && (float)$stmtFreqAluno8['frequencia_acumulada'] < 100.0,
        "Frequência acumulada do aluno com faltas anteriores reflete o histórico real (<100%)"
    );

    // =========================================================================
    // 5. Suíte Funcional: Detecção de Risco (<75%) refletida pelo Auto-Save
    // =========================================================================
    echo "\n{$amarelo}-> 5. Testando Sinalização de Risco (<75%) via Auto-Save...{$reset}\n";

    // Encontro 4: aluno 8 falta de novo (3 faltas em 4 aulas => 25% de frequência, abaixo de 75%)
    $presencasEnc4 = [];
    foreach ($alunoIds as $aId) {
        $presencasEnc4[(string)$aId] = '1';
    }
    $presencasEnc4[(string)$alunoIds[7]] = '0';

    $respostaRisco = simulaRequisicaoAutosave($autosaveFile, [
        'csrf_token'          => $sessaoAutenticada['admin_csrf_token'],
        'encontro_id'         => (string)$encontroIds[4],
        'conteudo_ministrado' => 'Encontro 4: consolidação de risco de evasão.',
        'presencas'           => $presencasEnc4,
        'abonado'             => '0',
    ], $sessaoAutenticada);

    afirme($respostaRisco['status'] === 200, "Auto-save do encontro com faltas acumuladas retorna HTTP 200");

    $aluno8Risco = null;
    foreach ($respostaRisco['body']['students'] as $alunoResp) {
        if ($alunoResp['aluno_id'] === $alunoIds[7]) {
            $aluno8Risco = $alunoResp;
            break;
        }
    }
    afirme($aluno8Risco !== null && $aluno8Risco['is_risk'] === true, "Aluno com frequência abaixo de 75% é sinalizado como is_risk=true pelo auto-save");
    afirme((int)($respostaRisco['body']['total_risco'] ?? -1) >= 1, "Contador agregado total_risco reflete ao menos 1 aluno em risco");

    echo "\n----------------------------------------------------------------------\n";
    if ($passou) {
        echo "{$verde}RESULTADO: {$totalAsserts}/{$totalAsserts} asserções aprovadas (100%){$reset}\n";
        echo "{$verde}Suíte do Ticket UI 04 APROVADA COM SUCESSO!{$reset}\n";
    } else {
        echo "{$vermelho}RESULTADO: Falha em uma ou mais asserções. Verifique os erros acima.{$reset}\n";
    }
    echo "----------------------------------------------------------------------\n";
} catch (Throwable $e) {
    echo "\n{$vermelho}Erro fatal durante execução dos testes: " . $e->getMessage() . "{$reset}\n";
    echo $e->getTraceAsString() . "\n";
    $passou = false;
} finally {
    if (file_exists($testDbPath)) {
        @unlink($testDbPath);
    }
}

$bufferedOutput = ob_get_clean();
echo $bufferedOutput;

if (!$passou) {
    exit(1);
}
