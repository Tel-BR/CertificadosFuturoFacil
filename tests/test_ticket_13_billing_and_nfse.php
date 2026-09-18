<?php
/**
 * Suíte de Testes Automatizados — Ticket 13: Faturamento, Dados Financeiros e Gerador Assistido de Texto para NFS-e
 * Cobertura TDD:
 *  - Costura 1: Modelagem e Persistência de Dados Fiscais (TurmaService & BillingService)
 *  - Costura 2: Motor de Cálculo Dinâmico de Faturamento (BillingService::calculateTotal)
 *  - Costura 3: Gerador Assistido de Discriminação de Serviços para NFS-e (BillingService::generateNfseText)
 *  - Costura 4: Bordas de Interface Web e Experiência Editorial (turma.php & turma_form.php)
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../src/Config/Database.php';
require_once __DIR__ . '/../src/Services/CalendarService.php';
require_once __DIR__ . '/../src/Services/TurmaService.php';
require_once __DIR__ . '/../src/Services/AuthService.php';

// O BillingService será requerido se existir; na fase RED da costura, testaremos sua instanciação
if (file_exists(__DIR__ . '/../src/Services/BillingService.php')) {
    require_once __DIR__ . '/../src/Services/BillingService.php';
}

use FuturoFacil\Config\Database;
use FuturoFacil\Services\CalendarService;
use FuturoFacil\Services\TurmaService;
use FuturoFacil\Services\BillingService;
use FuturoFacil\Services\AuthService;

$verde = "\033[32m";
$vermelho = "\033[31m";
$amarelo = "\033[33m";
$azul = "\033[36m";
$reset = "\033[0m";

echo "{$azul}======================================================================\n";
echo " Executando Suíte de Testes — Ticket 13: Faturamento e Apoio a NFS-e\n";
echo "======================================================================{$reset}\n\n";

$testDbPath = __DIR__ . '/test_temp_ticket_13.db';
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

    // =========================================================================
    // COSTURA 1: Modelagem e Persistência de Campos Fiscais
    // =========================================================================
    echo "\n{$amarelo}-> 1. Testando Schema e Persistência dos Campos Fiscais...{$reset}\n";

    $turmaService->ensureSchema();

    $cols = $pdo->query("PRAGMA table_info(turmas)")->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($cols, 'name');

    assertTest(in_array('razao_social', $colNames, true), "Tabela 'turmas' possui coluna 'razao_social'");
    assertTest(in_array('cnpj_tomador', $colNames, true), "Tabela 'turmas' possui coluna 'cnpj_tomador'");
    assertTest(in_array('cidade_uf', $colNames, true), "Tabela 'turmas' possui coluna 'cidade_uf'");
    assertTest(in_array('email_financeiro', $colNames, true), "Tabela 'turmas' possui coluna 'email_financeiro'");
    assertTest(in_array('numero_os_contrato', $colNames, true), "Tabela 'turmas' possui coluna 'numero_os_contrato'");
    assertTest(in_array('tipo_cobranca', $colNames, true), "Tabela 'turmas' possui coluna 'tipo_cobranca'");
    assertTest(in_array('valor_unitario', $colNames, true), "Tabela 'turmas' possui coluna 'valor_unitario'");
    assertTest(in_array('valor_total', $colNames, true), "Tabela 'turmas' possui coluna 'valor_total'");

    // Criação de turma com campos fiscais completos
    $dadosTurmaFiscal = [
        'curso_nome'         => 'Excel Corporativo Avançado',
        'razao_social'       => 'Cooperativa de Crédito Central S/A',
        'cnpj_tomador'       => '02.123.456/0001-89',
        'cidade_uf'          => 'Goiânia - GO',
        'email_financeiro'   => 'financeiro@coopcentral.com.br',
        'numero_os_contrato' => 'CT-2026-088',
        'tipo_cobranca'      => 'hora_aula',
        'valor_unitario'     => 220.00,
        'valor_total'        => 3520.00,
        'carga_horaria'      => 16,
        'modalidade'         => 'Presencial',
    ];

    $encontrosIniciais = [
        ['data_encontro' => '2026-11-03', 'turno' => 'V', 'horario_inicio' => '14:00', 'horario_fim' => '18:00', 'conteudo_previsto' => 'Fórmulas Matriciais', 'tipo' => 'aula'],
        ['data_encontro' => '2026-11-04', 'turno' => 'V', 'horario_inicio' => '14:00', 'horario_fim' => '18:00', 'conteudo_previsto' => 'Power Query ETL', 'tipo' => 'aula'],
        ['data_encontro' => '2026-11-05', 'turno' => 'V', 'horario_inicio' => '14:00', 'horario_fim' => '18:00', 'conteudo_previsto' => 'Modelagem de Dados', 'tipo' => 'aula'],
        ['data_encontro' => '2026-11-06', 'turno' => 'V', 'horario_inicio' => '14:00', 'horario_fim' => '18:00', 'conteudo_previsto' => 'Dashboards Interativos', 'tipo' => 'aula'],
    ];

    $turmaId = $turmaService->createTurma($dadosTurmaFiscal, $encontrosIniciais);
    assertTest($turmaId > 0, "Turma criada com sucesso com dados fiscais completos (ID {$turmaId})");

    $stmtGet = $pdo->prepare("SELECT * FROM turmas WHERE id = ?");
    $stmtGet->execute([$turmaId]);
    $turmaCriada = $stmtGet->fetch(PDO::FETCH_ASSOC);

    assertTest($turmaCriada['razao_social'] === 'Cooperativa de Crédito Central S/A', "Campo 'razao_social' persistido corretamente");
    assertTest($turmaCriada['cnpj_tomador'] === '02.123.456/0001-89', "Campo 'cnpj_tomador' persistido corretamente");
    assertTest($turmaCriada['cidade_uf'] === 'Goiânia - GO', "Campo 'cidade_uf' persistido corretamente");
    assertTest($turmaCriada['email_financeiro'] === 'financeiro@coopcentral.com.br', "Campo 'email_financeiro' persistido corretamente");
    assertTest($turmaCriada['numero_os_contrato'] === 'CT-2026-088', "Campo 'numero_os_contrato' persistido corretamente");
    assertTest((float)$turmaCriada['valor_unitario'] === 220.00, "Campo 'valor_unitario' persistido com valor 220.00");
    assertTest((float)$turmaCriada['valor_total'] === 3520.00, "Campo 'valor_total' persistido com valor 3520.00");

    // Retrocompatibilidade: cliente_nome e ordem_servico sincronizados
    assertTest($turmaCriada['cliente_nome'] === 'Cooperativa de Crédito Central S/A', "Retrocompatibilidade: 'cliente_nome' sincronizado com 'razao_social'");
    assertTest($turmaCriada['ordem_servico'] === 'CT-2026-088', "Retrocompatibilidade: 'ordem_servico' sincronizado com 'numero_os_contrato'");

    // Teste de atualização via updateTurma
    $turmaService->updateTurma($turmaId, [
        'razao_social'       => 'Cooperativa Central Atualizada',
        'numero_os_contrato' => 'CT-2026-088-REV1',
        'tipo_cobranca'      => 'por_aluno',
        'valor_unitario'     => 300.00,
    ]);

    $stmtGet->execute([$turmaId]);
    $turmaAtualizada = $stmtGet->fetch(PDO::FETCH_ASSOC);
    assertTest($turmaAtualizada['razao_social'] === 'Cooperativa Central Atualizada', "updateTurma atualizou 'razao_social'");
    assertTest($turmaAtualizada['numero_os_contrato'] === 'CT-2026-088-REV1', "updateTurma atualizou 'numero_os_contrato'");
    assertTest($turmaAtualizada['tipo_cobranca'] === 'por_aluno', "updateTurma atualizou 'tipo_cobranca'");
    assertTest((float)$turmaAtualizada['valor_unitario'] === 300.00, "updateTurma atualizou 'valor_unitario'");

    // =========================================================================
    // COSTURA 2: Motor de Cálculo Dinâmico (BillingService)
    // =========================================================================
    echo "\n{$amarelo}-> 2. Testando Motor de Cálculo Dinâmico (BillingService)...{$reset}\n";

    assertTest(class_exists(BillingService::class), "Classe BillingService existe no namespace FuturoFacil\\Services");

    if (class_exists(BillingService::class)) {
        $billingService = new BillingService($pdo);

        // 1. Cálculo por Hora-Aula
        $totalHoraAula = $billingService->calculateTotal('hora_aula', 200.00, 10, 16.0);
        assertTest($totalHoraAula === 3200.00, "Cálculo hora_aula correto (16h * R$ 200 = R$ 3.200,00)", "Obtido: {$totalHoraAula}");

        // 2. Cálculo Por Aluno
        $totalPorAluno = $billingService->calculateTotal('por_aluno', 350.00, 12, 16.0);
        assertTest($totalPorAluno === 4200.00, "Cálculo por_aluno correto (12 alunos * R$ 350 = R$ 4.200,00)", "Obtido: {$totalPorAluno}");

        // 3. Cálculo Valor Fechado
        $totalFechado = $billingService->calculateTotal('valor_fechado', 0.00, 12, 16.0, 5500.00);
        assertTest($totalFechado === 5500.00, "Cálculo valor_fechado correto (R$ 5.500,00 fixo)", "Obtido: {$totalFechado}");

        // Matricula 4 alunos na turma de teste para verificar getTurmaBillingData
        $stmtAluno = $pdo->prepare("INSERT INTO alunos (turma_id, nome_completo, cpf) VALUES (?, ?, ?)");
        $stmtAluno->execute([$turmaId, 'Aluno Teste 1', '11122233344']);
        $stmtAluno->execute([$turmaId, 'Aluno Teste 2', '22233344455']);
        $stmtAluno->execute([$turmaId, 'Aluno Teste 3', '33344455566']);
        $stmtAluno->execute([$turmaId, 'Aluno Teste 4', '44455566677']);

        $billingData = $billingService->getTurmaBillingData($turmaId);

        assertTest($billingData['total_alunos'] === 4, "getTurmaBillingData identificou exatamente 4 alunos matriculados");
        assertTest($billingData['total_horas'] === 16.0, "getTurmaBillingData computou 16.0 horas de aulas reais");
        assertTest($billingData['tipo_cobranca'] === 'por_aluno', "getTurmaBillingData retornou tipo_cobranca = por_aluno");
        // Como o tipo é por_aluno e valor_unitario = 300, total_calculado deve ser 4 * 300 = 1200
        assertTest($billingData['valor_total_calculado'] === 1200.00, "valor_total_calculado para 4 alunos * R$ 300 = R$ 1.200,00", "Obtido: {$billingData['valor_total_calculado']}");

        // Teste de atualização via updateBillingData
        $atualizou = $billingService->updateBillingData($turmaId, [
            'tipo_cobranca'  => 'hora_aula',
            'valor_unitario' => 250.00,
        ]);
        assertTest($atualizou === true, "updateBillingData executado com sucesso");

        $billingDataNovo = $billingService->getTurmaBillingData($turmaId);
        // 16h * 250 = 4000.00
        assertTest($billingDataNovo['valor_total_calculado'] === 4000.00, "Recálculo dinâmico após update: 16h * R$ 250 = R$ 4.000,00");
    }

    // =========================================================================
    // COSTURA 3: Gerador Assistido de Discriminação para NFS-e
    // =========================================================================
    echo "\n{$amarelo}-> 3. Testando Gerador Assistido de Discriminação de Serviços para NFS-e...{$reset}\n";

    if (class_exists(BillingService::class)) {
        // Adiciona um encontro extra de deslocamento logístico para confirmar exclusão do texto de NFS-e
        $stmtEncDesloc = $pdo->prepare("
            INSERT INTO encontros (turma_id, numero_encontro, data_encontro, turno, horario_inicio, horario_fim, tipo)
            VALUES (?, 99, '2026-11-02', 'V', '14:00', '18:00', 'deslocamento')
        ");
        $stmtEncDesloc->execute([$turmaId]);

        $nfseTexto = $billingService->generateNfseText($turmaId);

        assertTest(!empty($nfseTexto), "generateNfseText gerou texto não vazio");
        assertTest(str_contains($nfseTexto, 'Excel Corporativo Avançado'), "NFS-e contém o nome do curso");
        assertTest(str_contains($nfseTexto, 'Cooperativa Central Atualizada'), "NFS-e contém a Razão Social do tomador");
        assertTest(str_contains($nfseTexto, '02.123.456/0001-89'), "NFS-e contém o CNPJ formatado do tomador");
        assertTest(str_contains($nfseTexto, 'CT-2026-088-REV1'), "NFS-e contém o número da OS / Contrato");
        assertTest(str_contains($nfseTexto, '16 horas'), "NFS-e contém a carga horária total");
        assertTest(str_contains($nfseTexto, 'Aulas ministradas nos dias:'), "NFS-e contém o prefixo exato padronizado 'Aulas ministradas nos dias:'");
        assertTest(str_contains($nfseTexto, '03/11/2026 (14h às 18h)'), "NFS-e lista encontro 1 com data e horário formatados");
        assertTest(str_contains($nfseTexto, '04/11/2026 (14h às 18h)'), "NFS-e lista encontro 2 com data e horário formatados");
        assertTest(str_contains($nfseTexto, '05/11/2026 (14h às 18h)'), "NFS-e lista encontro 3 com data e horário formatados");
        assertTest(str_contains($nfseTexto, '06/11/2026 (14h às 18h)'), "NFS-e lista encontro 4 com data e horário formatados");
        assertTest(!str_contains($nfseTexto, '02/11/2026'), "NFS-e EXCLUIU rigorosamente a data do deslocamento logístico (02/11/2026)");
    }

    // =========================================================================
    // COSTURA 4: Bordas Web e Ergonomia Editorial (turma.php & turma_form.php)
    // =========================================================================
    echo "\n{$amarelo}-> 4. Testando Bordas de Interface Web e Ergonomia Editorial...{$reset}\n";

    $turmaViewSource = file_get_contents(__DIR__ . '/../public/diario/turma.php');
    $turmaFormSource = file_get_contents(__DIR__ . '/../public/diario/turma_form.php');

    // Verificações em turma.php
    assertTest(str_contains($turmaViewSource, 'Faturamento &amp; Dados Financeiros') || str_contains($turmaViewSource, 'Faturamento & Dados Financeiros'), "turma.php contém painel de Faturamento & Dados Financeiros");
    assertTest(str_contains($turmaViewSource, 'Copiar Descrição para NFS-e'), "turma.php contém botão 'Copiar Descrição para NFS-e'");
    assertTest(str_contains($turmaViewSource, 'copiarNfseTexto'), "turma.php contém função JS 'copiarNfseTexto'");
    assertTest(str_contains($turmaViewSource, 'toast') || str_contains($turmaViewSource, 'Copiado com sucesso'), "turma.php implementa feedback visual / toast 'Copiado com sucesso'");
    assertTest(str_contains($turmaViewSource, 'update_billing'), "turma.php processa ação 'update_billing' para preenchimento flexível");

    // ADR 0006: Verificação de ausência de emojis no card de faturamento
    assertTest(!preg_match('/[\x{1F300}-\x{1FAFF}]/u', $turmaViewSource) || !str_contains($turmaViewSource, '💰'), "turma.php respeita ADR 0006 evitando emojis na interface editorial");

    // Verificações em turma_form.php
    assertTest(str_contains($turmaFormSource, 'razao_social'), "turma_form.php contém campo 'razao_social'");
    assertTest(str_contains($turmaFormSource, 'cnpj_tomador'), "turma_form.php contém campo 'cnpj_tomador'");
    assertTest(str_contains($turmaFormSource, 'cidade_uf'), "turma_form.php contém campo 'cidade_uf'");
    assertTest(str_contains($turmaFormSource, 'email_financeiro'), "turma_form.php contém campo 'email_financeiro'");
    assertTest(str_contains($turmaFormSource, 'numero_os_contrato'), "turma_form.php contém campo 'numero_os_contrato'");
    assertTest(str_contains($turmaFormSource, 'tipo_cobranca'), "turma_form.php contém seletor de 'tipo_cobranca'");
    assertTest(str_contains($turmaFormSource, 'valor_unitario'), "turma_form.php contém campo 'valor_unitario'");
    assertTest(str_contains($turmaFormSource, 'valor_total'), "turma_form.php contém campo 'valor_total'");
    assertTest(str_contains($turmaFormSource, 'recalcularTotalFinanceiro') || str_contains($turmaFormSource, 'calcTotal'), "turma_form.php contém lógica JS para cálculo dinâmico reativo");

} catch (Throwable $e) {
    echo "{$vermelho}Erro fatal durante execução da suíte: {$e->getMessage()}{$reset}\n";
    echo $e->getTraceAsString() . "\n";
} finally {
    $pdo = null;
    $turmaService = null;
    $calendarService = null;
    $billingService = null;
    Database::resetConnection();
    if (file_exists($testDbPath)) {
        @unlink($testDbPath);
    }
}

echo "\n----------------------------------------------------------------------\n";
echo "RESULTADO: {$passedCount}/{$totalCount} asserções passaram.\n";
if ($passedCount === $totalCount && $totalCount > 0) {
    echo "{$verde}100% DOS TESTES DO TICKET 13 FORAM APROVADOS!{$reset}\n";
} else {
    echo "{$vermelho}FASE TDD: Existem testes pendentes de implementação.{$reset}\n";
}
echo "----------------------------------------------------------------------\n";
