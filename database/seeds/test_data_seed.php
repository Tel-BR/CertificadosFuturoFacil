<?php
/**
 * Script de Carga de Homologação (Seed de Testes Fictícios)
 * Plataforma Integrada de Gestão Pedagógica, Diário e Certificados Futuro Fácil
 * 
 * Popula o banco de dados com turmas pedagógicas fictícias representativas:
 * - Cobre todos os 4 turnos: Matutino [M], Vespertino [V], Noturno [N] e Dia Todo [D].
 * - Cobre os 3 estados temporais: Passadas (concluídas), Presentes (em andamento) e Futuras (previstas).
 * - Gera encontros detalhados com horários reais e planos de aula.
 * - Cadastra alunos fictícios sanitizados conforme a LGPD.
 * - Compatível tanto com MariaDB quanto com SQLite via PDO.
 * 
 * Uso via CLI:
 *   php database/seeds/test_data_seed.php [--force]
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/Database.php';

use FuturoFacil\Config\Database;

$verde = "\033[32m";
$vermelho = "\033[31m";
$amarelo = "\033[33m";
$azul = "\033[36m";
$reset = "\033[0m";

echo "{$azul}======================================================================\n";
echo " Carga de Homologação de Dados Pedagógicos — Futuro Fácil\n";
echo "======================================================================{$reset}\n\n";

$isForce = in_array('--force', $argv, true);

try {
    $pdo = Database::getConnection();

    // Limpar apenas turmas de seed anteriores (prefixo 'TURMA-SEED-') para não afetar certificados históricos
    if ($isForce) {
        echo "{$amarelo}-> Removendo turmas de seed anteriores (TURMA-SEED-*)...{$reset}\n";
        $stmtDelete = $pdo->prepare("DELETE FROM turmas WHERE codigo_turma LIKE 'TURMA-SEED-%'");
        $stmtDelete->execute();
    } else {
        // Checar se já existem turmas de seed
        $stmtCheck = $pdo->query("SELECT COUNT(*) FROM turmas WHERE codigo_turma LIKE 'TURMA-SEED-%'");
        if ((int)$stmtCheck->fetchColumn() > 0) {
            echo "{$amarelo}[AVISO] Turmas de homologação já existentes. Use '--force' para recriar.{$reset}\n";
            exit(0);
        }
    }

    $hoje = date('Y-m-d');
    $ontem = date('Y-m-d', strtotime('-1 day'));
    $anteontem = date('Y-m-d', strtotime('-2 days'));
    $passadoInicio = date('Y-m-d', strtotime('-14 days'));
    $passadoFim = date('Y-m-d', strtotime('-7 days'));
    $amanha = date('Y-m-d', strtotime('+1 day'));
    $futuro1 = date('Y-m-d', strtotime('+7 days'));
    $futuro2 = date('Y-m-d', strtotime('+14 days'));
    $futuro3 = date('Y-m-d', strtotime('+21 days'));

    echo "{$verde}-> Inserindo turmas de homologação nos 4 turnos e 3 horizontes temporais...{$reset}\n";

    $turmasSeed = [
        // 1. Passado - Concluída (Turno V - Vespertino)
        [
            'codigo'         => 'TURMA-SEED-01-EXCEL-SICOOB',
            'curso'          => 'Excel Especialista & Fórmulas Financeiras',
            'cliente'        => 'Sicoob Credisul',
            'ordem_servico'  => 'OS-2026-081',
            'modalidade'     => 'Presencial',
            'cliente_tipo'   => 'PJ',
            'cidade'         => 'Vilhena',
            'uf'             => 'RO',
            'tipo_cobranca'  => 'hora_aula',
            'valor_hora'     => 180.00,
            'carga_horaria'  => 16,
            'carga_extenso'  => 'Dezesseis horas',
            'data_inicio'    => $passadoInicio,
            'data_conclusao' => $passadoFim,
            'turno_padrao'   => 'V',
            'status'         => 'concluida',
            'chave_acesso'   => 'sicoob-excel-2026',
            'portal_certificados_modo' => 'download_direto',
            'instrutor'      => 'Instrutor Futuro Fácil',
            'materiais'      => [
                [
                    'titulo'    => 'Apostila Oficial - Excel Especialista & Fórmulas Financeiras',
                    'descricao' => 'Material didático completo com teoria, atalhos e exemplos práticos.',
                    'tipo'      => 'apostila',
                    'ordem'     => 1,
                    'ativo'     => 1,
                    'arquivo'   => 'Apostila_Excel_Sicoob.pdf',
                    'conteudo'  => "%PDF-1.4 Mock Sicoob Apostila PDF\n",
                ],
                [
                    'titulo'    => 'Planilha de Exercícios Práticos - PROCV e Modelagem Financeira',
                    'descricao' => 'Arquivo de trabalho utilizado nas aulas práticas da turma.',
                    'tipo'      => 'exercicio',
                    'ordem'     => 2,
                    'ativo'     => 1,
                    'arquivo'   => 'Exercicios_Modelagem_Sicoob.xlsx',
                    'conteudo'  => "PK Mock XLSX Exercicios\n",
                ],
                [
                    'titulo'    => 'Gabarito das Fórmulas Avançadas (Oculto pelo Instrutor)',
                    'descricao' => 'Resolução das tarefas práticas para liberação ao término das avaliações.',
                    'tipo'      => 'exercicio',
                    'ordem'     => 3,
                    'ativo'     => 0,
                    'arquivo'   => 'Gabarito_Formulas_Sicoob.xlsx',
                    'conteudo'  => "PK Mock XLSX Gabarito\n",
                ],
                [
                    'titulo'    => 'Vídeo Tutorial: Atalhos Essenciais no Excel',
                    'descricao' => 'Gravação complementar com dicas de produtividade rápida.',
                    'tipo'      => 'link',
                    'ordem'     => 4,
                    'ativo'     => 1,
                    'url'       => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                ],
            ],
            'encontros'      => [
                [
                    'numero'    => 1,
                    'data'      => $passadoInicio,
                    'turno'     => 'V',
                    'inicio'    => '14:00:00',
                    'fim'       => '18:00:00',
                    'previsto'  => 'Fórmulas Lógicas e Financeiras Avançadas (PROCV, XLOOKUP, VF, PGTO)',
                    'ministrado'=> 'Fórmulas executadas e exercícios práticos em planilhas bancárias reais.',
                ],
                [
                    'numero'    => 2,
                    'data'      => $passadoFim,
                    'turno'     => 'V',
                    'inicio'    => '14:00:00',
                    'fim'       => '18:00:00',
                    'previsto'  => 'Tabelas Dinâmicas, Segmentação e Dashboards Financeiros',
                    'ministrado'=> 'Construção de relatórios executivos com segmentação de dados e KPIs.',
                ],
            ],
            'alunos'         => [
                ['nome' => 'Camila Rodrigues Alves', 'cpf' => '12345678901', 'mascarado' => '***.456.789-**'],
                ['nome' => 'Marcos Vinicius Santos', 'cpf' => '23456789012', 'mascarado' => '***.567.890-**'],
                ['nome' => 'Renata Silveira Lima',   'cpf' => '34567890123', 'mascarado' => '***.678.901-**'],
            ],
        ],

        // 2. Passado - Concluída (Turno M - Matutino)
        [
            'codigo'         => 'TURMA-SEED-02-DASH-SEBRAE',
            'curso'          => 'Análise de Negócios e Dashboards Estratégicos',
            'cliente'        => 'Sebrae Rondônia',
            'ordem_servico'  => 'OS-SEBRAE-044',
            'modalidade'     => 'Presencial',
            'cliente_tipo'   => 'PJ',
            'cidade'         => 'Porto Velho',
            'uf'             => 'RO',
            'tipo_cobranca'  => 'valor_fechado',
            'valor_hora'     => 0.00,
            'valor_total'    => 4500.00,
            'carga_horaria'  => 12,
            'carga_extenso'  => 'Doze horas',
            'data_inicio'    => $anteontem,
            'data_conclusao' => $ontem,
            'turno_padrao'   => 'M',
            'status'         => 'concluida',
            'chave_acesso'   => 'sebrae-dash-2026',
            'portal_certificados_modo' => 'coordenacao',
            'instrutor'      => 'Instrutor Futuro Fácil',
            'materiais'      => [
                [
                    'titulo'    => 'Guia de Indicadores e Dashboards Estratégicos',
                    'descricao' => 'Apostila de métricas para pequenas e médias empresas.',
                    'tipo'      => 'apostila',
                    'ordem'     => 1,
                    'ativo'     => 1,
                    'arquivo'   => 'Guia_Dashboards_Sebrae.pdf',
                    'conteudo'  => "%PDF-1.4 Mock Sebrae Guia PDF\n",
                ],
                [
                    'titulo'    => 'Pesquisa de Satisfação do Workshop Sebrae',
                    'descricao' => 'Formulário de feedback dos participantes.',
                    'tipo'      => 'link',
                    'ordem'     => 2,
                    'ativo'     => 1,
                    'url'       => 'https://docs.google.com/forms/d/e/1FAIpQLScMockForms/viewform',
                ],
            ],
            'encontros'      => [
                [
                    'numero'    => 1,
                    'data'      => $anteontem,
                    'turno'     => 'M',
                    'inicio'    => '08:00:00',
                    'fim'       => '12:00:00',
                    'previsto'  => 'Métricas de Performance e Indicadores de Pequenas Empresas',
                    'ministrado'=> 'Mapeamento de KPIs comerciais e ponto de equilíbrio com cases reais.',
                ],
                [
                    'numero'    => 2,
                    'data'      => $ontem,
                    'turno'     => 'M',
                    'inicio'    => '08:00:00',
                    'fim'       => '12:00:00',
                    'previsto'  => 'Design de Painéis Visuais e Storytelling de Dados',
                    'ministrado'=> 'Criação de painel analítico final apresentado aos consultores.',
                ],
            ],
            'alunos'         => [
                ['nome' => 'Bruno Henrique Duarte',   'cpf' => '45678901234', 'mascarado' => '***.789.012-**'],
                ['nome' => 'Tatiane Moreira Costa',   'cpf' => '56789012345', 'mascarado' => '***.890.123-**'],
            ],
        ],

        // 3. Presente - Em Andamento (Hoje no turno M - Matutino)
        [
            'codigo'         => 'TURMA-SEED-03-PBI-SICREDI',
            'curso'          => 'Power BI Corporativo & Modelagem DAX Avançada',
            'cliente'        => 'Sicredi Univales',
            'ordem_servico'  => 'OS-SICREDI-102',
            'modalidade'     => 'Presencial',
            'cliente_tipo'   => 'PJ',
            'cidade'         => 'Cacoal',
            'uf'             => 'RO',
            'tipo_cobranca'  => 'hora_aula',
            'valor_hora'     => 220.00,
            'carga_horaria'  => 24,
            'carga_extenso'  => 'Vinte e quatro horas',
            'data_inicio'    => $hoje,
            'data_conclusao' => $amanha,
            'turno_padrao'   => 'M',
            'status'         => 'em_andamento',
            'chave_acesso'   => 'sicredi-dax-2026',
            'portal_certificados_modo' => 'nenhum',
            'instrutor'      => 'Instrutor Futuro Fácil',
            'materiais'      => [
                [
                    'titulo'    => 'Apostila Oficial - Power BI Corporativo e DAX',
                    'descricao' => 'Fundamentos de modelagem dimensional e funções DAX avançadas.',
                    'tipo'      => 'apostila',
                    'ordem'     => 1,
                    'ativo'     => 1,
                    'arquivo'   => 'Apostila_PowerBI_Sicredi.pdf',
                    'conteudo'  => "%PDF-1.4 Mock Sicredi PBI PDF\n",
                ],
            ],
            'encontros'      => [
                [
                    'numero'    => 1,
                    'data'      => $hoje,
                    'turno'     => 'M',
                    'inicio'    => '08:00:00',
                    'fim'       => '12:00:00',
                    'previsto'  => 'Modelagem Star Schema, Tabelas de Dimensão e Fato',
                    'ministrado'=> null, // Em andamento hoje
                ],
                [
                    'numero'    => 2,
                    'data'      => $amanha,
                    'turno'     => 'M',
                    'inicio'    => '08:00:00',
                    'fim'       => '12:00:00',
                    'previsto'  => 'Funções de Inteligência Temporal em DAX (SAMEPERIODLASTYEAR, TOTALYTD)',
                    'ministrado'=> null,
                ],
            ],
            'alunos'         => [
                ['nome' => 'Alexandre Magno Ferreira', 'cpf' => '67890123456', 'mascarado' => '***.901.234-**'],
                ['nome' => 'Debora Cristina Souza',    'cpf' => '78901234567', 'mascarado' => '***.012.345-**'],
                ['nome' => 'Gabriel Farias Martins',   'cpf' => '89012345678', 'mascarado' => '***.123.456-**'],
            ],
        ],

        // 4. Presente - Em Andamento (Hoje no turno N - Noturno)
        [
            'codigo'         => 'TURMA-SEED-04-AUTO-ACIC',
            'curso'          => 'Automação Comercial e Produtividade com IA',
            'cliente'        => 'ACIC Cacoal',
            'ordem_servico'  => 'OS-ACIC-019',
            'modalidade'     => 'Presencial',
            'cliente_tipo'   => 'PJ',
            'cidade'         => 'Cacoal',
            'uf'             => 'RO',
            'tipo_cobranca'  => 'hora_aula',
            'valor_hora'     => 150.00,
            'carga_horaria'  => 8,
            'carga_extenso'  => 'Oito horas',
            'data_inicio'    => $hoje,
            'data_conclusao' => $hoje,
            'turno_padrao'   => 'N',
            'status'         => 'em_andamento',
            'chave_acesso'   => 'acic-ia-2026',
            'instrutor'      => 'Instrutor Futuro Fácil',
            'encontros'      => [
                [
                    'numero'    => 1,
                    'data'      => $hoje,
                    'turno'     => 'N',
                    'inicio'    => '19:00:00',
                    'fim'       => '22:30:00',
                    'previsto'  => 'Workflows de Automação e Redação com Assistentes Locais',
                    'ministrado'=> null,
                ],
            ],
            'alunos'         => [
                ['nome' => 'Juliana Prado Ribeiro', 'cpf' => '90123456789', 'mascarado' => '***.234.567-**'],
                ['nome' => 'Rodrigo Mendes Rocha',  'cpf' => '01234567890', 'mascarado' => '***.345.678-**'],
            ],
        ],

        // 5. Futuro - Prevista (Turno D - Dia Todo / Integral)
        [
            'codigo'         => 'TURMA-SEED-05-SAUDE-UNIMED',
            'curso'          => 'Imersão em Análise de Indicadores de Saúde',
            'cliente'        => 'Unimed Ji-Paraná',
            'ordem_servico'  => 'OS-UNIMED-2026',
            'modalidade'     => 'Presencial',
            'cliente_tipo'   => 'PJ',
            'cidade'         => 'Ji-Paraná',
            'uf'             => 'RO',
            'tipo_cobranca'  => 'valor_fechado',
            'valor_hora'     => 0.00,
            'valor_total'    => 5200.00,
            'carga_horaria'  => 8,
            'carga_extenso'  => 'Oito horas',
            'data_inicio'    => $futuro1,
            'data_conclusao' => $futuro1,
            'turno_padrao'   => 'D',
            'status'         => 'prevista',
            'chave_acesso'   => 'unimed-saude-2026',
            'instrutor'      => 'Instrutor Futuro Fácil',
            'encontros'      => [
                [
                    'numero'    => 1,
                    'data'      => $futuro1,
                    'turno'     => 'D',
                    'inicio'    => '08:00:00',
                    'fim'       => '17:00:00',
                    'previsto'  => 'Análise de Sinistralidade, Custos Assistenciais e Benchmarking Hospitalar',
                    'ministrado'=> null,
                ],
            ],
            'alunos'         => [
                ['nome' => 'Dr. Fernando Vasconcelos', 'cpf' => '11223344556', 'mascarado' => '***.233.445-**'],
                ['nome' => 'Dra. Patricia Medeiros',   'cpf' => '22334455667', 'mascarado' => '***.344.556-**'],
            ],
        ],

        // 6. Futuro - Prevista (Turno V - Vespertino)
        [
            'codigo'         => 'TURMA-SEED-06-GOV-OCBRO',
            'curso'          => 'Governança Cooperativa e Finanças Estratégicas',
            'cliente'        => 'Sistema OCB/RO',
            'ordem_servico'  => 'OS-OCB-008',
            'modalidade'     => 'Presencial',
            'cliente_tipo'   => 'PJ',
            'cidade'         => 'Porto Velho',
            'uf'             => 'RO',
            'tipo_cobranca'  => 'hora_aula',
            'valor_hora'     => 200.00,
            'carga_horaria'  => 16,
            'carga_extenso'  => 'Dezesseis horas',
            'data_inicio'    => $futuro2,
            'data_conclusao' => $futuro3,
            'turno_padrao'   => 'V',
            'status'         => 'prevista',
            'chave_acesso'   => 'ocbro-gov-2026',
            'instrutor'      => 'Instrutor Futuro Fácil',
            'encontros'      => [
                [
                    'numero'    => 1,
                    'data'      => $futuro2,
                    'turno'     => 'V',
                    'inicio'    => '14:00:00',
                    'fim'       => '18:00:00',
                    'previsto'  => 'Estruturas de Governança e Compliance Cooperativo',
                    'ministrado'=> null,
                ],
                [
                    'numero'    => 2,
                    'data'      => $futuro3,
                    'turno'     => 'V',
                    'inicio'    => '14:00:00',
                    'fim'       => '18:00:00',
                    'previsto'  => 'Planejamento Orçamentário e Assembleias Digitais',
                    'ministrado'=> null,
                ],
            ],
            'alunos'         => [
                ['nome' => 'Lucas Evangelista Lima', 'cpf' => '33445566778', 'mascarado' => '***.455.667-**'],
                ['nome' => 'Mariana Peixoto Borges', 'cpf' => '44556677889', 'mascarado' => '***.566.778-**'],
            ],
        ],
    ];

    $stmtTurma = $pdo->prepare("
        INSERT INTO turmas (
            codigo_turma, curso_nome, cliente_nome, ordem_servico, modalidade, cliente_tipo,
            cliente_cidade, cliente_uf, tipo_cobranca, valor_hora_aula, valor_total,
            carga_horaria, carga_horaria_extenso, data_inicio, data_conclusao, turno_padrao,
            status, chave_acesso, portal_certificados_modo, instrutor
        ) VALUES (
            :codigo, :curso, :cliente, :os, :modalidade, :cliente_tipo,
            :cidade, :uf, :tipo_cobranca, :valor_hora, :valor_total,
            :carga_horaria, :carga_extenso, :data_inicio, :data_conclusao, :turno,
            :status, :chave, :portal_modo, :instrutor
        )
    ");

    $stmtEncontro = $pdo->prepare("
        INSERT INTO encontros (
            turma_id, numero_encontro, data_encontro, turno,
            horario_inicio, horario_fim, conteudo_previsto, conteudo_ministrado
        ) VALUES (
            :turma_id, :numero, :data, :turno,
            :inicio, :fim, :previsto, :ministrado
        )
    ");

    $stmtAluno = $pdo->prepare("
        INSERT INTO alunos (
            turma_id, nome_completo, cpf, cpf_limpo, cpf_mascarado
        ) VALUES (
            :turma_id, :nome, :cpf, :limpo, :mascarado
        )
    ");

    $stmtFreq = $pdo->prepare("
        INSERT INTO frequencias (encontro_id, aluno_id, presente)
        VALUES (:encontro_id, :aluno_id, :presente)
    ");

    $stmtMaterial = $pdo->prepare("
        INSERT INTO materiais_turma (
            turma_id, titulo, descricao, tipo, caminho_arquivo, url_externa, tamanho_bytes, ordem, ativo
        ) VALUES (
            :turma_id, :titulo, :descricao, :tipo, :caminho, :url, :tamanho, :ordem, :ativo
        )
    ");

    $baseArquivosDir = dirname(__DIR__, 2) . '/public/turmas/arquivos';

    foreach ($turmasSeed as $t) {
        $stmtTurma->execute([
            ':codigo'        => $t['codigo'],
            ':curso'         => $t['curso'],
            ':cliente'       => $t['cliente'],
            ':os'            => $t['ordem_servico'],
            ':modalidade'    => $t['modalidade'],
            ':cliente_tipo'  => $t['cliente_tipo'],
            ':cidade'        => $t['cidade'],
            ':uf'            => $t['uf'],
            ':tipo_cobranca' => $t['tipo_cobranca'],
            ':valor_hora'    => $t['valor_hora'] ?? 0.00,
            ':valor_total'   => $t['valor_total'] ?? 0.00,
            ':carga_horaria' => $t['carga_horaria'],
            ':carga_extenso' => $t['carga_extenso'],
            ':data_inicio'   => $t['data_inicio'],
            ':data_conclusao'=> $t['data_conclusao'],
            ':turno'         => $t['turno_padrao'],
            ':status'        => $t['status'],
            ':chave'         => $t['chave_acesso'],
            ':portal_modo'   => $t['portal_certificados_modo'] ?? 'nenhum',
            ':instrutor'     => $t['instrutor'],
        ]);

        $turmaId = (int)$pdo->lastInsertId();
        $encontroIds = [];

        foreach ($t['encontros'] as $e) {
            $stmtEncontro->execute([
                ':turma_id'   => $turmaId,
                ':numero'     => $e['numero'],
                ':data'       => $e['data'],
                ':turno'      => $e['turno'],
                ':inicio'     => $e['inicio'],
                ':fim'        => $e['fim'],
                ':previsto'   => $e['previsto'],
                ':ministrado' => $e['ministrado'],
            ]);
            $encontroIds[] = (int)$pdo->lastInsertId();
        }

        $alunoIds = [];
        foreach ($t['alunos'] as $al) {
            $stmtAluno->execute([
                ':turma_id'  => $turmaId,
                ':nome'      => $al['nome'],
                ':cpf'       => $al['cpf'],
                ':limpo'     => $al['cpf'],
                ':mascarado' => $al['mascarado'],
            ]);
            $alunoIds[] = (int)$pdo->lastInsertId();
        }

        // Se turma for concluída, grava frequências com presenças completas
        if ($t['status'] === 'concluida') {
            foreach ($encontroIds as $encId) {
                foreach ($alunoIds as $alId) {
                    $stmtFreq->execute([
                        ':encontro_id' => $encId,
                        ':aluno_id'    => $alId,
                        ':presente'    => 1,
                    ]);
                }
            }
        }

        // Inserção de materiais didáticos vinculados à turma
        if (!empty($t['materiais'])) {
            $turmaFolder = $baseArquivosDir . '/' . $turmaId;
            if (!is_dir($turmaFolder)) {
                @mkdir($turmaFolder, 0755, true);
            }

            foreach ($t['materiais'] as $mat) {
                $caminhoRel = null;
                $tamanho = null;

                if (!empty($mat['arquivo'])) {
                    $targetFile = $turmaFolder . '/' . $mat['arquivo'];
                    file_put_contents($targetFile, $mat['conteudo'] ?? "Mock Content\n");
                    $caminhoRel = $turmaId . '/' . $mat['arquivo'];
                    $tamanho = filesize($targetFile);
                }

                $stmtMaterial->execute([
                    ':turma_id' => $turmaId,
                    ':titulo'   => $mat['titulo'],
                    ':descricao'=> $mat['descricao'] ?? null,
                    ':tipo'     => $mat['tipo'],
                    ':caminho'  => $caminhoRel,
                    ':url'      => $mat['url'] ?? null,
                    ':tamanho'  => $tamanho,
                    ':ordem'    => $mat['ordem'] ?? 0,
                    ':ativo'    => $mat['ativo'] ?? 1,
                ]);
            }
        }

        echo "  {$verde}✓ Turma inserida:{$reset} [{$t['turno_padrao']}] {$t['curso']} — {$t['cliente']} ({$t['status']})\n";
    }

    echo "\n{$verde}Seed de homologação concluído com sucesso! " . count($turmasSeed) . " turmas criadas.{$reset}\n";
    echo "{$azul}======================================================================{$reset}\n";

} catch (Throwable $e) {
    echo "\n{$vermelho}[FALHA CRÍTICA NO SEED]{$reset} " . $e->getMessage() . "\n";
    exit(1);
}
