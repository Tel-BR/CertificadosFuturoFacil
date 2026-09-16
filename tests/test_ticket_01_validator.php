<?php
/**
 * Suíte de Testes Automatizados — Costura de Teste 1 (Testing Seam 1)
 * Garantia de Não-Regressão e Conformidade LGPD da Validação Pública
 * 
 * Verifica 100% dos certificados históricos reais da turma Sicoob
 * contra a nova camada de validação da plataforma.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Config/Database.php';
require_once __DIR__ . '/../src/Services/ValidatorService.php';

use FuturoFacil\Config\Database;
use FuturoFacil\Services\ValidatorService;

$verde = "\033[32m";
$vermelho = "\033[31m";
$amarelo = "\033[33m";
$azul = "\033[36m";
$reset = "\033[0m";

echo "{$azul}======================================================================\n";
echo " Executando Costura de Teste 1: Validação Pública e Não-Regressão LGPD\n";
echo "======================================================================{$reset}\n\n";

$sourceDb = __DIR__ . '/../registros.db';
if (!file_exists($sourceDb)) {
    echo "{$vermelho}[FALHA CRÍTICA] registros.db original não encontrado em {$sourceDb}{$reset}\n";
    exit(1);
}

// 1. Configurar banco SQLite em arquivo temporário para testes
$tempDbPath = __DIR__ . '/test_temp_validator.db';
if (file_exists($tempDbPath)) {
    unlink($tempDbPath);
}

try {
    $testPdo = new PDO("sqlite:{$tempDbPath}", null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Aplica schema SQLite
    $schemaSql = file_get_contents(__DIR__ . '/../database/schema_sqlite.sql');
    $testPdo->exec($schemaSql);

    // Carrega registros originais do registros.db
    $sourcePdo = new PDO("sqlite:{$sourceDb}", null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $stmtOrig = $sourcePdo->query("SELECT * FROM registros_certificados ORDER BY id ASC");
    $certificadosOriginais = $stmtOrig->fetchAll();

    $insertStmt = $testPdo->prepare("
        INSERT INTO registros_certificados (
            codigo_autenticidade, aluno_nome, aluno_cpf, aluno_cpf_mascarado,
            curso_nome, carga_horaria, carga_horaria_extenso, data_inicio,
            data_conclusao, data_emissao, modalidade, instrutor, cidade,
            ementa, livro_numero, folha_numero, registro_numero, frequencia,
            lote_id, presencas_detalhadas, horas_presentes, horas_totais, created_at
        ) VALUES (
            :codigo_autenticidade, :aluno_nome, :aluno_cpf, :aluno_cpf_mascarado,
            :curso_nome, :carga_horaria, :carga_horaria_extenso, :data_inicio,
            :data_conclusao, :data_emissao, :modalidade, :instrutor, :cidade,
            :ementa, :livro_numero, :folha_numero, :registro_numero, :frequencia,
            :lote_id, :presencas_detalhadas, :horas_presentes, :horas_totais, :created_at
        )
    ");

    foreach ($certificadosOriginais as $c) {
        $insertStmt->execute([
            ':codigo_autenticidade'  => strtoupper(trim($c['codigo_autenticidade'])),
            ':aluno_nome'            => $c['aluno_nome'],
            ':aluno_cpf'             => $c['aluno_cpf'],
            ':aluno_cpf_mascarado'   => $c['aluno_cpf_mascarado'],
            ':curso_nome'            => $c['curso_nome'],
            ':carga_horaria'         => $c['carga_horaria'],
            ':carga_horaria_extenso' => $c['carga_horaria_extenso'],
            ':data_inicio'           => $c['data_inicio'],
            ':data_conclusao'        => $c['data_conclusao'],
            ':data_emissao'          => $c['data_emissao'],
            ':modalidade'            => $c['modalidade'] ?? 'Presencial',
            ':instrutor'             => $c['instrutor'],
            ':cidade'                => $c['cidade'],
            ':ementa'                => $c['ementa'],
            ':livro_numero'          => $c['livro_numero'],
            ':folha_numero'          => $c['folha_numero'],
            ':registro_numero'       => $c['registro_numero'],
            ':frequencia'            => $c['frequencia'] ?? 100,
            ':lote_id'               => $c['lote_id'],
            ':presencas_detalhadas'  => $c['presencas_detalhadas'],
            ':horas_presentes'       => $c['horas_presentes'],
            ':horas_totais'          => $c['horas_totais'],
            ':created_at'            => $c['created_at'],
        ]);
    }

    // Configura o singleton Database para usar a base de teste
    Database::setConfig([
        'driver'   => 'sqlite',
        'database' => $tempDbPath,
    ]);

    $validator = new ValidatorService();

    $testesExecutados = 0;
    $falhas = 0;

    echo "-> Testando consulta dos " . count($certificadosOriginais) . " certificados reais importados...\n\n";

    foreach ($certificadosOriginais as $idx => $orig) {
        $testesExecutados++;
        $codigoOriginal = strtoupper(trim($orig['codigo_autenticidade']));
        
        // Validação via serviço
        $resultado = $validator->validarCodigo($codigoOriginal);

        // 1. Status de autenticidade
        if ($resultado['autentico'] !== true) {
            echo "{$vermelho}[FALHA] Certificado #{$orig['registro_numero']} ({$orig['aluno_nome']}) não retornou autêntico!{$reset}\n";
            $falhas++;
            continue;
        }

        // 2. Integridade do hash
        if ($resultado['codigo_autenticidade'] !== $codigoOriginal) {
            echo "{$vermelho}[FALHA] Hash retornado difere do original!{$reset}\n";
            $falhas++;
            continue;
        }

        // 3. Mascaramento LGPD do CPF (***.XXX.XXX-**)
        $cpfMascarado = $resultado['aluno_cpf_mascarado'];
        if (!preg_match('/^\*\*\*\.\d{3}\.\d{3}-\*\*$/', $cpfMascarado)) {
            echo "{$vermelho}[FALHA LGPD] CPF não está no formato mascarado exigido: '{$cpfMascarado}'{$reset}\n";
            $falhas++;
            continue;
        }

        // 4. Garantia de que o CPF original completo NÃO está exposto em nenhum lugar
        $cpfLimpo = preg_replace('/\D/', '', (string)$orig['aluno_cpf']);
        if (!empty($cpfLimpo) && strlen($cpfLimpo) === 11) {
            foreach ($resultado as $chave => $valor) {
                if (is_string($valor) && str_contains($valor, $cpfLimpo)) {
                    echo "{$vermelho}[VAZAMENTO LGPD] CPF sem máscara vazou no campo '{$chave}'!{$reset}\n";
                    $falhas++;
                    break 2;
                }
            }
        }

        // 5. Mascaramento LGPD do Nome do Aluno
        $nomeMascarado = $resultado['aluno_nome_mascarado'];
        if (!str_contains($nomeMascarado, '*')) {
            echo "{$vermelho}[FALHA LGPD] Nome do aluno não possui asteriscos de máscara: '{$nomeMascarado}'{$reset}\n";
            $falhas++;
            continue;
        }

        // 6. Integridade de livro/folha/registro
        if ($resultado['livro_numero'] !== (int)$orig['livro_numero'] ||
            $resultado['folha_numero'] !== (int)$orig['folha_numero'] ||
            $resultado['registro_numero'] !== (int)$orig['registro_numero']) {
            echo "{$vermelho}[FALHA SEQ] Sequenciamento de registro divergente!{$reset}\n";
            $falhas++;
            continue;
        }

        echo "  {$verde}✓ [OK]{$reset} Reg #{$orig['registro_numero']}: {$resultado['aluno_nome_mascarado']} | CPF: {$resultado['aluno_cpf_mascarado']} | L{$resultado['livro_numero']} F{$resultado['folha_numero']} R{$resultado['registro_numero']}\n";
    }

    // 7. Casos de Borda e Segurança
    echo "\n-> Testando Casos de Borda e Ataques...\n";

    // Borda 1: Hash inexistente (mas com 64 hexadecimais válidos)
    $testesExecutados++;
    $hashFalso = str_repeat('F', 64);
    $resInexistente = $validator->validarCodigo($hashFalso);
    if ($resInexistente['autentico'] === false && $resInexistente['status'] === 'nao_encontrado') {
        echo "  {$verde}✓ [OK]{$reset} Hash inexistente rejeitado corretamente com status 'nao_encontrado'.\n";
    } else {
        echo "{$vermelho}[FALHA] Hash inexistente não foi tratado corretamente.{$reset}\n";
        $falhas++;
    }

    // Borda 2: Hash truncado / tamanho incorreto
    $testesExecutados++;
    $resTruncado = $validator->validarCodigo("6FB1ED64F19C8BBC4DA014D0");
    if ($resTruncado['autentico'] === false && $resTruncado['status'] === 'invalido') {
        echo "  {$verde}✓ [OK]{$reset} Hash truncado rejeitado imediatamente sem consulta ao banco.\n";
    } else {
        echo "{$vermelho}[FALHA] Hash truncado não rejeitado como inválido.{$reset}\n";
        $falhas++;
    }

    // Borda 3: Tentativa de SQL Injection
    $testesExecutados++;
    $resInjection = $validator->validarCodigo("' OR '1'='1' -- ");
    if ($resInjection['autentico'] === false) {
        echo "  {$verde}✓ [OK]{$reset} Tentativa de SQL Injection sanitizada e bloqueada com sucesso.\n";
    } else {
        echo "{$vermelho}[FALHA CRÍTICA] SQL Injection permitida!{$reset}\n";
        $falhas++;
    }

    // Borda 4: Código vazio ou nulo
    $testesExecutados++;
    $resVazio = $validator->validarCodigo(null);
    if ($resVazio['autentico'] === false && $resVazio['status'] === 'vazio') {
        echo "  {$verde}✓ [OK]{$reset} Consulta vazia tratada com status 'vazio'.\n";
    } else {
        echo "{$vermelho}[FALHA] Consulta vazia não tratada corretamente.{$reset}\n";
        $falhas++;
    }

    // Borda 5: Código com espaços e minúsculas (higienização resiliente)
    $testesExecutados++;
    $primeiroCodigo = strtolower($certificadosOriginais[0]['codigo_autenticidade']);
    $codigoComEspacos = "  " . substr($primeiroCodigo, 0, 32) . " \n " . substr($primeiroCodigo, 32) . "  ";
    $resHigienizado = $validator->validarCodigo($codigoComEspacos);
    if ($resHigienizado['autentico'] === true) {
        echo "  {$verde}✓ [OK]{$reset} Código com espaços/minúsculas higienizado com 100% de sucesso.\n";
    } else {
        echo "{$vermelho}[FALHA] Falha na higienização resiliente de código com espaços.{$reset}\n";
        $falhas++;
    }

    echo "\n----------------------------------------------------------------------\n";
    if ($falhas === 0) {
        echo "{$verde}RESULTADO: 100% DE SUCESSO! ({$testesExecutados} verificações executadas sem falhas).{$reset}\n";
        echo "{$verde}Garantia de Não-Regressão e Conformidade LGPD APROVADAS com louvor!{$reset}\n";
    } else {
        echo "{$vermelho}RESULTADO: {$falhas} falhas detectadas em {$testesExecutados} testes.{$reset}\n";
        exit(1);
    }
    echo "----------------------------------------------------------------------\n";

} finally {
    // Limpeza
    Database::resetConnection();
    $validator = null;
    $stmtOrig = null;
    $insertStmt = null;
    $testPdo = null;
    $sourcePdo = null;
    gc_collect_cycles();
    if (file_exists($tempDbPath)) {
        @unlink($tempDbPath);
    }
}
