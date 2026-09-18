<?php
/**
 * Suíte de Testes Automatizados — Ticket 12: Portal do Aluno — QR Code Projetável no Telão, Auto-Cadastro, Presença Automática e Governança de Correções
 * Cobertura TDD:
 *  - Costura 1: Gerador Vetorial de QR Code SVG puro e Data URI (QRCodeGenerator)
 *  - Costura 2: Rate Limiting de Auto-Acesso e Sessão do Aluno (AuthService)
 *  - Costura 3: Reconciliação Inteligente de Alunos e Presença Automática (TurmaService)
 *  - Costura 4: Governança de Correções Cadastrais (TurmaService)
 *  - Costura 5: Soberania do Instrutor e Sincronização Retroativa com Certificados (TurmaService)
 *  - Costura 6: Disparo Transacional de Chave de Acesso (TransactionalMailService)
 *  - Costura 7: Rotas HTTP e Integridade de Arquivos (router.php, entrar.php, qrcode.php)
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../src/Config/Database.php';
require_once __DIR__ . '/../src/Services/CalendarService.php';
require_once __DIR__ . '/../src/Services/TurmaService.php';
require_once __DIR__ . '/../src/Services/AuthService.php';
require_once __DIR__ . '/../src/Services/AttendanceService.php';
require_once __DIR__ . '/../src/Services/TransactionalMailService.php';
require_once __DIR__ . '/../src/Utils/QRCodeGenerator.php';

use FuturoFacil\Config\Database;
use FuturoFacil\Services\CalendarService;
use FuturoFacil\Services\TurmaService;
use FuturoFacil\Services\AuthService;
use FuturoFacil\Services\AttendanceService;
use FuturoFacil\Services\TransactionalMailService;
use FuturoFacil\Utils\QRCodeGenerator;

$verde = "\033[32m";
$vermelho = "\033[31m";
$amarelo = "\033[33m";
$azul = "\033[36m";
$reset = "\033[0m";

echo "{$azul}======================================================================\n";
echo " Executando Suíte de Testes — Ticket 12: Telão QR Code, Auto-Acesso & Governança\n";
echo "======================================================================{$reset}\n\n";

$testDbPath = __DIR__ . '/test_temp_ticket_12.db';
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
    // 1. Inicializa banco SQLite isolado com schema
    Database::setConfig([
        'driver'   => 'sqlite',
        'database' => $testDbPath,
    ]);
    $pdo = Database::getConnection();
    $schemaSql = file_get_contents(__DIR__ . '/../database/schema_sqlite.sql');
    $pdo->exec($schemaSql);

    $calendarService = new CalendarService($pdo);
    $turmaService = new TurmaService($pdo, $calendarService);
    $authService = new AuthService($pdo);
    $attendanceService = new AttendanceService($pdo);

    $turmaService->ensureSchema();

    // =========================================================================
    // COSTURA 1: Gerador de QR Code Vetorial SVG Puro (QRCodeGenerator)
    // =========================================================================
    echo "\n{$amarelo}-> 1. Testando QRCodeGenerator (SVG Puro e Data URI)...{$reset}\n";

    $testUrl = "https://futurofacil.com.br/turmas/entrar?turma=excel-2026-demo&cpf=1&wpp=1";
    $svg = QRCodeGenerator::generateSvg($testUrl, 360);

    assertTest(str_contains($svg, '<svg'), "generateSvg retorna tag <svg>");
    assertTest(str_contains($svg, 'viewBox="0 0'), "generateSvg inclui atributo viewBox escalável");
    assertTest(str_contains($svg, 'shape-rendering="crispEdges"'), "generateSvg inclui renderização nítida crispEdges");
    assertTest(str_contains($svg, '<rect'), "generateSvg contém elementos <rect> que formam a matriz QR");

    $dataUri = QRCodeGenerator::generateDataUri($testUrl, 200);
    assertTest(str_starts_with($dataUri, 'data:image/svg+xml;base64,'), "generateDataUri retorna prefixo data:image/svg+xml;base64,");
    $decodedSvg = base64_decode(substr($dataUri, strlen('data:image/svg+xml;base64,')));
    assertTest(str_contains($decodedSvg, '<svg'), "generateDataUri decodifica para SVG válido");

    // =========================================================================
    // COSTURA 2: Rate Limiting e Sessão do Aluno (AuthService)
    // =========================================================================
    echo "\n{$amarelo}-> 2. Testando Rate Limiting e Gestão de Sessão do Aluno...{$reset}\n";

    $testIp = "192.168.1.50";
    // Limite de 3 requisições por 10 minutos
    assertTest(AuthService::checkStudentAccessRateLimit($testIp, 3, 600) === true, "1ª tentativa permitida");
    assertTest(AuthService::checkStudentAccessRateLimit($testIp, 3, 600) === true, "2ª tentativa permitida");
    assertTest(AuthService::checkStudentAccessRateLimit($testIp, 3, 600) === true, "3ª tentativa permitida");
    assertTest(AuthService::checkStudentAccessRateLimit($testIp, 3, 600) === false, "4ª tentativa bloqueada pelo rate-limiting (retorna false)");

    $otherIp = "192.168.1.51";
    assertTest(AuthService::checkStudentAccessRateLimit($otherIp, 3, 600) === true, "IP diferente é permitido imediatamente sem interferência");

    // Autenticação com enriquecimento de dados do aluno
    $turmaExemploId = (int)$turmaService->createTurma([
        'curso_nome'    => 'Power BI Essencial',
        'cliente_nome'  => 'Cooperativa Alfa',
        'instrutor'     => 'Tel Santana Leite',
        'cidade'        => 'Goiânia - GO',
        'data_inicio'   => '2026-10-01',
        'data_conclusao'=> '2026-10-05',
        'carga_horaria' => 20,
        'chave_acesso'  => 'pbi-alfa-2026',
    ]);

    $stmtTurma = $pdo->prepare("SELECT * FROM turmas WHERE id = ?");
    $stmtTurma->execute([$turmaExemploId]);
    $turmaExemplo = $stmtTurma->fetch(PDO::FETCH_ASSOC);

    $loginRes = $authService->authenticateStudent('pbi-alfa-2026', $turmaExemplo['codigo_turma'], [
        'aluno_id'       => 101,
        'aluno_nome'     => 'Carlos Henrique Silva',
        'aluno_cpf'      => '123.456.789-00',
        'aluno_email'    => 'carlos@alfa.com.br',
        'aluno_telefone' => '(62) 98765-4321',
    ]);

    assertTest($loginRes['success'] === true, "authenticateStudent com dados de aluno retorna sucesso");
    assertTest(AuthService::isStudentAuthenticated() === true, "isStudentAuthenticated retorna true");
    $sessAluno = AuthService::getAuthenticatedStudentTurma();
    assertTest($sessAluno['aluno_id'] === 101, "Sessão armazena aluno_id = 101");
    assertTest($sessAluno['aluno_nome'] === 'Carlos Henrique Silva', "Sessão armazena aluno_nome");
    assertTest($sessAluno['aluno_email'] === 'carlos@alfa.com.br', "Sessão armazena aluno_email");

    // Atualização dinâmica dos dados na sessão
    AuthService::updateAuthenticatedStudentData([
        'aluno_nome' => 'Carlos H. Silva Sauro',
    ]);
    $sessAtualizada = AuthService::getAuthenticatedStudentTurma();
    assertTest($sessAtualizada['aluno_nome'] === 'Carlos H. Silva Sauro', "updateAuthenticatedStudentData atualiza campo na sessão ativa");

    // =========================================================================
    // COSTURA 3: Reconciliação Inteligente de Alunos e Presença Automática
    // =========================================================================
    echo "\n{$amarelo}-> 3. Testando Reconciliação de Alunos e Presença Automática...{$reset}\n";

    // Criar encontro pedagógico para a turma
    $stmtEnc = $pdo->prepare("
        INSERT INTO encontros (turma_id, numero_encontro, data_encontro, turno, horario_inicio, horario_fim, tipo)
        VALUES (?, 1, '2026-10-01', 'M', '08:00', '12:00', 'aula')
    ");
    $stmtEnc->execute([$turmaExemploId]);
    $encontroId = (int)$pdo->lastInsertId();

    // Cenário A: Cadastro novo de aluno com CPF, Email, Telefone e Presença
    $res1 = $turmaService->reconcileOrRegisterStudent(
        $turmaExemploId,
        'Lucas Mendes Ribeiro',
        'lucas@empresa.com.br',
        '529.982.247-25',
        '(62) 99111-2222',
        $encontroId
    );
    $aluno1Id = (int)$res1['aluno_id'];

    assertTest($aluno1Id > 0, "reconcileOrRegisterStudent cadastra novo aluno com sucesso (ID: {$aluno1Id})");

    $stmtCheckAluno = $pdo->prepare("SELECT * FROM alunos WHERE id = ?");
    $stmtCheckAluno->execute([$aluno1Id]);
    $aluno1 = $stmtCheckAluno->fetch(PDO::FETCH_ASSOC);

    assertTest($aluno1['nome_completo'] === 'Lucas Mendes Ribeiro', "Nome completo salvo com exatidão");
    assertTest($aluno1['cpf_limpo'] === '52998224725', "CPF limpo higienizado com 11 dígitos");
    assertTest($aluno1['email'] === 'lucas@empresa.com.br', "Email do aluno persistido na coluna 'email'");
    assertTest($aluno1['telefone'] === '(62) 99111-2222', "Telefone do aluno persistido na coluna 'telefone'");

    // Verificar se a presença no encontro foi marcada
    $stmtFreq = $pdo->prepare("SELECT presente FROM frequencias WHERE encontro_id = ? AND aluno_id = ?");
    $stmtFreq->execute([$encontroId, $aluno1Id]);
    $freq = $stmtFreq->fetch(PDO::FETCH_ASSOC);
    assertTest(!empty($freq) && (int)$freq['presente'] === 1, "Presença automática gravada com presente = 1");

    // Cenário B: Reconciliação por CPF (mesmo aluno entrando novamente, enriquece dados sem duplicar)
    $resB = $turmaService->reconcileOrRegisterStudent(
        $turmaExemploId,
        'Lucas Mendes Ribeiro',
        'lucas.novo@empresa.com.br',
        '52998224725', // CPF sem pontuação
        '(62) 99999-8888',
        null
    );
    $aluno1ReconciliadoCpf = (int)$resB['aluno_id'];
    assertTest($aluno1ReconciliadoCpf === $aluno1Id, "Reconciliação por CPF encontra o registro existente e não duplica");

    // Cenário C: Reconciliação por Email
    // Cria aluno apenas com nome e email
    $stmtAlunoSemCpf = $pdo->prepare("INSERT INTO alunos (turma_id, nome_completo, email) VALUES (?, 'Mariana Costa', 'mariana@empresa.com.br')");
    $stmtAlunoSemCpf->execute([$turmaExemploId]);
    $marianaId = (int)$pdo->lastInsertId();

    $resC = $turmaService->reconcileOrRegisterStudent(
        $turmaExemploId,
        'Mariana da Costa',
        'mariana@empresa.com.br',
        '111.444.777-35',
        '(62) 97777-1111',
        null
    );
    $marianaReconciliada = (int)$resC['aluno_id'];
    assertTest($marianaReconciliada === $marianaId, "Reconciliação por Email encontra registro sem CPF anterior");

    $stmtCheckMariana = $pdo->prepare("SELECT * FROM alunos WHERE id = ?");
    $stmtCheckMariana->execute([$marianaId]);
    $marianaAtualizada = $stmtCheckMariana->fetch(PDO::FETCH_ASSOC);
    assertTest($marianaAtualizada['cpf_limpo'] === '11144477735', "Reconciliação enriqueceu CPF do aluno previamente sem CPF");

    // Cenário D: Reconciliação por Nome Exato
    $stmtAlunoSemCpfEmail = $pdo->prepare("INSERT INTO alunos (turma_id, nome_completo) VALUES (?, 'Roberto Andrade Silva')");
    $stmtAlunoSemCpfEmail->execute([$turmaExemploId]);
    $robertoId = (int)$pdo->lastInsertId();

    $resD = $turmaService->reconcileOrRegisterStudent(
        $turmaExemploId,
        'roberto andrade silva', // Caixa baixa
        'roberto@empresa.com.br',
        '123.456.789-09',
        '(62) 96666-2222',
        null
    );
    $robertoReconciliado = (int)$resD['aluno_id'];
    assertTest($robertoReconciliado === $robertoId, "Reconciliação por Nome (case-insensitive) encontra aluno cadastrado via planilha");

    // =========================================================================
    // COSTURA 4: Governança de Correções Cadastrais (Solicitação, Diff e Aprovação)
    // =========================================================================
    echo "\n{$amarelo}-> 4. Testando Ciclo de Correções Cadastrais do Aluno...{$reset}\n";

    // Criação de solicitação de correção pelo aluno
    $reqId = $turmaService->createCorrectionRequest(
        $turmaExemploId,
        $aluno1Id,
        'Lucas Mendes Ribeiro de Souza',
        '529.982.247-25',
        '(62) 99111-9999',
        'Casamento e alteração de sobrenome familiar'
    );

    assertTest($reqId > 0, "createCorrectionRequest cria registro de solicitação pendente (ID: {$reqId})");

    $pendingReqs = $turmaService->getPendingCorrectionRequests($turmaExemploId);
    assertTest(count($pendingReqs) === 1, "getPendingCorrectionRequests retorna exatamente 1 solicitação pendente");
    assertTest($pendingReqs[0]['nome_proposto'] === 'Lucas Mendes Ribeiro de Souza', "Solicitação contém nome_proposto correto");
    assertTest($pendingReqs[0]['aluno_nome_atual'] === 'Lucas Mendes Ribeiro', "Solicitação une aluno_nome_atual via INNER JOIN");

    // Aprovação da solicitação pelo instrutor
    $resAprovacao = $turmaService->resolveCorrectionRequest($reqId, true, 'Documento de certidão validado');
    assertTest($resAprovacao === true, "resolveCorrectionRequest(..., true) retorna true");

    $stmtCheckAluno1Aprovado = $pdo->prepare("SELECT * FROM alunos WHERE id = ?");
    $stmtCheckAluno1Aprovado->execute([$aluno1Id]);
    $aluno1Aprovado = $stmtCheckAluno1Aprovado->fetch(PDO::FETCH_ASSOC);
    assertTest($aluno1Aprovado['nome_completo'] === 'Lucas Mendes Ribeiro de Souza', "Aprovação atualizou imediatamente o nome_completo do aluno na tabela 'alunos'");

    $pendingDepois = $turmaService->getPendingCorrectionRequests($turmaExemploId);
    assertTest(count($pendingDepois) === 0, "Solicitação processada não aparece mais na lista de pendências");

    // Rejeição de solicitação
    $req2Id = $turmaService->createCorrectionRequest(
        $turmaExemploId,
        $aluno1Id,
        'Nome Fantasioso',
        null,
        null,
        'Apelido pessoal'
    );
    $turmaService->resolveCorrectionRequest($req2Id, false, 'Apenas nomes civis oficiais são aceitos');

    $stmtCheckAluno1Recusado = $pdo->prepare("SELECT * FROM alunos WHERE id = ?");
    $stmtCheckAluno1Recusado->execute([$aluno1Id]);
    $aluno1Recusado = $stmtCheckAluno1Recusado->fetch(PDO::FETCH_ASSOC);
    assertTest($aluno1Recusado['nome_completo'] === 'Lucas Mendes Ribeiro de Souza', "Recusa NÃO altera o cadastro do aluno");

    // =========================================================================
    // COSTURA 5: Soberania do Instrutor & Sincronização Retroativa de Certificados
    // =========================================================================
    echo "\n{$amarelo}-> 5. Testando Edição Direta do Instrutor e Sincronização com Certificados...{$reset}\n";

    // Cria um registro de certificado emitido para Lucas
    $stmtCert = $pdo->prepare("
        INSERT INTO registros_certificados (turma_id, aluno_nome, aluno_cpf, codigo_autenticidade, curso_nome, carga_horaria, data_conclusao, data_emissao, livro_numero, folha_numero, registro_numero)
        VALUES (?, 'Lucas Mendes Ribeiro de Souza', '529.***.***-25', 'CERT-VALID-12345', 'Power BI Essencial', 20, '2026-10-05', '2026-10-05', 1, 1, 1)
    ");
    $stmtCert->execute([$turmaExemploId]);
    $certId = (int)$pdo->lastInsertId();

    // Instrutor corrige diretamente no diário de turma
    $turmaService->updateAluno($aluno1Id, [
        'nome_completo' => 'Lucas Mendes R. de Souza (Oficial)',
        'cpf'           => '52998224725',
        'email'         => 'lucas.oficial@empresa.com.br',
        'telefone'      => '(62) 99111-9999',
    ]);

    // Verifica que o aluno foi atualizado
    $stmtAlunoUp = $pdo->prepare("SELECT nome_completo FROM alunos WHERE id = ?");
    $stmtAlunoUp->execute([$aluno1Id]);
    assertTest($stmtAlunoUp->fetchColumn() === 'Lucas Mendes R. de Souza (Oficial)', "updateAluno atualiza tabela 'alunos'");

    // Verifica que o certificado emitido foi sincronizado retroativamente para reemissão
    $stmtCertUp = $pdo->prepare("SELECT aluno_nome FROM registros_certificados WHERE id = ?");
    $stmtCertUp->execute([$certId]);
    assertTest($stmtCertUp->fetchColumn() === 'Lucas Mendes R. de Souza (Oficial)', "updateAluno sincroniza retroativamente com 'registros_certificados'");

    // =========================================================================
    // COSTURA 6: Disparo de E-mail Transacional com Chave de Acesso
    // =========================================================================
    echo "\n{$amarelo}-> 6. Testando Disparo Transacional de Chave de Acesso...{$reset}\n";

    $mailService = new TransactionalMailService($pdo, true); // modo de teste
    $emailEnviado = $mailService->sendStudentAccessEmail(
        'Lucas Mendes R. de Souza',
        'lucas.oficial@empresa.com.br',
        'Power BI Essencial',
        'pbi-alfa-2026',
        'https://futurofacil.com.br/turmas/pbi-alfa-2026?chave=pbi-alfa-2026'
    );

    assertTest(!empty($emailEnviado['success']), "sendStudentAccessEmail retorna success => true em testMode");
    $sentMails = $mailService->getSentMails();
    assertTest(count($sentMails) === 1, "getSentMails registra 1 e-mail armazenado");
    assertTest($sentMails[0]['to'] === 'lucas.oficial@empresa.com.br', "Destinatário correto registrado");
    assertTest(str_contains($sentMails[0]['subject'], 'Chave de Acesso'), "Assunto contém 'Chave de Acesso'");
    assertTest(str_contains($sentMails[0]['body_html'], 'pbi-alfa-2026'), "Corpo HTML contém a chave de acesso");
    assertTest(str_contains($sentMails[0]['body_html'], 'https://futurofacil.com.br/turmas/pbi-alfa-2026?chave=pbi-alfa-2026'), "Corpo HTML contém o link de acesso direto");

    // Validação ADR-0006: Zero emojis nos templates de e-mail
    $hasEmoji = preg_match('/[\x{1F600}-\x{1F64F}|\x{1F300}-\x{1F5FF}|\x{1F680}-\x{1F6FF}|\x{2600}-\x{26FF}|\x{2700}-\x{27BF}]/u', $sentMails[0]['body_html']);
    assertTest($hasEmoji === 0, "Corpo do e-mail cumpre estritamente ADR-0006 (Zero emojis)");

    // =========================================================================
    // COSTURA 7: Rotas HTTP e Integridade de Arquivos
    // =========================================================================
    echo "\n{$amarelo}-> 7. Testando Rotas HTTP e Integridade de Arquivos...{$reset}\n";

    $routerContent = file_get_contents(__DIR__ . '/../public/router.php');
    assertTest(str_contains($routerContent, '/turmas/entrar'), "public/router.php roteia '/turmas/entrar'");
    assertTest(str_contains($routerContent, '/diario/'), "public/router.php possui despachante dinâmico para submódulos do diário (incluindo qrcode)");

    assertTest(file_exists(__DIR__ . '/../public/turmas/entrar.php'), "Arquivo public/turmas/entrar.php existe");
    assertTest(file_exists(__DIR__ . '/../public/diario/qrcode.php'), "Arquivo public/diario/qrcode.php existe");
    assertTest(file_exists(__DIR__ . '/../src/Utils/QRCodeGenerator.php'), "Arquivo src/Utils/QRCodeGenerator.php existe");
    assertTest(file_exists(__DIR__ . '/../src/Services/TransactionalMailService.php'), "Arquivo src/Services/TransactionalMailService.php existe");

} catch (Throwable $e) {
    echo "\n{$vermelho}Exceção Fatal durante a execução dos testes: {$e->getMessage()}{$reset}\n";
    echo $e->getTraceAsString() . "\n";
} finally {
    if (file_exists($testDbPath)) {
        @unlink($testDbPath);
    }
}

echo "\n{$azul}======================================================================\n";
if ($passedCount === $totalCount && $totalCount > 0) {
    echo " {$verde}TODOS OS {$totalCount} TESTES DO TICKET 12 PASSARAM COM SUCESSO!{$reset}\n";
} else {
    echo " {$vermelho}{$passedCount} DE {$totalCount} TESTES PASSARAM. REVISE AS FALHAS.{$reset}\n";
}
echo "======================================================================{$reset}\n";

exit($passedCount === $totalCount ? 0 : 1);
