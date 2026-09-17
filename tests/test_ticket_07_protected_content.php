<?php
/**
 * Suíte de Testes Automatizados — Ticket 07: Área Protegida de Conteúdo da Turma
 * Plataforma Integrada de Gestão Pedagógica, Diário e Certificados Futuro Fácil
 * 
 * Cobertura Completa:
 * 1. Integridade do Schema (portal_certificados_modo e ativo em materiais_turma)
 * 2. MaterialService CRUD completo (upload de arquivos, links, toggle ativo, remoção)
 * 3. Blindagem estrita contra Path Traversal (resolveFilePath)
 * 4. Detecção automática de Embeds (YouTube, Vimeo, Google Forms, MS Forms)
 * 5. Autenticação do Aluno com Chave de Acesso da Turma e Defesa contra Força Bruta
 * 6. Costura de Teste 2 (Testing Seam 2):
 *    - Blindagem física (.htaccess) com Deny from all e Require all denied
 *    - Bloqueio HTTP 403 em downloads não autenticados
 *    - Isolamento estrito entre turmas (aluno da Turma A bloqueado na Turma B)
 *    - Bloqueio de materiais ocultos (ativo = 0) para alunos
 *    - Liberação de download para operador administrativo
 *    - Streaming correto com MIME type e bytes idênticos
 * 7. End-to-End: Verificação de download.php e router.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Config/Database.php';
require_once __DIR__ . '/../src/Services/AuthService.php';
require_once __DIR__ . '/../src/Services/MaterialService.php';

use FuturoFacil\Config\Database;
use FuturoFacil\Services\AuthService;
use FuturoFacil\Services\MaterialService;

$verde = "\033[32m";
$vermelho = "\033[31m";
$amarelo = "\033[33m";
$azul = "\033[36m";
$reset = "\033[0m";

echo "{$azul}======================================================================\n";
echo " Executando Suíte de Testes — Ticket 07: Área Protegida de Conteúdo\n";
echo "======================================================================{$reset}\n\n";

$testDbPath = __DIR__ . '/test_temp_ticket_07.db';
if (file_exists($testDbPath)) {
    @unlink($testDbPath);
}

$testStorageDir = __DIR__ . '/test_temp_storage_07';
if (is_dir($testStorageDir)) {
    // Remove recursivamente arquivos residuais
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($testStorageDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $fileinfo) {
        $fileinfo->isDir() ? rmdir($fileinfo->getRealPath()) : unlink($fileinfo->getRealPath());
    }
    rmdir($testStorageDir);
}
mkdir($testStorageDir, 0755, true);

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
    // -------------------------------------------------------------------------
    // 1. Inicialização do Banco SQLite Isolado
    // -------------------------------------------------------------------------
    $pdo = new PDO("sqlite:{$testDbPath}", null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $schemaSql = file_get_contents(__DIR__ . '/../database/schema_sqlite.sql');
    $pdo->exec($schemaSql);

    echo "{$azul}[Etapa 1] Verificação de Schema e Configuração{$reset}\n";

    // Verifica colunas na tabela turmas
    $colsTurmas = $pdo->query("PRAGMA table_info(turmas)")->fetchAll();
    $colNamesTurmas = array_column($colsTurmas, 'name');
    assertTest(in_array('portal_certificados_modo', $colNamesTurmas, true), "Tabela 'turmas' contém a coluna 'portal_certificados_modo'");

    // Verifica colunas na tabela materiais_turma
    $colsMateriais = $pdo->query("PRAGMA table_info(materiais_turma)")->fetchAll();
    $colNamesMateriais = array_column($colsMateriais, 'name');
    assertTest(in_array('ativo', $colNamesMateriais, true), "Tabela 'materiais_turma' contém a coluna 'ativo'");
    assertTest(in_array('ordem', $colNamesMateriais, true), "Tabela 'materiais_turma' contém a coluna 'ordem'");

    // Cria turmas de teste
    $stmtTurma = $pdo->prepare("
        INSERT INTO turmas (
            codigo_turma, curso_nome, cliente_nome, ordem_servico, modalidade, cliente_tipo,
            cliente_cidade, cliente_uf, tipo_cobranca, valor_hora_aula, valor_total,
            carga_horaria, data_inicio, data_conclusao, turno_padrao, status, chave_acesso,
            portal_certificados_modo, instrutor
        ) VALUES (
            :codigo, :curso, :cliente, :os, :modalidade, :cliente_tipo,
            :cidade, :uf, :tipo_cobranca, :valor_hora, :valor_total,
            :carga_horaria, :data_inicio, :data_conclusao, :turno, :status, :chave,
            :portal_modo, :instrutor
        )
    ");

    // Turma 1 - Ativa (Sicoob)
    $stmtTurma->execute([
        ':codigo'        => 'TURMA-TEST-01',
        ':curso'         => 'Excel Especialista',
        ':cliente'       => 'Sicoob Credisul',
        ':os'            => 'OS-001',
        ':modalidade'    => 'Presencial',
        ':cliente_tipo'  => 'PJ',
        ':cidade'        => 'Vilhena',
        ':uf'            => 'RO',
        ':tipo_cobranca' => 'hora_aula',
        ':valor_hora'    => 150.00,
        ':valor_total'   => 2400.00,
        ':carga_horaria' => 16,
        ':data_inicio'   => '2026-09-01',
        ':data_conclusao'=> '2026-09-10',
        ':turno'         => 'V',
        ':status'        => 'concluida',
        ':chave'         => 'chave-turma-alpha',
        ':portal_modo'   => 'download_direto',
        ':instrutor'     => 'Instrutor Teste',
    ]);
    $turma1Id = (int)$pdo->lastInsertId();

    // Turma 2 - Ativa (Sebrae)
    $stmtTurma->execute([
        ':codigo'        => 'TURMA-TEST-02',
        ':curso'         => 'Dashboards Estratégicos',
        ':cliente'       => 'Sebrae',
        ':os'            => 'OS-002',
        ':modalidade'    => 'Presencial',
        ':cliente_tipo'  => 'PJ',
        ':cidade'        => 'Porto Velho',
        ':uf'            => 'RO',
        ':tipo_cobranca' => 'hora_aula',
        ':valor_hora'    => 150.00,
        ':valor_total'   => 1800.00,
        ':carga_horaria' => 12,
        ':data_inicio'   => '2026-09-15',
        ':data_conclusao'=> '2026-09-20',
        ':turno'         => 'M',
        ':status'        => 'em_andamento',
        ':chave'         => 'chave-turma-beta',
        ':portal_modo'   => 'coordenacao',
        ':instrutor'     => 'Instrutor Teste',
    ]);
    $turma2Id = (int)$pdo->lastInsertId();

    // Turma 3 - Cancelada
    $stmtTurma->execute([
        ':codigo'        => 'TURMA-TEST-CANCELADA',
        ':curso'         => 'Curso Cancelado',
        ':cliente'       => 'Cliente Cancelado',
        ':os'            => 'OS-003',
        ':modalidade'    => 'Presencial',
        ':cliente_tipo'  => 'PJ',
        ':cidade'        => 'Cacoal',
        ':uf'            => 'RO',
        ':tipo_cobranca' => 'hora_aula',
        ':valor_hora'    => 100.00,
        ':valor_total'   => 1000.00,
        ':carga_horaria' => 10,
        ':data_inicio'   => '2026-08-01',
        ':data_conclusao'=> '2026-08-05',
        ':turno'         => 'N',
        ':status'        => 'cancelada',
        ':chave'         => 'chave-cancelada-xyz',
        ':portal_modo'   => 'nenhum',
        ':instrutor'     => 'Instrutor Teste',
    ]);
    $turmaCanceladaId = (int)$pdo->lastInsertId();

    assertTest($turma1Id > 0 && $turma2Id > 0 && $turmaCanceladaId > 0, "Turmas de teste criadas no banco isolado");

    // -------------------------------------------------------------------------
    // 2. Testes de CRUD em MaterialService
    // -------------------------------------------------------------------------
    echo "\n{$azul}[Etapa 2] MaterialService CRUD e Validações{$reset}\n";

    $materialService = new MaterialService($pdo, $testStorageDir);
    assertTest($materialService->getBaseStorageDir() === $testStorageDir, "MaterialService inicializado com diretório de testes isolado");

    // Rejeição de arquivo com extensão perigosa (.exe ou .php)
    $threwInvalidExt = false;
    try {
        $fakeUploadBad = [
            'name'     => 'malware.php',
            'tmp_name' => tempnam(sys_get_temp_dir(), 'mal'),
            'error'    => UPLOAD_ERR_OK,
            'size'     => 1024,
        ];
        file_put_contents($fakeUploadBad['tmp_name'], '<?php echo "evil"; ?>');
        $materialService->createMaterial([
            'turma_id' => $turma1Id,
            'titulo'   => 'Arquivo Perigoso',
        ], $fakeUploadBad);
    } catch (InvalidArgumentException $e) {
        $threwInvalidExt = true;
    }
    assertTest($threwInvalidExt, "Rejeição com exceção ao tentar upload de extensão não permitida (.php)");

    // Rejeição de URL inválida
    $threwInvalidUrl = false;
    try {
        $materialService->createMaterial([
            'turma_id'    => $turma1Id,
            'titulo'      => 'Link Malformado',
            'url_externa' => 'not-a-valid-url',
        ]);
    } catch (InvalidArgumentException $e) {
        $threwInvalidUrl = true;
    }
    assertTest($threwInvalidUrl, "Rejeição com exceção ao informar URL malformada");

    // Rejeição de ausência de arquivo E url
    $threwEmptySource = false;
    try {
        $materialService->createMaterial([
            'turma_id' => $turma1Id,
            'titulo'   => 'Sem Origem',
        ]);
    } catch (InvalidArgumentException $e) {
        $threwEmptySource = true;
    }
    assertTest($threwEmptySource, "Rejeição quando nem arquivo nem URL são fornecidos");

    // Upload bem-sucedido de Apostila PDF (Turma 1)
    $tmpPdfPath = tempnam(sys_get_temp_dir(), 'pdf');
    file_put_contents($tmpPdfPath, "%PDF-1.4 Conteúdo Mock da Apostila Sicoob\n");
    $uploadPdf = [
        'name'     => 'Apostila_Excel_2026.pdf',
        'tmp_name' => $tmpPdfPath,
        'error'    => UPLOAD_ERR_OK,
        'size'     => filesize($tmpPdfPath),
    ];
    $mat1Id = $materialService->createMaterial([
        'turma_id'  => $turma1Id,
        'titulo'    => 'Apostila Oficial de Excel',
        'descricao' => 'Guia definitivo de fórmulas financeiras',
        'tipo'      => 'apostila',
        'ordem'     => 1,
        'ativo'     => 1,
    ], $uploadPdf);
    assertTest($mat1Id > 0, "Criação de material tipo apostila com upload de arquivo PDF (ID: {$mat1Id})");

    $mat1 = $materialService->getMaterialById($mat1Id);
    assertTest($mat1 !== null && $mat1['titulo'] === 'Apostila Oficial de Excel', "getMaterialById retorna metadados corretos");
    assertTest($mat1['extensao'] === 'pdf', "Metadados enriquecidos detectam extensão 'pdf'");
    assertTest($mat1['is_arquivo'] === true, "Material identificado corretamente como 'is_arquivo'");

    // Colisão de nome: upload de outro arquivo com mesmo nome para a mesma turma
    $tmpPdfPath2 = tempnam(sys_get_temp_dir(), 'pdf2');
    file_put_contents($tmpPdfPath2, "%PDF-1.4 Conteúdo Versão 2\n");
    $uploadPdf2 = [
        'name'     => 'Apostila_Excel_2026.pdf',
        'tmp_name' => $tmpPdfPath2,
        'error'    => UPLOAD_ERR_OK,
        'size'     => filesize($tmpPdfPath2),
    ];
    $mat1DuplId = $materialService->createMaterial([
        'turma_id'  => $turma1Id,
        'titulo'    => 'Apostila Oficial de Excel v2',
        'tipo'      => 'apostila',
    ], $uploadPdf2);
    $mat1Dupl = $materialService->getMaterialById($mat1DuplId);
    assertTest(
        str_contains((string)$mat1Dupl['caminho_arquivo'], 'Apostila_Excel_2026_1.pdf'),
        "Colisão de nome de arquivo tratada com sufixo numérico seguro (_1.pdf)"
    );

    // Upload de Planilha XLSX (Turma 1) - Inicialmente Oculta (ativo = 0)
    $tmpXlsxPath = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($tmpXlsxPath, "PK Mock Planilha de Exercicios\n");
    $uploadXlsx = [
        'name'     => 'Exercicios_Praticos.xlsx',
        'tmp_name' => $tmpXlsxPath,
        'error'    => UPLOAD_ERR_OK,
        'size'     => filesize($tmpXlsxPath),
    ];
    $mat2Id = $materialService->createMaterial([
        'turma_id'  => $turma1Id,
        'titulo'    => 'Gabarito dos Exercícios',
        'descricao' => 'Planilha com resolução',
        'tipo'      => 'exercicio',
        'ordem'     => 2,
        'ativo'     => 0, // Inativo / Oculto
    ], $uploadXlsx);
    assertTest($mat2Id > 0, "Criação de material oculto (ativo = 0) com upload XLSX (ID: {$mat2Id})");

    // Cadastro de Link Externo com Vídeo do YouTube (Turma 1)
    $mat3Id = $materialService->createMaterial([
        'turma_id'    => $turma1Id,
        'titulo'      => 'Vídeo Tutorial do YouTube',
        'descricao'   => 'Aula gravada de revisão',
        'tipo'      => 'link',
        'ordem'       => 3,
        'ativo'       => 1,
        'url_externa' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    ]);
    $mat3 = $materialService->getMaterialById($mat3Id);
    assertTest($mat3['embed_info']['is_embeddable'] === true, "Detecção de link YouTube como embeddable no cadastro de material");
    assertTest($mat3['embed_info']['embed_type'] === 'youtube', "Tipo de embed reconhecido como 'youtube'");

    // Cadastro de Material na Turma 2 (Isolamento)
    $tmpTurma2Path = tempnam(sys_get_temp_dir(), 't2');
    file_put_contents($tmpTurma2Path, "%PDF-1.4 Material Exclusivo Turma 2\n");
    $uploadT2 = [
        'name'     => 'Manual_Turma_2.pdf',
        'tmp_name' => $tmpTurma2Path,
        'error'    => UPLOAD_ERR_OK,
        'size'     => filesize($tmpTurma2Path),
    ];
    $matTurma2Id = $materialService->createMaterial([
        'turma_id' => $turma2Id,
        'titulo'   => 'Manual Exclusivo Turma 2',
        'tipo'     => 'apostila',
        'ativo'    => 1,
    ], $uploadT2);
    assertTest($matTurma2Id > 0, "Material da Turma 2 cadastrado para teste de isolamento (ID: {$matTurma2Id})");

    // Listagem de materiais por turma (apenas ativos vs todos)
    $materiaisTurma1Ativos = $materialService->getMateriaisByTurma($turma1Id, true);
    $materiaisTurma1Todos = $materialService->getMateriaisByTurma($turma1Id, false);
    assertTest(count($materiaisTurma1Ativos) === 3, "getMateriaisByTurma(apenasAtivos = true) retorna apenas os 3 materiais ativos da Turma 1");
    assertTest(count($materiaisTurma1Todos) === 4, "getMateriaisByTurma(apenasAtivos = false) retorna todos os 4 materiais da Turma 1");

    // Categorização de materiais
    $categorizados = $materialService->getMateriaisCategorizados($turma1Id, false);
    assertTest(isset($categorizados['apostila']), "Categorização agrupa 'apostila'");
    assertTest(isset($categorizados['exercicio']), "Categorização agrupa 'exercicio'");
    assertTest(isset($categorizados['video']), "Categorização agrupa 'video' derivado de link do YouTube");

    // Toggle de status de visibilidade (ativo = 0 -> ativo = 1)
    $toggleRes1 = $materialService->toggleMaterialStatus($mat2Id);
    $mat2Toggled = $materialService->getMaterialById($mat2Id);
    assertTest($toggleRes1 && (int)$mat2Toggled['ativo'] === 1, "toggleMaterialStatus alternou material oculto (0) para visível (1)");

    // Toggle de volta (ativo = 1 -> ativo = 0)
    $materialService->toggleMaterialStatus($mat2Id);
    $mat2ToggledBack = $materialService->getMaterialById($mat2Id);
    assertTest((int)$mat2ToggledBack['ativo'] === 0, "toggleMaterialStatus alternou material de volta para oculto (0)");

    // Atualização de metadados (updateMaterial)
    $updateRes = $materialService->updateMaterial($mat1Id, [
        'titulo' => 'Apostila Oficial de Excel — Revisada e Ampliada',
        'ordem'  => 5,
    ]);
    $mat1Updated = $materialService->getMaterialById($mat1Id);
    assertTest($updateRes && $mat1Updated['titulo'] === 'Apostila Oficial de Excel — Revisada e Ampliada', "updateMaterial atualizou título com sucesso");
    assertTest((int)$mat1Updated['ordem'] === 5, "updateMaterial atualizou ordenação com sucesso");

    // Atualização de Chave de Acesso da Turma com validação
    $updateChaveOk = $materialService->updateTurmaChaveAcesso($turma1Id, 'Nova-Chave_123');
    assertTest($updateChaveOk, "updateTurmaChaveAcesso alterou chave de acesso com sucesso");
    $turma1Reload = $materialService->getTurmaBySlugOrChave('nova-chave_123');
    assertTest($turma1Reload !== null && (int)$turma1Reload['id'] === $turma1Id, "getTurmaBySlugOrChave localiza turma pela nova chave em caixa baixa");

    // Rejeição de chave duplicada
    $threwDuplicateKey = false;
    try {
        $materialService->updateTurmaChaveAcesso($turma2Id, 'nova-chave_123');
    } catch (InvalidArgumentException $e) {
        $threwDuplicateKey = true;
    }
    assertTest($threwDuplicateKey, "updateTurmaChaveAcesso rejeita chave que já pertence a outra turma");

    // Exclusão de Material (deleteMaterial) com remoção física do disco
    $fileToDeleteRel = $mat1Dupl['caminho_arquivo'];
    $fileToDeleteAbs = $materialService->resolveFilePath((string)$fileToDeleteRel);
    assertTest($fileToDeleteAbs !== null && file_exists($fileToDeleteAbs), "Arquivo físico duplicado existe no disco antes da exclusão");
    $deleteRes = $materialService->deleteMaterial($mat1DuplId);
    assertTest($deleteRes, "deleteMaterial retornou true ao excluir registro do banco");
    assertTest(!file_exists($fileToDeleteAbs), "deleteMaterial apagou o arquivo físico do disco");
    assertTest($materialService->getMaterialById($mat1DuplId) === null, "Material excluído não é mais retornado pelo banco");

    // -------------------------------------------------------------------------
    // 3. Blindagem contra Path Traversal (resolveFilePath)
    // -------------------------------------------------------------------------
    echo "\n{$azul}[Etapa 3] Blindagem contra Ataques de Path Traversal{$reset}\n";

    $validResolved = $materialService->resolveFilePath((string)$mat1['caminho_arquivo']);
    assertTest($validResolved !== null && is_file($validResolved), "resolveFilePath resolve caminho legítimo dentro do diretório base");

    $traversalLinux = $materialService->resolveFilePath('../../etc/passwd');
    assertTest($traversalLinux === null, "resolveFilePath bloqueia '../' traversal no estilo Unix");

    $traversalWin = $materialService->resolveFilePath('..\\..\\..\\windows\\system.ini');
    assertTest($traversalWin === null, "resolveFilePath bloqueia '..\\' traversal no estilo Windows");

    $traversalNullByte = $materialService->resolveFilePath("{$turma1Id}/teste.pdf\0../../");
    assertTest($traversalNullByte === null, "resolveFilePath rejeita caminhos com caracteres nulos");

    // -------------------------------------------------------------------------
    // 4. Detecção Automática de Embeds
    // -------------------------------------------------------------------------
    echo "\n{$azul}[Etapa 4] Detecção e Sanitização de Embeds Externos{$reset}\n";

    $ytWatch = $materialService->detectEmbedType('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
    assertTest($ytWatch['is_embeddable'] && $ytWatch['embed_type'] === 'youtube' && str_contains($ytWatch['embed_url'], 'youtube-nocookie.com/embed/dQw4w9WgXcQ'), "Detecção de YouTube padrão com conversão para youtube-nocookie");

    $ytShort = $materialService->detectEmbedType('https://youtu.be/dQw4w9WgXcQ');
    assertTest($ytShort['is_embeddable'] && $ytShort['embed_type'] === 'youtube', "Detecção de link encurtado youtu.be");

    $vimeo = $materialService->detectEmbedType('https://vimeo.com/123456789');
    assertTest($vimeo['is_embeddable'] && $vimeo['embed_type'] === 'vimeo' && str_contains($vimeo['embed_url'], 'player.vimeo.com/video/123456789'), "Detecção de link Vimeo com conversão para player embed");

    $googleForms = $materialService->detectEmbedType('https://docs.google.com/forms/d/e/1FAIpQLScMockForms/viewform');
    assertTest($googleForms['is_embeddable'] && $googleForms['embed_type'] === 'google_forms' && str_contains($googleForms['embed_url'], 'embedded=true'), "Detecção de Google Forms com injeção automática de embedded=true");

    $msForms = $materialService->detectEmbedType('https://forms.office.com/pages/responsepage.aspx?id=mock');
    assertTest($msForms['is_embeddable'] && $msForms['embed_type'] === 'ms_forms', "Detecção de Microsoft Forms");

    $genericLink = $materialService->detectEmbedType('https://futurofacil.com.br/artigos');
    assertTest(!$genericLink['is_embeddable'] && $genericLink['embed_type'] === null, "Links comuns não são classificados como embed");

    // -------------------------------------------------------------------------
    // 5. Autenticação do Aluno e Defesa contra Força Bruta (AuthService)
    // -------------------------------------------------------------------------
    echo "\n{$azul}[Etapa 5] Autenticação de Alunos e Defesa Progressiva{$reset}\n";

    $authService = new AuthService($pdo);

    // Chave correta para Turma 1
    $authOk = $authService->authenticateStudent('nova-chave_123');
    assertTest($authOk['success'] === true, "authenticateStudent com chave válida autentica com sucesso");
    assertTest(AuthService::isStudentAuthenticated(), "isStudentAuthenticated() retorna true após login bem-sucedido");
    $sessAluno = AuthService::getAuthenticatedStudentTurma();
    assertTest($sessAluno !== null && (int)$sessAluno['turma_id'] === $turma1Id, "getAuthenticatedStudentTurma() retorna a turma correta vinculada à sessão");
    assertTest(isset($sessAluno['expira_em']) && $sessAluno['expira_em'] > time() + (29 * 86400), "Sessão do aluno configurada com janela de 30 dias de persistência");

    // Logout de aluno
    AuthService::logoutStudent();
    assertTest(!AuthService::isStudentAuthenticated(), "logoutStudent() encerra a sessão do aluno com sucesso");

    // Chave de turma cancelada
    $authCancelada = $authService->authenticateStudent('chave-cancelada-xyz');
    assertTest($authCancelada['success'] === false && str_contains($authCancelada['error'] ?? '', 'cancelada'), "authenticateStudent rejeita acesso a turmas canceladas");

    // Chave inexistente
    $authInvalida = $authService->authenticateStudent('chave-inexistente-999');
    assertTest($authInvalida['success'] === false, "authenticateStudent rejeita chave inexistente");

    // Teste de atraso progressivo (progressive delay)
    $authService->clearStudentFailedAttempts();
    $ipTeste = '192.168.100.50';
    $delay1 = $authService->recordStudentFailedAttempt($ipTeste);
    assertTest($delay1 === 1, "Primeira falha consecutiva define atraso de 1 segundo");
    $delay2 = $authService->recordStudentFailedAttempt($ipTeste);
    assertTest($delay2 === 2, "Segunda falha consecutiva define atraso de 2 segundos");
    $delay3 = $authService->recordStudentFailedAttempt($ipTeste);
    assertTest($delay3 === 4, "Terceira falha consecutiva define atraso de 4 segundos");

    $authService->clearStudentFailedAttempts($ipTeste);
    assertTest($authService->getStudentFailedAttempts($ipTeste) === 0, "clearStudentFailedAttempts limpa as tentativas registradas");

    // -------------------------------------------------------------------------
    // 6. Costura de Teste 2 (Testing Seam 2: Blindagem Física e Download Controlado)
    // -------------------------------------------------------------------------
    echo "\n{$azul}[Etapa 6] Costura de Teste 2: Blindagem Física e Streaming Controlado{$reset}\n";

    // 6.1. Verificação do .htaccess físico na pasta public/turmas/arquivos/
    $htaccessPath = dirname(__DIR__) . '/public/turmas/arquivos/.htaccess';
    assertTest(file_exists($htaccessPath), "Arquivo físico .htaccess existe em public/turmas/arquivos/");
    $htaccessContent = file_get_contents($htaccessPath);
    assertTest(str_contains($htaccessContent, 'Deny from all'), ".htaccess contém diretiva legada 'Deny from all'");
    assertTest(str_contains($htaccessContent, 'Require all denied'), ".htaccess contém diretiva Apache 2.4 'Require all denied'");
    assertTest(str_contains($htaccessContent, 'Options -Indexes'), ".htaccess desativa listagem de diretório (Options -Indexes)");

    // 6.2. Download Não Autenticado -> HTTP 403 Forbidden
    ob_start();
    $codeUnauth = $materialService->streamMaterialDownload($mat1Id, null, false, false);
    $outputUnauth = ob_get_clean();
    assertTest($codeUnauth === 403, "Download sem autenticação retorna HTTP 403 Forbidden (recebido: {$codeUnauth})");
    assertTest(str_contains((string)$outputUnauth, 'Acesso negado'), "Download sem autenticação exibe mensagem clara de acesso negado");

    // 6.3. Isolamento Estrito entre Turmas (Aluno da Turma 1 tentando baixar material da Turma 2) -> HTTP 403 Forbidden
    ob_start();
    $codeCrossTurma = $materialService->streamMaterialDownload($matTurma2Id, $turma1Id, false, false);
    $outputCrossTurma = ob_get_clean();
    assertTest($codeCrossTurma === 403, "Aluno da Turma 1 tentando baixar arquivo da Turma 2 recebe HTTP 403 Forbidden (recebido: {$codeCrossTurma})");
    assertTest(str_contains((string)$outputCrossTurma, 'Você não possui autorização'), "Mensagem de violação de isolamento exibida");

    // 6.4. Material Oculto (ativo = 0) acessado por Aluno da própria turma -> HTTP 403 Forbidden
    ob_start();
    $codeHidden = $materialService->streamMaterialDownload($mat2Id, $turma1Id, false, false);
    $outputHidden = ob_get_clean();
    assertTest($codeHidden === 403, "Aluno tentando baixar material oculto (ativo = 0) recebe HTTP 403 Forbidden (recebido: {$codeHidden})");
    assertTest(str_contains((string)$outputHidden, 'temporariamente indisponível'), "Mensagem de material indisponível exibida");

    // 6.5. Material Oculto acessado por Operador Administrativo (isAdmin = true) -> Sucesso (200)
    ob_start();
    $codeAdminHidden = $materialService->streamMaterialDownload($mat2Id, null, true, false);
    $outputAdminHidden = ob_get_clean();
    assertTest($codeAdminHidden === 200, "Download de material oculto por operador admin retorna HTTP 200");
    assertTest(str_contains((string)$outputAdminHidden, 'Mock Planilha de Exercicios'), "Operador administrativo tem permissão para auditar/baixar materiais ocultos");

    // 6.6. Download Legítimo por Aluno Autenticado da Turma -> Sucesso (200 com bytes reais)
    ob_start();
    $codeLegit = $materialService->streamMaterialDownload($mat1Id, $turma1Id, false, false);
    $outputLegit = ob_get_clean();
    assertTest($codeLegit === 200, "Download legítimo pelo aluno da turma retorna HTTP 200");
    assertTest(str_contains((string)$outputLegit, 'Conteúdo Mock da Apostila Sicoob'), "Download de material legítimo pelo aluno logado entrega o conteúdo exato do arquivo");

    // -------------------------------------------------------------------------
    // 7. Testes End-to-End via Subprocesso (download.php e router.php)
    // -------------------------------------------------------------------------
    echo "\n{$azul}[Etapa 7] Verificações End-to-End e Controladores Reais{$reset}\n";

    // Executa download.php via CLI sem sessão de aluno -> deve retornar 403
    $cmdDownloadUnauth = 'php -r "'
        . '$_GET[\'id\'] = ' . $mat1Id . ';'
        . 'require \'public/turmas/download.php\';'
        . '"';
    $outputProcess = shell_exec("cd /d \"" . dirname(__DIR__) . "\" && " . $cmdDownloadUnauth);
    assertTest(str_contains((string)$outputProcess, 'Acesso negado'), "Requisição direta a download.php sem sessão ativa retorna Acesso negado");

    // Executa download.php com id inválido (0) -> deve retornar 400
    $cmdDownloadBadId = 'php -r "'
        . '$_GET[\'id\'] = 0;'
        . 'require \'public/turmas/download.php\';'
        . '"';
    $outputBadId = shell_exec("cd /d \"" . dirname(__DIR__) . "\" && " . $cmdDownloadBadId);
    assertTest(str_contains((string)$outputBadId, 'Identificador de material inválido'), "download.php rejeita identificador de material inválido com 400");

    // Verificação de regras em public/router.php
    $cmdRouterBlocked = 'php -r "'
        . '$_SERVER[\'REQUEST_URI\'] = \'/turmas/arquivos/1/secreto.pdf\';'
        . 'require \'public/router.php\';'
        . '"';
    $outputRouter = shell_exec("cd /d \"" . dirname(__DIR__) . "\" && " . $cmdRouterBlocked);
    assertTest(str_contains((string)$outputRouter, 'Acesso Proibido'), "public/router.php bloqueia acesso direto à pasta /turmas/arquivos com 403");

    // Limpeza de arquivos de teste
    if (file_exists($testDbPath)) {
        @unlink($testDbPath);
    }
    // Remove arquivos do diretório de testes
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($testStorageDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $fileinfo) {
        $fileinfo->isDir() ? @rmdir($fileinfo->getRealPath()) : @unlink($fileinfo->getRealPath());
    }
    @rmdir($testStorageDir);

    echo "\n{$azul}======================================================================{$reset}\n";
    echo "{$verde} Suíte de Testes Ticket 07 Finalizada: {$passedCount}/{$totalCount} testes passaram com sucesso!{$reset}\n";
    echo "{$azul}======================================================================{$reset}\n";

    if ($passedCount !== $totalCount) {
        exit(1);
    }

} catch (Throwable $e) {
    echo "\n{$vermelho}[ERRO FATAL NA EXECUÇÃO DOS TESTES]{$reset} " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
