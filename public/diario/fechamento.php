<?php
/**
 * Tela de Fechamento Assistido, Apoio ao Faturamento (NFS-e) e Emissão de Certificados
 * Plataforma Integrada de Gestão Pedagógica, Diário e Certificados Futuro Fácil
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/Database.php';
require_once __DIR__ . '/../../src/Services/AuthService.php';
require_once __DIR__ . '/../../src/Services/AttendanceService.php';
require_once __DIR__ . '/../../src/Services/CertificateService.php';
require_once __DIR__ . '/../../src/Views/layout_admin.php';

use FuturoFacil\Config\Database;
use FuturoFacil\Services\AuthService;
use FuturoFacil\Services\AttendanceService;
use FuturoFacil\Services\CertificateService;
use function FuturoFacil\Views\renderAdminLayout;

AuthService::requireAuth();

$pdo = Database::getConnection();
$certificateService = new CertificateService($pdo);

$turmaId = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);

if ($turmaId <= 0) {
    // Redireciona para a primeira turma ativa ou lista geral
    $stmtFirst = $pdo->query("SELECT id FROM turmas ORDER BY id DESC LIMIT 1");
    $turmaId = (int)$stmtFirst->fetchColumn();
    if ($turmaId <= 0) {
        header('Location: /diario/turmas');
        exit;
    }
}

// -----------------------------------------------------------------------------
// PROCESSAMENTO DE AÇÕES POST (Abono, Justificativa, Emissão)
// -----------------------------------------------------------------------------
$feedbackMessage = null;
$feedbackType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';

    if (!AuthService::verifyCsrfToken($csrfToken)) {
        $feedbackMessage = 'Token de segurança inválido ou expirado. Por favor, tente novamente.';
        $feedbackType = 'danger';
    } else {
        try {
            if ($action === 'abonar_aula') {
                $encontroId = (int)($_POST['encontro_id'] ?? 0);
                $estado = (int)($_POST['estado'] ?? 1) === 1;
                $certificateService->abonarAulaColetiva($encontroId, $estado);
                $feedbackMessage = $estado 
                    ? 'Aula coletiva abonada com sucesso! 100% de presença aplicado a toda a turma para este encontro.'
                    : 'Abono da aula coletiva revogado com sucesso.';
            } elseif ($action === 'justificar_aluno') {
                $alunoId = (int)($_POST['aluno_id'] ?? 0);
                $motivo = trim((string)($_POST['motivo'] ?? ''));
                $certificateService->justificarAluno($alunoId, $motivo);
                $feedbackMessage = 'Justificativa extraordinária homologada com sucesso! O aluno agora está apto para certificação.';
            } elseif ($action === 'remover_justificativa') {
                $alunoId = (int)($_POST['aluno_id'] ?? 0);
                $certificateService->removerJustificativa($alunoId);
                $feedbackMessage = 'Justificativa extraordinária removida. O aproveitamento do aluno retornou ao cálculo regular.';
            } elseif ($action === 'emitir_certificados') {
                $dataEmissao = trim((string)($_POST['data_emissao'] ?? date('Y-m-d')));
                $resEmissao = $certificateService->emitirCertificadosTurma($turmaId, ['data_emissao' => $dataEmissao]);

                // Armazena na sessão para download imediato
                $_SESSION['last_zip_bytes'] = $resEmissao['zip_bytes'];
                $_SESSION['last_zip_name'] = $resEmissao['zip_filename'];

                $feedbackMessage = sprintf(
                    'Parabéns! %d certificado(s) emitido(s) com sucesso com registro ininterrupto no Livro Digital! O download do pacote ZIP começará automaticamente.',
                    $resEmissao['total_emitidos']
                );
                $feedbackType = 'success';
            }
        } catch (Throwable $e) {
            $feedbackMessage = 'Erro na operação: ' . $e->getMessage();
            $feedbackType = 'danger';
        }
    }
}

// -----------------------------------------------------------------------------
// AÇÃO: DOWNLOAD DIRETO DO ZIP GERADO
// -----------------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'download_zip') {
    if (!empty($_SESSION['last_zip_bytes']) && !empty($_SESSION['last_zip_name'])) {
        $zipBytes = $_SESSION['last_zip_bytes'];
        $zipName = $_SESSION['last_zip_name'];

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zipName . '"');
        header('Content-Length: ' . strlen($zipBytes));
        header('Cache-Control: max-age=0');
        header('Pragma: public');
        echo $zipBytes;
        exit;
    }
}

// Carrega dados auditados da turma
try {
    $dados = $certificateService->getTurmaFechamentoData($turmaId);
} catch (Throwable $e) {
    header('Location: /diario/turmas');
    exit;
}

$turma = $dados['turma'];
$encontros = $dados['encontros'];
$alunosAptos = $dados['alunos_aptos'];
$alunosInaptos = $dados['alunos_inaptos'];
$csrfToken = AuthService::getCsrfToken();
$nextSeq = $certificateService->getNextSequenceNumbers();

ob_start();
?>
<div class="space-y-6">
    <!-- Cabeçalho e Navegação de Turma -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 bg-white p-6 rounded-xl border border-slate-200 shadow-sm">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <a href="/diario/turmas" class="text-xs font-semibold text-cyan-700 hover:text-cyan-800 uppercase tracking-wider flex items-center gap-1">
                    ← Voltar para Turmas
                </a>
                <span class="text-slate-300">|</span>
                <a href="/diario/turma?id=<?= $turmaId ?>" class="text-xs font-semibold text-slate-500 hover:text-slate-700">
                    Ver Diário da Turma
                </a>
            </div>
            <h1 class="text-2xl font-bold text-slate-900 tracking-tight flex items-center gap-3">
                <span>Fechamento Assistido & Emissão</span>
                <span class="text-sm font-semibold px-2.5 py-1 rounded-full <?= $turma['status'] === 'concluida' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' ?>">
                    <?= $turma['status'] === 'concluida' ? 'Turma Concluída' : 'Em Andamento' ?>
                </span>
            </h1>
            <p class="text-slate-600 text-sm mt-1">
                <span class="font-medium text-slate-800"><?= htmlspecialchars((string)$turma['curso_nome']) ?></span> ·
                <span><?= htmlspecialchars((string)($turma['cliente_nome'] ?? 'Cliente PJ')) ?></span> ·
                <span><?= (int)$turma['carga_horaria'] ?> horas</span> ·
                <span>Código: <code class="text-xs bg-slate-100 px-1 py-0.5 rounded font-mono text-slate-700"><?= htmlspecialchars((string)$turma['codigo_turma']) ?></code></span>
            </p>
        </div>

        <div class="flex items-center gap-3">
            <div class="text-right hidden md:block">
                <p class="text-xs text-slate-500">Próximo Assento Notarial</p>
                <p class="text-sm font-bold text-cyan-800">
                    Livro <?= $nextSeq['livro_numero'] ?> · Folha <?= $nextSeq['folha_numero'] ?> · Reg. <?= $nextSeq['registro_numero'] ?>
                </p>
            </div>
        </div>
    </div>

    <?php if ($feedbackMessage): ?>
        <div class="p-4 rounded-xl border <?= $feedbackType === 'success' ? 'bg-emerald-50 border-emerald-200 text-emerald-900' : 'bg-rose-50 border-rose-200 text-rose-900' ?> flex items-start gap-3 shadow-sm">
            <div class="text-xl"><?= $feedbackType === 'success' ? '✓' : '⚠' ?></div>
            <div class="flex-1 font-medium text-sm leading-relaxed"><?= htmlspecialchars($feedbackMessage) ?></div>
            <?php if (!empty($_SESSION['last_zip_bytes'])): ?>
                <a href="/diario/fechamento?turma_id=<?= $turmaId ?>&action=download_zip" class="inline-flex items-center px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold rounded-lg shadow-sm transition">
                    Baixar Pacote ZIP Agora
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Grid de Indicadores de Aproveitamento -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm">
            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Total de Alunos</p>
            <p class="text-3xl font-extrabold text-slate-900 mt-2"><?= $dados['total_alunos'] ?></p>
            <p class="text-xs text-slate-500 mt-1">Matriculados na turma</p>
        </div>

        <div class="bg-white p-5 rounded-xl border border-emerald-200 bg-emerald-50/20 shadow-sm">
            <p class="text-xs font-semibold text-emerald-800 uppercase tracking-wider flex items-center justify-between">
                <span>Alunos Aptos</span>
                <span class="text-xs bg-emerald-100 text-emerald-800 px-2 py-0.5 rounded-full font-bold">≥ 75%</span>
            </p>
            <p class="text-3xl font-extrabold text-emerald-600 mt-2"><?= $dados['total_aptos'] ?></p>
            <p class="text-xs text-emerald-700 mt-1 font-medium">Habilitados para certificação</p>
        </div>

        <div class="bg-white p-5 rounded-xl border <?= $dados['total_inaptos'] > 0 ? 'border-amber-300 bg-amber-50/30' : 'border-slate-200' ?> shadow-sm">
            <p class="text-xs font-semibold <?= $dados['total_inaptos'] > 0 ? 'text-amber-800' : 'text-slate-500' ?> uppercase tracking-wider flex items-center justify-between">
                <span>Alunos Inaptos</span>
                <span class="text-xs <?= $dados['total_inaptos'] > 0 ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-600' ?> px-2 py-0.5 rounded-full font-bold">&lt; 75%</span>
            </p>
            <p class="text-3xl font-extrabold <?= $dados['total_inaptos'] > 0 ? 'text-amber-600' : 'text-slate-900' ?> mt-2"><?= $dados['total_inaptos'] ?></p>
            <p class="text-xs text-slate-500 mt-1">Pendentes de justificativa</p>
        </div>

        <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm">
            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Aproveitamento Global</p>
            <p class="text-3xl font-extrabold text-cyan-700 mt-2"><?= $dados['aproveitamento_percentual'] ?>%</p>
            <div class="w-full bg-slate-200 rounded-full h-2 mt-2 overflow-hidden">
                <div class="bg-cyan-600 h-2 rounded-full transition-all duration-500" style="width: <?= min(100, $dados['aproveitamento_percentual']) ?>%"></div>
            </div>
        </div>
    </div>

    <!-- Seção de Ações Rápidas: Abono Coletivo & Apoio ao Faturamento -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Ferramenta: Abonar Aula Coletiva -->
        <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-2">
                    <h2 class="text-base font-bold text-slate-900 flex items-center gap-2">
                        <span>Abonar Aula Coletiva</span>
                    </h2>
                    <span class="text-xs bg-cyan-100 text-cyan-800 font-semibold px-2 py-0.5 rounded">1 Toque</span>
                </div>
                <p class="text-slate-600 text-xs leading-relaxed mb-4">
                    Permite selecionar um encontro específico (ex: feriado, reagendamento ou evento institucional) e aplicar <strong>100% de presença abonada</strong> para todos os alunos instantaneamente, recalculando os percentuais antes da emissão formal.
                </p>

                <form method="POST" class="space-y-3">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="abonar_aula">

                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Selecione o Encontro:</label>
                        <select name="encontro_id" class="w-full text-xs font-medium border-slate-300 rounded-lg p-2.5 bg-slate-50 border focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500">
                            <?php foreach ($encontros as $enc): ?>
                                <?php if ($enc['tipo'] === 'aula'): ?>
                                    <option value="<?= $enc['id'] ?>">
                                        Aula <?= $enc['numero_encontro'] ?> (<?= date('d/m/Y', strtotime($enc['data_encontro'])) ?> - <?= htmlspecialchars((string)$enc['turno']) ?>) <?= ((int)$enc['abonado'] === 1) ? '— [JÁ ABONADA]' : '' ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="flex items-center gap-2 pt-1">
                        <button type="submit" name="estado" value="1" class="flex-1 py-2 px-3 bg-cyan-700 hover:bg-cyan-800 text-white font-semibold text-xs rounded-lg shadow-sm transition">
                            Abonar para Toda a Turma
                        </button>
                        <button type="submit" name="estado" value="0" class="py-2 px-3 bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold text-xs rounded-lg transition">
                            Revogar Abono
                        </button>
                    </div>
                </form>
            </div>

            <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500">
                <span>Encontros da turma: <strong><?= count($encontros) ?></strong></span>
                <span>Aulas realizadas: <strong><?= count(array_filter($encontros, fn($e) => $e['tipo'] === 'aula')) ?></strong></span>
            </div>
        </div>

        <!-- Card: Apoio ao Faturamento / NFS-e -->
        <div class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-2">
                    <h2 class="text-base font-bold text-slate-900 flex items-center gap-2">
                        <span>Apoio ao Faturamento (NFS-e)</span>
                    </h2>
                    <span class="text-sm font-extrabold text-emerald-700 bg-emerald-50 border border-emerald-200 px-2.5 py-0.5 rounded-lg">
                        R$ <?= number_format((float)$dados['valor_calculado'], 2, ',', '.') ?>
                    </span>
                </div>
                <p class="text-slate-600 text-xs leading-relaxed mb-3">
                    Discriminação oficial dos serviços contendo Ordem de Serviço, modalidade, carga horária diária (h/dia), listagem dinâmica das datas dos encontros realizados e dados do tomador.
                </p>

                <div class="relative">
                    <textarea id="nfseTextarea" rows="5" readonly class="w-full text-xs font-mono p-3 bg-slate-50 border border-slate-200 rounded-lg text-slate-800 resize-none focus:outline-none"><?= htmlspecialchars((string)$dados['nfse_description']) ?></textarea>
                </div>
            </div>

            <div class="mt-3 flex items-center justify-between">
                <span class="text-xs text-slate-500">Pronto para emissão na Prefeitura</span>
                <button type="button" onclick="copyNfseText()" id="btnCopyNfse" class="inline-flex items-center gap-1.5 py-2 px-4 bg-slate-900 hover:bg-slate-800 text-white font-semibold text-xs rounded-lg shadow-sm transition">
                    <span id="copyIcon">📋</span>
                    <span id="copyLabel">Copiar Descrição para NFS-e</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Tabela 1: Alunos Aptos (>= 75% ou Justificados) -->
    <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
            <div>
                <h2 class="text-base font-bold text-slate-900 flex items-center gap-2">
                    <span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
                    <span>Alunos Aptos para Certificação (<?= count($alunosAptos) ?>)</span>
                </h2>
                <p class="text-xs text-slate-500 mt-0.5">Alunos com frequência regular ou deliberação extraordinária homologada</p>
            </div>
            <span class="text-xs font-bold text-emerald-800 bg-emerald-100 px-2.5 py-1 rounded-full">
                <?= count($alunosAptos) ?> aluno(s)
            </span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs text-slate-700">
                <thead class="bg-slate-50 text-slate-500 font-semibold border-b border-slate-200">
                    <tr>
                        <th class="px-5 py-3 w-12">Nº</th>
                        <th class="px-5 py-3">Nome Completo</th>
                        <th class="px-5 py-3">CPF</th>
                        <th class="px-5 py-3 text-center">Presenças</th>
                        <th class="px-5 py-3 text-center">Frequência</th>
                        <th class="px-5 py-3">Situação</th>
                        <th class="px-5 py-3 text-right">Ação</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($alunosAptos)): ?>
                        <tr>
                            <td colspan="7" class="px-5 py-8 text-center text-slate-500 font-medium">
                                Nenhum aluno apto até o momento. Utilize o abono coletivo ou autorize justificativas abaixo.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($alunosAptos as $idx => $a): ?>
                            <tr class="hover:bg-slate-50/50 transition">
                                <td class="px-5 py-3.5 font-mono text-slate-400 font-medium"><?= sprintf('%02d', $idx + 1) ?></td>
                                <td class="px-5 py-3.5 font-bold text-slate-900"><?= htmlspecialchars((string)$a['nome_completo']) ?></td>
                                <td class="px-5 py-3.5 font-mono text-slate-600"><?= htmlspecialchars((string)$a['cpf_mascarado']) ?></td>
                                <td class="px-5 py-3.5 text-center font-medium"><?= $a['total_presencas'] ?> / <?= $a['total_aulas'] ?></td>
                                <td class="px-5 py-3.5 text-center">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full font-bold text-xs bg-emerald-100 text-emerald-800">
                                        <?= $a['frequencia'] ?>%
                                    </span>
                                </td>
                                <td class="px-5 py-3.5">
                                    <?php if ($a['is_justificado']): ?>
                                        <span class="inline-flex items-center gap-1 text-xs font-semibold text-purple-800 bg-purple-100 px-2 py-0.5 rounded-full" title="<?= htmlspecialchars((string)$a['justificativa_texto']) ?>">
                                            ★ Justificativa Extraordinária
                                        </span>
                                    <?php else: ?>
                                        <span class="text-xs text-slate-600 font-medium">Frequência Regular</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-5 py-3.5 text-right">
                                    <?php if ($a['is_justificado']): ?>
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                            <input type="hidden" name="action" value="remover_justificativa">
                                            <input type="hidden" name="aluno_id" value="<?= $a['id'] ?>">
                                            <button type="submit" class="text-xs text-rose-600 hover:text-rose-800 font-medium hover:underline">
                                                Revogar Justificativa
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-xs text-emerald-600 font-medium">Aprovado</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Tabela 2: Alunos Inaptos (< 75% e sem justificativa) -->
    <?php if (!empty($alunosInaptos)): ?>
        <div class="bg-white rounded-xl border border-amber-200 shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-amber-100 flex items-center justify-between bg-amber-50/50">
                <div>
                    <h2 class="text-base font-bold text-amber-900 flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span>
                        <span>Alunos Inaptos para Certificação (<?= count($alunosInaptos) ?>)</span>
                    </h2>
                    <p class="text-xs text-amber-800 mt-0.5">Alunos abaixo do teto de 75% de frequência que não receberão certificados sem parecer formal</p>
                </div>
                <span class="text-xs font-bold text-amber-900 bg-amber-100 px-2.5 py-1 rounded-full">
                    Abaixo do Limite
                </span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs text-slate-700">
                    <thead class="bg-slate-50 text-slate-500 font-semibold border-b border-slate-200">
                        <tr>
                            <th class="px-5 py-3 w-12">Nº</th>
                            <th class="px-5 py-3">Nome Completo</th>
                            <th class="px-5 py-3">CPF</th>
                            <th class="px-5 py-3 text-center">Presenças</th>
                            <th class="px-5 py-3 text-center">Frequência</th>
                            <th class="px-5 py-3">Motivo Impeditivo</th>
                            <th class="px-5 py-3 text-right">Deliberação da Coordenação</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($alunosInaptos as $idx => $inapto): ?>
                            <tr class="hover:bg-amber-50/20 transition">
                                <td class="px-5 py-3.5 font-mono text-slate-400 font-medium"><?= sprintf('%02d', $idx + 1) ?></td>
                                <td class="px-5 py-3.5 font-bold text-slate-900"><?= htmlspecialchars((string)$inapto['nome_completo']) ?></td>
                                <td class="px-5 py-3.5 font-mono text-slate-600"><?= htmlspecialchars((string)$inapto['cpf_mascarado']) ?></td>
                                <td class="px-5 py-3.5 text-center font-medium"><?= $inapto['total_presencas'] ?> / <?= $inapto['total_aulas'] ?></td>
                                <td class="px-5 py-3.5 text-center">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full font-bold text-xs bg-amber-100 text-amber-900">
                                        <?= $inapto['frequencia'] ?>%
                                    </span>
                                </td>
                                <td class="px-5 py-3.5 text-rose-700 font-medium">
                                    Faltas excessivas (&lt; 75%)
                                </td>
                                <td class="px-5 py-3.5 text-right">
                                    <button type="button" onclick="openJustificativaModal(<?= $inapto['id'] ?>, '<?= htmlspecialchars(addslashes((string)$inapto['nome_completo'])) ?>', '<?= $inapto['frequencia'] ?>%')" class="py-1 px-2.5 bg-amber-100 hover:bg-amber-200 text-amber-900 font-semibold rounded text-xs transition">
                                        Autorizar Justificativa Extraordinária
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- Seção de Confirmação e Emissão Notarial -->
    <div class="bg-gradient-to-r from-slate-900 via-cyan-950 to-slate-900 p-6 md:p-8 rounded-xl text-white shadow-md">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-6">
            <div class="max-w-2xl">
                <span class="text-xs font-bold text-cyan-400 uppercase tracking-wider bg-cyan-950/80 px-2.5 py-1 rounded-full border border-cyan-800">
                    Etapa Final de Conclusão da Turma
                </span>
                <h3 class="text-xl font-bold mt-3">Confirmar e Emitir Certificados Duplex</h3>
                <p class="text-slate-300 text-xs md:text-sm mt-1 leading-relaxed">
                    Ao confirmar, o sistema gravará atomicamente os assentos no Livro de Registro Digital, gerará os hashes SHA-256 exclusivos, renderizará os PDFs A4 duplex (frente com guilloché e verso com QR Code) e disponibilizará o pacote ZIP completo com o consolidado da gráfica e a planilha Excel atualizada.
                </p>
                <div class="flex items-center gap-4 mt-3 text-xs text-cyan-200 font-mono">
                    <span>• Sequenciamento: Livro <?= $nextSeq['livro_numero'] ?>, Folha <?= $nextSeq['folha_numero'] ?>, Registro <?= $nextSeq['registro_numero'] ?></span>
                    <span>• Certificados a emitir: <strong><?= count($alunosAptos) ?></strong></span>
                </div>
            </div>

            <div class="flex flex-col sm:flex-row items-stretch md:items-center gap-3">
                <?php if ($dados['ja_emitida']): ?>
                    <p role="status" class="text-sm font-semibold text-emerald-200">Turma já emitida. A emissão de novos assentos está bloqueada.</p>
                <?php endif; ?>
                <form method="POST" onsubmit="return confirm('Deseja realmente formalizar a conclusão da turma e emitir os <?= count($alunosAptos) ?> certificados oficiais no Livro de Registro?');">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="emitir_certificados">
                    <input type="hidden" name="data_emissao" value="<?= date('Y-m-d') ?>">

                    <button type="submit" <?= (empty($alunosAptos) || $dados['ja_emitida']) ? 'disabled' : '' ?> class="w-full sm:w-auto py-3 px-6 bg-cyan-500 hover:bg-cyan-400 disabled:opacity-50 disabled:cursor-not-allowed text-slate-950 font-bold text-sm rounded-xl shadow-lg transition flex items-center justify-center gap-2">
                        <span><?= $dados['ja_emitida'] ? 'Turma já emitida' : '🎓 Confirmar e Emitir Certificados' ?></span>
                    </button>
                </form>

                <?php if ($dados['ja_emitida']): ?>
                    <a href="/diario/turma?id=<?= $turmaId ?>&action=export" class="py-3 px-4 bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold rounded-xl text-center transition">
                        Exportar Excel da Turma
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Modal de Justificativa Extraordinária -->
<div id="modalJustificativa" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center hidden">
    <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl border border-slate-200 m-4">
        <h3 class="text-base font-bold text-slate-900 flex items-center gap-2">
            <span>Autorizar Justificativa Extraordinária</span>
        </h3>
        <p class="text-xs text-slate-600 mt-1">
            Aluno: <strong id="modalAlunoNome" class="text-slate-900"></strong> (Frequência: <span id="modalAlunoFreq" class="font-bold text-amber-700"></span>)
        </p>

        <form method="POST" class="mt-4 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="justificar_aluno">
            <input type="hidden" name="aluno_id" id="modalAlunoId" value="0">

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Parecer Formal / Motivo da Coordenação:</label>
                <textarea name="motivo" rows="3" required placeholder="Ex: Atestado médico homologado pela gerência de RH do tomador / Reposição de conteúdo realizada..." class="w-full text-xs border border-slate-300 rounded-lg p-3 text-slate-800 focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500 resize-none"></textarea>
                <p class="text-[11px] text-slate-500 mt-1">
                    Este texto constará nos registros de auditoria pedagógica da instituição.
                </p>
            </div>

            <div class="flex items-center justify-end gap-2 pt-2">
                <button type="button" onclick="closeJustificativaModal()" class="py-2 px-4 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold rounded-lg transition">
                    Cancelar
                </button>
                <button type="submit" class="py-2 px-4 bg-cyan-700 hover:bg-cyan-800 text-white text-xs font-semibold rounded-lg shadow-sm transition">
                    Homologar e Promover a Apto
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function copyNfseText() {
    const textarea = document.getElementById('nfseTextarea');
    if (!textarea) return;

    textarea.select();
    textarea.setSelectionRange(0, 99999);

    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(textarea.value).then(() => {
            showCopiedFeedback();
        }).catch(() => {
            document.execCommand('copy');
            showCopiedFeedback();
        });
    } else {
        document.execCommand('copy');
        showCopiedFeedback();
    }
}

function showCopiedFeedback() {
    const btn = document.getElementById('btnCopyNfse');
    const label = document.getElementById('copyLabel');
    const icon = document.getElementById('copyIcon');
    if (!btn || !label || !icon) return;

    const originalText = label.innerText;
    label.innerText = 'Copiado com Sucesso!';
    icon.innerText = '✓';
    btn.classList.remove('bg-slate-900', 'hover:bg-slate-800');
    btn.classList.add('bg-emerald-600', 'hover:bg-emerald-700');

    setTimeout(() => {
        label.innerText = originalText;
        icon.innerText = '📋';
        btn.classList.remove('bg-emerald-600', 'hover:bg-emerald-700');
        btn.classList.add('bg-slate-900', 'hover:bg-slate-800');
    }, 2500);
}

function openJustificativaModal(alunoId, alunoNome, alunoFreq) {
    document.getElementById('modalAlunoId').value = alunoId;
    document.getElementById('modalAlunoNome').innerText = alunoNome;
    document.getElementById('modalAlunoFreq').innerText = alunoFreq;
    document.getElementById('modalJustificativa').classList.remove('hidden');
}

function closeJustificativaModal() {
    document.getElementById('modalJustificativa').classList.add('hidden');
}
</script>
<?php
$content = ob_get_clean();
renderAdminLayout('Fechamento Assistido & Emissão', 'turmas', $content);
