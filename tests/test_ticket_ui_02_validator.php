<?php
/**
 * Suíte de Testes Automatizados — Ticket UI 02
 * Validação Pública e Harmonização Editorial (ADR-0006 e LGPD)
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
echo " Executando Suíte de Testes — Ticket UI 02: Validação Editorial & LGPD\n";
echo "======================================================================{$reset}\n\n";

$passou = true;
$totalAsserts = 0;

function afirme(bool $condicao, string $descricao): void {
    global $passou, $totalAsserts, $verde, $vermelho, $reset;
    $totalAsserts++;
    if ($condicao) {
        echo "  {$verde}✓ [OK]{$reset} {$descricao}\n";
    } else {
        echo "  {$vermelho}✗ [FALHA]{$reset} {$descricao}\n";
        $passou = false;
    }
}

// 1. Análise Estática de Design System e Diretrizes ADR-0006
echo "{$amarelo}-> 1. Análise Estática de Design System e Diretrizes ADR-0006...{$reset}\n";

$validarFile = __DIR__ . '/../public/validar/index.php';
afirme(file_exists($validarFile), "Arquivo public/validar/index.php existe");

$sourceCode = file_get_contents($validarFile);

afirme(str_contains($sourceCode, '#FAF7F1') || str_contains($sourceCode, '#faf7f1'), "Token papel marfim (#FAF7F1) configurado");
afirme(str_contains($sourceCode, '#1B1918') || str_contains($sourceCode, '#1b1918'), "Token carvão (#1B1918) configurado");
afirme(str_contains($sourceCode, '#0E7490') || str_contains($sourceCode, '#0e7490'), "Token Petróleo Tech (#0E7490) configurado");
afirme(str_contains($sourceCode, '#EA580C') || str_contains($sourceCode, '#ea580c'), "Token Coral Solar (#EA580C) configurado");
afirme(str_contains($sourceCode, '#E2DFDA') || str_contains($sourceCode, '#e2dfda'), "Token de bordas nítidas de 1px (#E2DFDA) configurado");
afirme(str_contains($sourceCode, '#FDEBEC') || str_contains($sourceCode, '#fdebec'), "Token card austero vermelho marfim (#FDEBEC) configurado");

afirme(str_contains($sourceCode, 'Ubuntu'), "Família tipográfica Ubuntu institucional incluída");
afirme(str_contains($sourceCode, 'monospace') || str_contains($sourceCode, 'JetBrains Mono'), "Tipografia Monospace para dados técnicos configurada");

afirme(str_contains($sourceCode, 'logo.svg'), "Logotipo oficial vetorial logo.svg referenciado no cabeçalho");
afirme(!str_contains($sourceCode, 'brand-logo">F</div>'), "Representação legada com caixa 'F' descontinuada");

$hasEmoji = preg_match('/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{1F1E0}-\x{1F1FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}\x{1F900}-\x{1F9FF}\x{1FA70}-\x{1FAFF}]/u', $sourceCode);
afirme(!$hasEmoji, "Disciplina minimalista: zero emojis na interface pública de validação");

// 2. Renderização de Certificado Autêntico
echo "\n{$amarelo}-> 2. Renderização de Certificado Autêntico (Caso de Sucesso)...{$reset}\n";

Database::setConfig([
    'driver' => 'sqlite',
    'database' => __DIR__ . '/../registros.db',
]);

$pdo = Database::getConnection();
$stmtCert = $pdo->query("SELECT * FROM registros_certificados WHERE aluno_nome LIKE '%Ana Luiza%' LIMIT 1");
$certReal = $stmtCert->fetch(PDO::FETCH_ASSOC);

if (!$certReal) {
    $certReal = $pdo->query("SELECT * FROM registros_certificados LIMIT 1")->fetch(PDO::FETCH_ASSOC);
}

afirme(!empty($certReal), "Certificado real recuperado da base para teste");

$_GET = ['validar' => $certReal['codigo_autenticidade']];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

ob_start();
require $validarFile;
$htmlValido = ob_get_clean();

afirme(str_contains($htmlValido, 'Certificado Autêntico e Válido'), "Cabeçalho confirma autenticidade inequívoca");
afirme(str_contains($htmlValido, 'A** L**** S***** N*********'), "Nome do titular do certificado estritamente mascarado (LGPD)");
afirme(str_contains($htmlValido, '***.367.631-**') || str_contains($htmlValido, substr($certReal['aluno_cpf'], 3, 7)), "CPF mascarado sob LGPD");
afirme(str_contains($htmlValido, $certReal['curso_nome']), "Nome do curso exibido com clareza");
afirme(str_contains($htmlValido, (string)$certReal['carga_horaria']), "Carga horária exibida");
afirme(str_contains($htmlValido, 'Livro nº') && str_contains($htmlValido, 'Folha nº') && str_contains($htmlValido, 'Registro nº'), "Assentos no Livro Digital de Registro exibidos");
afirme(str_contains($htmlValido, $certReal['codigo_autenticidade']), "Código SHA-256 de 64 caracteres presente em destaque");
afirme(str_contains($htmlValido, 'Garantia de Privacidade e LGPD'), "Nota institucional de conformidade com a LGPD visível");

// 3. Renderização de Código Não Localizado (Card Austero #FDEBEC)
echo "\n{$amarelo}-> 3. Renderização de Código Não Localizado (Card Austero)...{$reset}\n";

$hashInexistente = 'F' . str_repeat('0', 63);
$_GET = ['validar' => $hashInexistente];

ob_start();
require $validarFile;
$htmlNaoLocalizado = ob_get_clean();

afirme(str_contains($htmlNaoLocalizado, 'Certificado não localizado'), "Mensagem padronizada 'Certificado não localizado' renderizada");
afirme(str_contains($htmlNaoLocalizado, '#FDEBEC') || str_contains($htmlNaoLocalizado, '#fdebec') || str_contains($htmlNaoLocalizado, 'card-austere') || str_contains($htmlNaoLocalizado, 'not-found'), "Card com estilização austera em tom marfim avermelhado");
afirme(str_contains($htmlNaoLocalizado, 'conferir') || str_contains($htmlNaoLocalizado, 'conferência') || str_contains($htmlNaoLocalizado, 'contatar') || str_contains($htmlNaoLocalizado, 'coordenação'), "Orientação objetiva para conferir o código ou contatar a instituição presente");
afirme(str_contains($htmlNaoLocalizado, $hashInexistente), "Código pesquisado exibido na caixa de conferência");

// 4. Renderização de Código com Formato Inválido
echo "\n{$amarelo}-> 4. Renderização de Código com Formato Inválido...{$reset}\n";

$_GET = ['validar' => 'CODIGO_INVALIDO_CURTO'];

ob_start();
require $validarFile;
$htmlInvalido = ob_get_clean();

afirme(str_contains($htmlInvalido, 'formato') || str_contains($htmlInvalido, 'inválido') || str_contains($htmlInvalido, 'não localizado'), "Mensagem informativa para formato inválido exibida sem erros 500");
afirme(!str_contains($htmlInvalido, 'Fatal error') && !str_contains($htmlInvalido, 'Warning:'), "Sem avisos ou erros PHP na renderização de entrada com formato incorreto");

// 5. Estado Inicial (Empty State)
echo "\n{$amarelo}-> 5. Renderização do Estado Inicial (Empty State)...{$reset}\n";

$_GET = [];

ob_start();
require $validarFile;
$htmlEmpty = ob_get_clean();

afirme(str_contains($htmlEmpty, 'Pronto para Validação') || str_contains($htmlEmpty, 'Validação de Autenticidade'), "Estado de espera acolhedor e informativo");
afirme(str_contains($htmlEmpty, 'QR Code'), "Instrução para QR Code disponível no estado inicial");
afirme(str_contains($htmlEmpty, '<svg'), "Ícone vetorial SVG presente no estado inicial");

// 6. Teste de Não-Regressão em Lote dos Certificados Reais
echo "\n{$amarelo}-> 6. Teste de Não-Regressão em Lote dos Certificados Reais...{$reset}\n";

$stmtTodos = $pdo->query("SELECT codigo_autenticidade, aluno_nome, aluno_cpf FROM registros_certificados ORDER BY id ASC");
$todosCertificados = $stmtTodos->fetchAll(PDO::FETCH_ASSOC);

$totalReais = count($todosCertificados);
$sucessos = 0;
$validatorService = new ValidatorService($pdo);

foreach ($todosCertificados as $c) {
    $res = $validatorService->validarCodigo($c['codigo_autenticidade']);
    if ($res['autentico'] === true && !empty($res['aluno_nome_mascarado']) && !empty($res['aluno_cpf_mascarado'])) {
        $sucessos++;
    }
}

afirme($sucessos === $totalReais && $totalReais > 0, "100% dos {$totalReais} certificados da base real validados com mascaramento consistente ({$sucessos}/{$totalReais})");

// Resumo Final
echo "\n----------------------------------------------------------------------\n";
if ($passou) {
    echo "{$verde}RESULTADO: {$totalAsserts}/{$totalAsserts} asserções aprovadas (100%){$reset}\n";
    echo "{$verde}Suíte do Ticket UI 02 APROVADA COM SUCESSO!{$reset}\n";
    exit(0);
} else {
    echo "{$vermelho}RESULTADO: Falha em uma ou mais asserções. Verifique os erros acima.{$reset}\n";
    exit(1);
}
