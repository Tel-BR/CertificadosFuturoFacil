<?php
/**
 * Suíte de Testes Automatizados — Ticket 06: Fechamento Assistido, Emissão de Certificados Duplex e Pacote ZIP
 * Plataforma Integrada de Gestão Pedagógica, Diário e Certificados Futuro Fácil
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Config/Database.php';
require_once __DIR__ . '/../src/Services/ValidatorService.php';
require_once __DIR__ . '/../src/Services/AttendanceService.php';
require_once __DIR__ . '/../src/Services/CalendarService.php';
require_once __DIR__ . '/../src/Services/CertificatePdfService.php';
require_once __DIR__ . '/../src/Services/CertificateService.php';
require_once __DIR__ . '/../src/Services/AuthService.php';

use FuturoFacil\Config\Database;
use FuturoFacil\Services\ValidatorService;
use FuturoFacil\Services\AttendanceService;
use FuturoFacil\Services\CalendarService;
use FuturoFacil\Services\CertificatePdfService;
use FuturoFacil\Services\CertificateService;

$verde = "\033[32m";
$vermelho = "\033[31m";
$amarelo = "\033[33m";
$azul = "\033[36m";
$reset = "\033[0m";

echo "{$azul}======================================================================\n";
echo " Executando Suíte de Testes — Ticket 06: Fechamento Assistido e Emissão ZIP\n";
echo "======================================================================{$reset}\n\n";

$testDbPath = __DIR__ . '/test_temp_closure.db';
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

    $certificateService = new CertificateService($pdo);
    $validatorService = new ValidatorService($pdo);
    $pdfService = new CertificatePdfService();

    // -------------------------------------------------------------------------
    // Cenário de Teste: Criação de Turma, Encontros e Alunos
    // -------------------------------------------------------------------------
    $stmtTurma = $pdo->prepare("
        INSERT INTO turmas (
            codigo_turma, curso_nome, cliente_nome, ordem_servico, modalidade,
            cliente_tipo, cliente_cidade, cliente_uf, cliente_cnpj,
            tipo_cobranca, valor_hora_aula, valor_total, carga_horaria,
            data_inicio, data_conclusao, turno_padrao, status, chave_acesso,
            instrutor, cidade, ementa
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?
        )
    ");
    $stmtTurma->execute([
        'TURMA-EXCEL-FECHAMENTO-2026',
        'Excel Intermediário e Avançado para Gestão',
        'Sicoob CrediGoiás',
        'OS-2026-9901',
        'Presencial',
        'PJ',
        'Goiânia',
        'GO',
        '01.234.567/0001-89',
        'hora_aula',
        180.00,
        3600.00,
        20,
        '2026-10-05',
        '2026-10-28',
        'V',
        'em_andamento',
        'CHAVE-FECHAMENTO-06',
        'Tel Santana Leite',
        'Goiânia',
        "1. Fórmulas Lógicas e de Pesquisa (PROCV, ÍNDICE, CORRESP, PROCX);\n2. Tabelas Dinâmicas e Segmentação de Dados;\n3. Automação com Power Query e Introdução a Macros."
    ]);
    $turmaId = (int)$pdo->lastInsertId();

    // Cria 4 Encontros de 5h cada = 20h total
    $stmtEnc = $pdo->prepare("
        INSERT INTO encontros (turma_id, numero_encontro, data_encontro, turno, tipo, abonado, conteudo_ministrado)
        VALUES (?, ?, ?, 'V', 'aula', ?, ?)
    ");
    $stmtEnc->execute([$turmaId, 1, '2026-10-05', 0, 'Aula 1: Fórmulas Lógicas']);
    $enc1Id = (int)$pdo->lastInsertId();

    $stmtEnc->execute([$turmaId, 2, '2026-10-12', 0, 'Aula 2: Pesquisa Avançada']);
    $enc2Id = (int)$pdo->lastInsertId();

    $stmtEnc->execute([$turmaId, 3, '2026-10-19', 0, 'Aula 3: Tabelas Dinâmicas']);
    $enc3Id = (int)$pdo->lastInsertId();

    $stmtEnc->execute([$turmaId, 4, '2026-10-26', 0, 'Aula 4: Power Query e Avaliação']);
    $enc4Id = (int)$pdo->lastInsertId();

    // Cria 10 Alunos
    $stmtAluno = $pdo->prepare("
        INSERT INTO alunos (turma_id, nome_completo, cpf, cpf_limpo, cpf_mascarado, justificado)
        VALUES (?, ?, ?, ?, ?, 0)
    ");

    $alunosIds = [];
    for ($i = 1; $i <= 10; $i++) {
        $nome = sprintf("Aluno de Teste %02d", $i);
        $cpf = sprintf("111.222.333-%02d", $i);
        $cpfLimpo = sprintf("111222333%02d", $i);
        $cpfMasc = sprintf("***.222.333-%02d", $i);
        $stmtAluno->execute([$turmaId, $nome, $cpf, $cpfLimpo, $cpfMasc]);
        $alunosIds[$i] = (int)$pdo->lastInsertId();
    }

    // Configura presenças para que:
    // Alunos 1 a 7 tenham >= 75% (Aptos)
    // Aluno 8 tenha 50% (2 presenças em 4 aulas) -> Inapto
    // Aluno 9 tenha 50% (2 presenças em 4 aulas) -> Inapto
    // Aluno 10 tenha 25% (1 presença em 4 aulas) -> Inapto
    $stmtFreq = $pdo->prepare("INSERT INTO frequencias (encontro_id, aluno_id, presente) VALUES (?, ?, ?)");

    // Alunos 1 a 7: 100% de presença (4 presenças)
    for ($i = 1; $i <= 7; $i++) {
        $stmtFreq->execute([$enc1Id, $alunosIds[$i], 1]);
        $stmtFreq->execute([$enc2Id, $alunosIds[$i], 1]);
        $stmtFreq->execute([$enc3Id, $alunosIds[$i], 1]);
        $stmtFreq->execute([$enc4Id, $alunosIds[$i], 1]);
    }

    // Aluno 8: faltou aulas 2 e 3 (50%)
    $stmtFreq->execute([$enc1Id, $alunosIds[8], 1]);
    $stmtFreq->execute([$enc2Id, $alunosIds[8], 0]);
    $stmtFreq->execute([$enc3Id, $alunosIds[8], 0]);
    $stmtFreq->execute([$enc4Id, $alunosIds[8], 1]);

    // Aluno 9: faltou aulas 3 e 4 (50%)
    $stmtFreq->execute([$enc1Id, $alunosIds[9], 1]);
    $stmtFreq->execute([$enc2Id, $alunosIds[9], 1]);
    $stmtFreq->execute([$enc3Id, $alunosIds[9], 0]);
    $stmtFreq->execute([$enc4Id, $alunosIds[9], 0]);

    // Aluno 10: faltou aulas 2, 3 e 4 (25%)
    $stmtFreq->execute([$enc1Id, $alunosIds[10], 1]);
    $stmtFreq->execute([$enc2Id, $alunosIds[10], 0]);
    $stmtFreq->execute([$enc3Id, $alunosIds[10], 0]);
    $stmtFreq->execute([$enc4Id, $alunosIds[10], 0]);

    // =========================================================================
    // 1. Testando Separação Auditada de Alunos Aptos e Inaptos
    // =========================================================================
    echo "-> 1. Testando Separação Auditada de Alunos Aptos (>= 75%) e Inaptos (< 75%)...\n";
    $dadosFechamento = $certificateService->getTurmaFechamentoData($turmaId);

    assertTest($dadosFechamento['total_alunos'] === 10, "Total de alunos auditados é exatamente 10");
    assertTest(count($dadosFechamento['alunos_aptos']) === 7, "Exatamente 7 alunos classificados como Aptos (>= 75%)");
    assertTest(count($dadosFechamento['alunos_inaptos']) === 3, "Exatamente 3 alunos classificados como Inaptos (< 75%)");
    assertTest($dadosFechamento['total_aptos'] === 7, "total_aptos confere");
    assertTest($dadosFechamento['total_inaptos'] === 3, "total_inaptos confere");
    assertTest($dadosFechamento['aproveitamento_percentual'] === 70.0, "Aproveitamento inicial da turma é de 70.0%");
    assertTest($dadosFechamento['alunos_aptos'][0]['frequencia'] === 100.0, "Primeiro aluno apto com 100% de frequência");
    assertTest($dadosFechamento['alunos_inaptos'][0]['frequencia'] === 50.0, "Aluno 8 inapto com 50% de frequência");
    assertTest($dadosFechamento['alunos_inaptos'][2]['frequencia'] === 25.0, "Aluno 10 inapto com 25% de frequência");

    // =========================================================================
    // 2. Testando Abono Coletivo de Aula Pontual
    // =========================================================================
    echo "\n-> 2. Testando Abono Coletivo de Aula Pontual (1-toque com recálculo instantâneo)...\n";
    // Abonar o Encontro 2 (onde Aluno 8 e 10 faltaram)
    $resAbono = $certificateService->abonarAulaColetiva($enc2Id, true);
    assertTest($resAbono === true, "Abono do Encontro 2 executado com sucesso");

    $dadosPosAbono = $certificateService->getTurmaFechamentoData($turmaId);
    // Com o encontro 2 abonado:
    // Aluno 8 agora tem 3 presenças em 4 aulas = 75.0% -> torna-se Apto!
    // Aluno 9 continua com 50% (faltou 3 e 4) -> permanece Inapto
    // Aluno 10 agora tem 2 presenças em 4 aulas = 50.0% -> permanece Inapto
    assertTest(count($dadosPosAbono['alunos_aptos']) === 8, "Após abono da aula 2, Aluno 8 atingiu 75% e foi promovido a Apto");
    assertTest(count($dadosPosAbono['alunos_inaptos']) === 2, "Inaptos reduzidos para 2");
    assertTest($dadosPosAbono['aproveitamento_percentual'] === 80.0, "Aproveitamento subiu para 80.0%");

    // Revoga abono para validar retorno consistente
    $certificateService->abonarAulaColetiva($enc2Id, false);
    $dadosRevogados = $certificateService->getTurmaFechamentoData($turmaId);
    assertTest(count($dadosRevogados['alunos_aptos']) === 7, "Revogação do abono retornou aptos para 7");

    // =========================================================================
    // 3. Testando Justificativa Individual Excepcional
    // =========================================================================
    echo "\n-> 3. Testando Justificativa Individual Excepcional Deliberada pela Coordenação...\n";
    $aluno8Id = $alunosIds[8];
    $motivo = "Atestado médico de 3 dias homologado pela gerência de RH do Sicoob.";
    $resJust = $certificateService->justificarAluno($aluno8Id, $motivo);
    assertTest($resJust === true, "Justificativa excepcional registrada com sucesso para o Aluno 8");

    $dadosPosJust = $certificateService->getTurmaFechamentoData($turmaId);
    assertTest(count($dadosPosJust['alunos_aptos']) === 8, "Aluno 8 com justificativa extraordinária promovido para a lista de Aptos");
    assertTest(count($dadosPosJust['alunos_inaptos']) === 2, "Lista de inaptos agora possui apenas 2 alunos");

    // Procura o Aluno 8 na lista de aptos
    $aluno8Fechamento = null;
    foreach ($dadosPosJust['alunos_aptos'] as $apto) {
        if ($apto['id'] === $aluno8Id) {
            $aluno8Fechamento = $apto;
            break;
        }
    }
    assertTest($aluno8Fechamento !== null, "Aluno 8 localizado na lista de aptos");
    assertTest($aluno8Fechamento['is_justificado'] === true, "Flag is_justificado marcada como true");
    assertTest($aluno8Fechamento['justificativa_texto'] === $motivo, "Texto do motivo da justificativa preservado fielmente");
    assertTest(str_contains($aluno8Fechamento['motivo_aprovacao'], 'Justificativa Extraordinária'), "Motivo de aprovação reflete a deliberação formal");

    // Testa remoção e reinclusão de justificativa
    $certificateService->removerJustificativa($aluno8Id);
    $dadosPosRemocao = $certificateService->getTurmaFechamentoData($turmaId);
    assertTest(count($dadosPosRemocao['alunos_aptos']) === 7, "Remoção de justificativa devolveu o aluno para inaptos");

    // Re-aplica justificativa para o Aluno 8 para que ele seja certificado na emissão formal
    $certificateService->justificarAluno($aluno8Id, $motivo);

    // =========================================================================
    // 4. Testando Sequenciamento Atômico do Livro de Registro Digital
    // =========================================================================
    echo "\n-> 4. Testando Sequenciamento Atômico Notarial (Livro, Folha e Registro)...\n";
    // Insere registros históricos fictícios simulando o fechamento do lote Sicoob (Livro 1, Folha 40, Registro 40)
    $stmtHist = $pdo->prepare("
        INSERT INTO registros_certificados (
            codigo_autenticidade, aluno_nome, curso_nome, carga_horaria, data_conclusao, data_emissao,
            livro_numero, folha_numero, registro_numero
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmtHist->execute([
        'HASH_HISTORICO_39', 'Wisliane Lacir', 'Excel', 20, '2026-09-08', '2026-09-08', 1, 39, 39
    ]);
    $stmtHist->execute([
        'HASH_HISTORICO_40', 'Yago Romário Santos Costa', 'Excel', 20, '2026-09-08', '2026-09-08', 1, 40, 40
    ]);

    $nextSeq = $certificateService->getNextSequenceNumbers();
    assertTest($nextSeq['livro_numero'] === 1, "Próximo Livro é o Livro 1");
    assertTest($nextSeq['folha_numero'] === 41, "Próxima Folha é a Folha 41 (sequência exata após o Sicoob)");
    assertTest($nextSeq['registro_numero'] === 41, "Próximo Registro é o Registro 41");

    // Testando regra de virada de livro ao alcançar 100 folhas:
    $stmtHist->execute([
        'HASH_VIRADA_TESTE', 'Aluno Limite 100', 'Excel', 20, '2026-09-08', '2026-09-08', 1, 100, 100
    ]);
    $nextSeqVirada = $certificateService->getNextSequenceNumbers();
    assertTest($nextSeqVirada['livro_numero'] === 2, "Virada de Livro: após Folha 100, próximo Livro é o Livro 2");
    assertTest($nextSeqVirada['folha_numero'] === 1, "Virada de Livro: próxima Folha reinicia em 1");
    assertTest($nextSeqVirada['registro_numero'] === 101, "Virada de Livro: registro continua estritamente ininterrupto (101)");

    // Limpa o registro temporário de virada para manter a sequência real em 41
    $pdo->exec("DELETE FROM registros_certificados WHERE codigo_autenticidade = 'HASH_VIRADA_TESTE'");

    // =========================================================================
    // 5. Testando Apoio ao Faturamento e Discriminação de Serviços para NFS-e
    // =========================================================================
    echo "\n-> 5. Testando Apoio ao Faturamento e Discriminação de Serviços para NFS-e...\n";
    $nfse = $certificateService->generateNfseDescription($turmaId);

    assertTest(!empty($nfse), "Texto de discriminação de serviços gerado com sucesso");
    assertTest(str_contains($nfse, 'OS-2026-9901'), "Discriminação contém a Ordem de Serviço da turma");
    assertTest(str_contains($nfse, 'Excel Intermediário e Avançado para Gestão'), "Discriminação contém o nome do curso");
    assertTest(str_contains($nfse, 'Presencial'), "Discriminação contém a modalidade");
    assertTest(str_contains($nfse, '20 horas'), "Discriminação contém a carga horária total");
    assertTest(str_contains($nfse, '5h/dia'), "Discriminação contém a carga horária diária (20h / 4 dias = 5h/dia)");
    assertTest(str_contains($nfse, '05/10/2026, 12/10/2026, 19/10/2026 e 26/10/2026'), "Discriminação lista dinamicamente todas as datas dos encontros");
    assertTest(str_contains($nfse, 'Sicoob CrediGoiás'), "Discriminação contém o nome do tomador");
    assertTest(str_contains($nfse, '01.234.567/0001-89'), "Discriminação contém o CNPJ do tomador");
    assertTest(str_contains($nfse, 'Goiânia-GO'), "Discriminação contém o município de execução");
    assertTest(str_contains($nfse, 'Tel Santana Leite'), "Discriminação contém o instrutor");
    assertTest(str_contains($nfse, 'R$ 3.600,00'), "Discriminação contém o valor total calculado (20h x R$ 180,00)");

    // =========================================================================
    // 6. Testando Emissão Oficial Atômica e Geração do Pacote ZIP
    // =========================================================================
    echo "\n-> 6. Testando Emissão Oficial Atômica e Geração do Pacote ZIP...\n";
    $resEmissao = $certificateService->emitirCertificadosTurma($turmaId, ['data_emissao' => '2026-10-28']);

    assertTest($resEmissao['success'] === true, "Emissão de certificados executada com sucesso");
    assertTest($resEmissao['total_emitidos'] === 8, "Exatamente 8 certificados emitidos (7 regulares + 1 justificado)");
    assertTest(!empty($resEmissao['zip_filename']), "Nome de arquivo ZIP gerado");
    assertTest(str_contains($resEmissao['zip_filename'], '.zip'), "Extensão do arquivo é .zip");
    assertTest(strlen($resEmissao['zip_bytes']) > 5000, "Arquivo ZIP binário gerado com tamanho consistente (> 5KB)");

    // Checa se o status da turma foi atualizado para 'concluida'
    $stmtStatus = $pdo->prepare("SELECT status FROM turmas WHERE id = ?");
    $stmtStatus->execute([$turmaId]);
    $statusAtual = $stmtStatus->fetchColumn();
    assertTest($statusAtual === 'concluida', "Status da turma atualizado atomicamente para 'concluida'");

    // Checa se os assentos no banco de dados seguiram a sequência ininterrupta
    $stmtAssentos = $pdo->prepare("
        SELECT livro_numero, folha_numero, registro_numero, codigo_autenticidade, aluno_nome
        FROM registros_certificados
        WHERE turma_id = ?
        ORDER BY registro_numero ASC
    ");
    $stmtAssentos->execute([$turmaId]);
    $assentos = $stmtAssentos->fetchAll(PDO::FETCH_ASSOC);

    assertTest(count($assentos) === 8, "Exatamente 8 registros gravados na tabela registros_certificados");
    assertTest((int)$assentos[0]['livro_numero'] === 1, "Primeiro certificado no Livro 1");
    assertTest((int)$assentos[0]['folha_numero'] === 41, "Primeiro certificado na Folha 41");
    assertTest((int)$assentos[0]['registro_numero'] === 41, "Primeiro certificado no Registro 41");
    assertTest((int)$assentos[7]['folha_numero'] === 48, "Último certificado na Folha 48");
    assertTest((int)$assentos[7]['registro_numero'] === 48, "Último certificado no Registro 48");

    // =========================================================================
    // 7. Testando Conformidade de Validação Pública (/validar)
    // =========================================================================
    echo "\n-> 7. Testando Validação Pública Imediata dos Certificados Recém-Emitidos...\n";
    foreach ($assentos as $assento) {
        $hash = $assento['codigo_autenticidade'];
        assertTest(strlen($hash) === 64, "Hash SHA-256 possui exatamente 64 caracteres");
        assertTest(ctype_xdigit($hash), "Hash SHA-256 é estritamente hexadecimal");

        $valResult = $validatorService->validarCodigo($hash);
        assertTest($valResult['autentico'] === true, "Hash {$assento['registro_numero']} validou perfeitamente como autêntico");
        assertTest(str_contains($valResult['aluno_cpf_mascarado'], '***.'), "CPF mascarado pela LGPD retornado com sucesso");
    }

    // =========================================================================
    // 8. Testando Conteúdo e Integridade do Pacote ZIP
    // =========================================================================
    echo "\n-> 8. Testando Conteúdo e Integridade do Pacote ZIP...\n";
    $zipTempFile = sys_get_temp_dir() . '/test_verify_' . uniqid() . '.zip';
    file_put_contents($zipTempFile, $resEmissao['zip_bytes']);

    $zip = new ZipArchive();
    $openRes = $zip->open($zipTempFile);
    assertTest($openRes === true, "Pacote ZIP pode ser aberto perfeitamente");

    $namelist = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $namelist[] = $zip->getNameIndex($i);
    }

    // Deve conter:
    // 1. livro_registro_certificados.xlsx
    // 2. certificados_consolidado_grafica.pdf
    // 3. certificados_individuais/certificado_...
    assertTest(in_array("livro_registro_certificados.xlsx", $namelist, true), "ZIP contém a planilha 'livro_registro_certificados.xlsx'");
    assertTest(in_array("certificados_consolidado_grafica.pdf", $namelist, true), "ZIP contém o PDF 'certificados_consolidado_grafica.pdf'");

    $pdfIndCount = 0;
    foreach ($namelist as $fname) {
        if (str_starts_with($fname, "certificados_individuais/certificado_") && str_ends_with($fname, ".pdf")) {
            $pdfIndCount++;
        }
    }
    assertTest($pdfIndCount === 8, "ZIP contém exatamente os 8 PDFs de certificados individuais");

    // Testa se o PDF consolidado possui 2 * N = 16 páginas
    $consolidatedBytes = $zip->getFromName("certificados_consolidado_grafica.pdf");
    assertTest(!empty($consolidatedBytes), "PDF consolidado extraído com sucesso do ZIP");

    $tempConsPdf = sys_get_temp_dir() . '/temp_cons_' . uniqid() . '.pdf';
    file_put_contents($tempConsPdf, $consolidatedBytes);

    $safePath = str_replace('\\', '/', $tempConsPdf);
    $cmdPy = "python -c \"import pypdf; r = pypdf.PdfReader('{$safePath}'); print(len(r.pages)); assert len(r.pages) == 16;\"";
    $pyOutput = trim((string)shell_exec($cmdPy));
    assertTest($pyOutput === "16", "PDF consolidado para gráfica contém estritamente 16 páginas (2 * 8 alunos)");
    @unlink($tempConsPdf);

    // Testa se a planilha do Livro de Registro dentro do ZIP é válida
    $excelBytes = $zip->getFromName("livro_registro_certificados.xlsx");
    assertTest(strlen($excelBytes) > 1000, "Planilha do Livro de Registro extraída com sucesso do ZIP (> 1KB)");
    assertTest(str_starts_with($excelBytes, "PK"), "Planilha possui assinatura válida de arquivo OpenXML / ZIP (PK)");

    $zip->close();
    @unlink($zipTempFile);

    // =========================================================================
    // 9. Testando Interface Web e Roteamento Amigável (/diario/fechamento)
    // =========================================================================
    echo "\n-> 9. Testando Interface Web e Roteamento Amigável...\n";
    $fechamentoPath = __DIR__ . '/../public/diario/fechamento.php';
    assertTest(file_exists($fechamentoPath), "Arquivo public/diario/fechamento.php existe");

    $htaccessContent = file_get_contents(__DIR__ . '/../public/diario/.htaccess');
    assertTest(str_contains($htaccessContent, 'fechamento'), "Regra de roteamento para /diario/fechamento configurada no .htaccess");

    $fechamentoContent = file_get_contents($fechamentoPath);
    assertTest(str_contains($fechamentoContent, 'Fechamento Assistido'), "Página contém o título 'Fechamento Assistido'");
    assertTest(str_contains($fechamentoContent, 'Alunos Aptos'), "Página contém a seção de Alunos Aptos");
    assertTest(str_contains($fechamentoContent, 'Alunos Inaptos'), "Página contém a seção de Alunos Inaptos");
    assertTest(str_contains($fechamentoContent, 'Abonar Aula Coletiva'), "Página contém a ferramenta 'Abonar Aula Coletiva'");
    assertTest(str_contains($fechamentoContent, 'Apoio ao Faturamento'), "Página contém a seção de Apoio ao Faturamento");
    assertTest(str_contains($fechamentoContent, 'Copiar Descrição para NFS-e'), "Página contém o botão de 1 clique 'Copiar Descrição para NFS-e'");
    assertTest(str_contains($fechamentoContent, 'Confirmar e Emitir Certificados'), "Página contém o botão de emissão 'Confirmar e Emitir Certificados'");
    assertTest(str_contains($fechamentoContent, 'csrf_token'), "Formulários protegidos por token anti-CSRF");

} catch (Throwable $e) {
    echo "{$vermelho}ERRO INESPERADO NA SUÍTE DE TESTES: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "{$reset}\n";
    exit(1);
} finally {
    if (file_exists($testDbPath)) {
        @unlink($testDbPath);
    }
}

echo "\n----------------------------------------------------------------------\n";
if ($passedCount === $totalCount && $totalCount > 0) {
    echo "{$verde}RESULTADO: 100% DE SUCESSO! ({$passedCount}/{$totalCount} verificações executadas sem falhas).\n";
    echo "Ticket 06 (Fechamento Assistido, Emissão Duplex e Pacote ZIP) APROVADO!{$reset}\n";
} else {
    echo "{$vermelho}RESULTADO: {$passedCount} DE {$totalCount} TESTES PASSARAM. REVISE AS FALHAS!{$reset}\n";
    exit(1);
}
echo "----------------------------------------------------------------------\n\n";
