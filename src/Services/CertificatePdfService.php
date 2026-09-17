<?php
/**
 * Motor Vetorial de Renderização de Certificados em PDF Duplex (A4 Paisagem)
 * Plataforma Integrada de Gestão Pedagógica, Diário e Certificados Futuro Fácil
 * 
 * Implementação nativa em PHP compatível com ISO 32000-1 / PDF 1.4:
 * - Anverso: Fundo off-white, moldura guilloché com 16 harmônicos contínuos em L, wordmark FUTUROFÁCIL, assento digital, texto de concessão formal.
 * - Reverso: Ementa detalhada, assento oficial, QR Code vetorial nativo para validação pública e hash SHA-256 em blocos monoespaçados.
 * - Suporta emissão de certificados individuais duplex (2 páginas) e PDF consolidado para gráfica (2 * N páginas intercaladas).
 */

declare(strict_types=1);

namespace FuturoFacil\Services;

require_once __DIR__ . '/../Utils/QRCodeGenerator.php';
require_once __DIR__ . '/ValidatorService.php';

use FuturoFacil\Utils\QRCodeGenerator;
use InvalidArgumentException;

class CertificatePdfService
{
    public const PAGE_WIDTH = 841.89;  // A4 Paisagem (297 mm em pt)
    public const PAGE_HEIGHT = 595.28; // A4 Paisagem (210 mm em pt)

    private const COLOR_PRIMARY = [0.055, 0.455, 0.565];   // #0E7490 (Petróleo Tech)
    private const COLOR_SECONDARY = [0.918, 0.345, 0.047]; // #EA580C (Coral Solar)
    private const COLOR_BG = [0.980, 0.988, 1.000];        // #FAFCFF (Off-white)
    private const COLOR_TEXT_DARK = [0.094, 0.161, 0.231]; // #18293B
    private const COLOR_TEXT_MUTED = [0.392, 0.455, 0.545];// #64748B
    private const COLOR_BORDER_LIGHT = [0.886, 0.910, 0.941]; // #E2E8F0

    /**
     * Converte a carga horária em horas para texto formal por extenso em português.
     */
    public static function formatCargaHorariaExtenso(int $horas): string
    {
        if ($horas <= 0) {
            return "zero horas";
        }
        if ($horas === 1) {
            return "uma hora";
        }

        $unidades = [
            0 => "", 1 => "um", 2 => "duas", 3 => "três", 4 => "quatro", 5 => "cinco",
            6 => "seis", 7 => "sete", 8 => "oito", 9 => "nove", 10 => "dez",
            11 => "onze", 12 => "doze", 13 => "treze", 14 => "quatorze", 15 => "quinze",
            16 => "dezesseis", 17 => "dezessete", 18 => "dezoito", 19 => "dezenove"
        ];
        $dezenas = [
            2 => "vinte", 3 => "trinta", 4 => "quarenta", 5 => "cinquenta",
            6 => "sessenta", 7 => "setenta", 8 => "oitenta", 9 => "noventa"
        ];
        $centenas = [
            1 => "cento", 2 => "duzentas", 3 => "trezentas", 4 => "quatrocentas",
            5 => "quinhentas", 6 => "seiscentas", 7 => "setecentas", 8 => "oitocentas", 9 => "novecentas"
        ];

        if ($horas === 100) {
            return "cem horas";
        }

        if ($horas < 20) {
            $ext = $unidades[$horas];
            if ($horas === 2) {
                return "duas horas";
            }
            return "{$ext} horas";
        }

        if ($horas < 100) {
            $d = (int)floor($horas / 10);
            $u = $horas % 10;
            if ($u === 0) {
                return "{$dezenas[$d]} horas";
            }
            $uStr = ($u === 1) ? "uma" : (($u === 2) ? "duas" : $unidades[$u]);
            return "{$dezenas[$d]} e {$uStr} horas";
        }

        if ($horas < 1000) {
            $c = (int)floor($horas / 100);
            $resto = $horas % 100;
            if ($resto === 0) {
                return "{$centenas[$c]} horas";
            }
            $restoExt = self::formatCargaHorariaExtenso($resto);
            $restoExt = preg_replace('/ horas?$/', '', $restoExt);
            return "{$centenas[$c]} e {$restoExt} horas";
        }

        return "{$horas} horas";
    }

    /**
     * Formata datas para formato por extenso formal (ex: '15 de outubro de 2026').
     */
    public static function formatDateExtenso(string $dataStr): string
    {
        $meses = [
            1 => "janeiro", 2 => "fevereiro", 3 => "março", 4 => "abril",
            5 => "maio", 6 => "junho", 7 => "julho", 8 => "agosto",
            9 => "setembro", 10 => "outubro", 11 => "novembro", 12 => "dezembro"
        ];

        $ts = strtotime($dataStr);
        if ($ts === false) {
            return $dataStr;
        }

        $dia = (int)date('j', $ts);
        $mes = (int)date('n', $ts);
        $ano = (int)date('Y', $ts);

        return "{$dia} de {$meses[$mes]} de {$ano}";
    }

    /**
     * Renderiza o certificado individual duplex (2 páginas: Anverso e Reverso).
     */
    public function renderCertificateDuplex(array $aluno, array $turma, array $registro): string
    {
        $doc = new SimplePdfDocument(self::PAGE_WIDTH, self::PAGE_HEIGHT);

        // Página 1: Anverso
        $streamAnverso = $this->buildAnversoStream($aluno, $turma, $registro);
        $doc->addPage($streamAnverso);

        // Página 2: Reverso
        $streamReverso = $this->buildReversoStream($aluno, $turma, $registro);
        $doc->addPage($streamReverso);

        return $doc->output();
    }

    /**
     * Renderiza o PDF consolidado para gráfica (2 * N páginas intercaladas ordenadas).
     * [Frente 1, Verso 1, Frente 2, Verso 2, ..., Frente N, Verso N]
     */
    public function renderConsolidatedDuplex(array $loteAlunos): string
    {
        $doc = new SimplePdfDocument(self::PAGE_WIDTH, self::PAGE_HEIGHT);

        foreach ($loteAlunos as $item) {
            $aluno = $item['aluno'];
            $turma = $item['turma'];
            $registro = $item['registro'];

            $streamAnverso = $this->buildAnversoStream($aluno, $turma, $registro);
            $doc->addPage($streamAnverso);

            $streamReverso = $this->buildReversoStream($aluno, $turma, $registro);
            $doc->addPage($streamReverso);
        }

        return $doc->output();
    }

    /**
     * Constrói o fluxo de desenho vetorial do Anverso (Página 1).
     */
    private function buildAnversoStream(array $aluno, array $turma, array $registro): string
    {
        $p = "";

        // 1. Fundo Off-White (#FAFCFF)
        $p .= sprintf("%.3f %.3f %.3f rg\n", self::COLOR_BG[0], self::COLOR_BG[1], self::COLOR_BG[2]);
        $p .= sprintf("0 0 %.2f %.2f re f\n", self::PAGE_WIDTH, self::PAGE_HEIGHT);

        // 2. Moldura de Segurança com Linhas Guia Perimétricas
        $margin = 24.0;
        // Linha externa (#0E7490)
        $p .= sprintf("%.3f %.3f %.3f RG\n", self::COLOR_PRIMARY[0], self::COLOR_PRIMARY[1], self::COLOR_PRIMARY[2]);
        $p .= "0.65 w\n";
        $p .= sprintf("%.2f %.2f %.2f %.2f re S\n", $margin + 2, $margin + 2, self::PAGE_WIDTH - ($margin + 2) * 2, self::PAGE_HEIGHT - ($margin + 2) * 2);

        // Linha interna (#EA580C)
        $p .= sprintf("%.3f %.3f %.3f RG\n", self::COLOR_SECONDARY[0], self::COLOR_SECONDARY[1], self::COLOR_SECONDARY[2]);
        $p .= "0.35 w\n";
        $p .= sprintf("%.2f %.2f %.2f %.2f re S\n", $margin + 6, $margin + 6, self::PAGE_WIDTH - ($margin + 6) * 2, self::PAGE_HEIGHT - ($margin + 6) * 2);

        // 3. Fita Contínua em L de Guilloché Vetorial (16 ondas harmônicas micrométricas)
        $p .= $this->renderGuillocheLRibbon();

        // 4. Marca Institucional FUTUROFÁCIL (Wordmark)
        $p .= sprintf("%.3f %.3f %.3f rg\n", self::COLOR_PRIMARY[0], self::COLOR_PRIMARY[1], self::COLOR_PRIMARY[2]);
        $p .= "BT /F2 20 Tf 1.5 Tc 56 536 Td (FUTUROFACIL) Tj ET\n";
        $p .= sprintf("%.3f %.3f %.3f rg\n", self::COLOR_TEXT_MUTED[0], self::COLOR_TEXT_MUTED[1], self::COLOR_TEXT_MUTED[2]);
        $p .= "BT /F1 7 Tf 1.2 Tc 56 523 Td (CAPACITACAO DIGITAL SOB MEDIDA) Tj ET\n";

        // 5. Card do Assento Digital Notarial (Canto Superior Direito)
        $cardX = 540.0;
        $cardY = 512.0;
        $cardW = 246.0;
        $cardH = 44.0;
        // Caixa com fundo e borda
        $p .= "q\n";
        $p .= "0.973 0.980 0.988 rg\n"; // #F8FAFC
        $p .= sprintf("%.3f %.3f %.3f RG\n", self::COLOR_BORDER_LIGHT[0], self::COLOR_BORDER_LIGHT[1], self::COLOR_BORDER_LIGHT[2]);
        $p .= "0.75 w\n";
        $p .= sprintf("%.2f %.2f %.2f %.2f re B\n", $cardX, $cardY, $cardW, $cardH);
        $p .= sprintf("%.3f %.3f %.3f rg\n", self::COLOR_TEXT_MUTED[0], self::COLOR_TEXT_MUTED[1], self::COLOR_TEXT_MUTED[2]);
        $p .= sprintf("BT /F2 6.5 Tf 0.8 Tc %.2f %.2f Td (ASSENTO DIGITAL NOTARIAL) Tj ET\n", $cardX + 10, $cardY + 30);
        $p .= sprintf("%.3f %.3f %.3f rg\n", self::COLOR_PRIMARY[0], self::COLOR_PRIMARY[1], self::COLOR_PRIMARY[2]);
        $assentoTxt = sprintf("LIVRO: %d   FOLHA: %d   REGISTRO: %d", (int)$registro['livro_numero'], (int)$registro['folha_numero'], (int)$registro['registro_numero']);
        $p .= sprintf("BT /F2 8.5 Tf 0 Tc %.2f %.2f Td (%s) Tj ET\n", $cardX + 10, $cardY + 18, self::escapePdfString($assentoTxt));
        $codigoCurto = substr((string)$registro['codigo_autenticidade'], 0, 24) . "...";
        $p .= sprintf("%.3f %.3f %.3f rg\n", self::COLOR_TEXT_DARK[0], self::COLOR_TEXT_DARK[1], self::COLOR_TEXT_DARK[2]);
        $p .= sprintf("BT /F3 6.5 Tf 0 Tc %.2f %.2f Td (AUTENTICIDADE: %s) Tj ET\n", $cardX + 10, $cardY + 8, $codigoCurto);
        $p .= "Q\n";

        // 6. Cabeçalho Principal do Certificado
        $p .= sprintf("%.3f %.3f %.3f rg\n", self::COLOR_PRIMARY[0], self::COLOR_PRIMARY[1], self::COLOR_PRIMARY[2]);
        $p .= "BT /F2 32 Tf 2 Tc 56 440 Td (CERTIFICADO) Tj ET\n";
        $p .= sprintf("%.3f %.3f %.3f rg\n", self::COLOR_SECONDARY[0], self::COLOR_SECONDARY[1], self::COLOR_SECONDARY[2]);
        $p .= "BT /F2 9 Tf 1.2 Tc 56 422 Td (CONCESSAO DE CERTIFICADO DE CONCLUSÃO DE CURSO LIVRE) Tj ET\n";

        // Linha divisória
        $p .= "0.80 0.83 0.88 RG\n";
        $p .= "0.5 w\n";
        $p .= "56 412 m 786 412 l S\n";

        // 7. Texto Formal de Concessão
        $alunoNome = mb_strtoupper((string)$aluno['nome_completo'], 'UTF-8');
        $alunoCpf = ValidatorService::formatCpf((string)$aluno['cpf']);
        $cursoNome = mb_strtoupper((string)$turma['curso_nome'], 'UTF-8');
        $cargaHoraria = (int)$turma['carga_horaria'];
        $cargaExtenso = self::formatCargaHorariaExtenso($cargaHoraria);
        $dataInicio = self::formatDateExtenso((string)$turma['data_inicio']);
        $dataConclusao = self::formatDateExtenso((string)$turma['data_conclusao']);
        $instrutor = (string)($turma['instrutor'] ?? 'Tel Santana Leite');
        $cidade = (string)($turma['cidade'] ?? 'Goiânia');
        $frequencia = (int)($registro['frequencia'] ?? 100);

        $p .= sprintf("%.3f %.3f %.3f rg\n", self::COLOR_TEXT_DARK[0], self::COLOR_TEXT_DARK[1], self::COLOR_TEXT_DARK[2]);
        $introText = "Certificamos que, para os devidos fins de direito, sob as normas da Lei Federal nº 9.394/1996 e Decreto nº 5.154/2004:";
        $p .= sprintf("BT /F1 11 Tf 0 Tc 56 386 Td (%s) Tj ET\n", self::escapePdfString($introText));

        // Nome do Aluno em Destaque
        $p .= sprintf("%.3f %.3f %.3f rg\n", self::COLOR_PRIMARY[0], self::COLOR_PRIMARY[1], self::COLOR_PRIMARY[2]);
        $p .= sprintf("BT /F2 20 Tf 0.5 Tc 56 352 Td (%s) Tj ET\n", self::escapePdfString($alunoNome));

        // CPF e Aproveitamento
        $p .= sprintf("%.3f %.3f %.3f rg\n", self::COLOR_TEXT_DARK[0], self::COLOR_TEXT_DARK[1], self::COLOR_TEXT_DARK[2]);
        $cpfTexto = sprintf("portador(a) do CPF nº %s, concluiu com êxito e aproveitamento com frequência de %d%% o curso livre de capacitação profissional em:", $alunoCpf, $frequencia);
        $p .= sprintf("BT /F1 11 Tf 0 Tc 56 332 Td (%s) Tj ET\n", self::escapePdfString($cpfTexto));

        // Nome do Curso em Caixa Alta
        $p .= sprintf("%.3f %.3f %.3f rg\n", self::COLOR_SECONDARY[0], self::COLOR_SECONDARY[1], self::COLOR_SECONDARY[2]);
        $p .= sprintf("BT /F2 16 Tf 0.5 Tc 56 304 Td (%s) Tj ET\n", self::escapePdfString($cursoNome));

        // Detalhes da Carga Horária e Período
        $p .= sprintf("%.3f %.3f %.3f rg\n", self::COLOR_TEXT_DARK[0], self::COLOR_TEXT_DARK[1], self::COLOR_TEXT_DARK[2]);
        $detalhesTexto = sprintf(
            "com carga horária total de %d horas (%s), realizado no período de %s a %s,",
            $cargaHoraria,
            $cargaExtenso,
            $dataInicio,
            $dataConclusao
        );
        $p .= sprintf("BT /F1 11 Tf 0 Tc 56 280 Td (%s) Tj ET\n", self::escapePdfString($detalhesTexto));

        $regenciaTexto = sprintf(
            "sob a coordenação e regência do(a) instrutor(a) %s, ministrado na modalidade %s, na cidade de %s.",
            $instrutor,
            (string)$turma['modalidade'],
            $cidade
        );
        $p .= sprintf("BT /F1 11 Tf 0 Tc 56 262 Td (%s) Tj ET\n", self::escapePdfString($regenciaTexto));

        // 8. Área de Assinaturas (Instrutor e Discente)
        // Linha Instrutor (Esquerda)
        $p .= "0.58 0.64 0.72 RG\n0.8 w\n";
        $p .= "110 106 m 360 106 l S\n";
        $p .= sprintf("%.3f %.3f %.3f rg\n", self::COLOR_TEXT_DARK[0], self::COLOR_TEXT_DARK[1], self::COLOR_TEXT_DARK[2]);
        $p .= sprintf("BT /F2 9.5 Tf 0 Tc 110 92 Td (%s) Tj ET\n", self::escapePdfString($instrutor));
        $p .= sprintf("%.3f %.3f %.3f rg\n", self::COLOR_TEXT_MUTED[0], self::COLOR_TEXT_MUTED[1], self::COLOR_TEXT_MUTED[2]);
        $p .= "BT /F1 7.5 Tf 0.5 Tc 110 80 Td (Instrutor\(a\) Responsável · Futuro Fácil) Tj ET\n";

        // Linha Discente (Direita)
        $p .= "0.58 0.64 0.72 RG\n0.8 w\n";
        $p .= "480 106 m 730 106 l S\n";
        $p .= sprintf("%.3f %.3f %.3f rg\n", self::COLOR_TEXT_DARK[0], self::COLOR_TEXT_DARK[1], self::COLOR_TEXT_DARK[2]);
        $p .= sprintf("BT /F2 9.5 Tf 0 Tc 480 92 Td (%s) Tj ET\n", self::escapePdfString($alunoNome));
        $p .= sprintf("%.3f %.3f %.3f rg\n", self::COLOR_TEXT_MUTED[0], self::COLOR_TEXT_MUTED[1], self::COLOR_TEXT_MUTED[2]);
        $p .= "BT /F1 7.5 Tf 0.5 Tc 480 80 Td (Discente Concluinte) Tj ET\n";

        // 9. Rodapé Jurídico
        $p .= sprintf("%.3f %.3f %.3f rg\n", self::COLOR_TEXT_MUTED[0], self::COLOR_TEXT_MUTED[1], self::COLOR_TEXT_MUTED[2]);
        $footer = "Certificado oficial de conclusão de curso livre emitido em conformidade com o Decreto Presidencial nº 5.154/2004 e LDB nº 9.394/1996 · Futuro Fácil";
        $p .= sprintf("BT /F1 6.5 Tf 0.5 Tc 140 42 Td (%s) Tj ET\n", self::escapePdfString($footer));

        return $p;
    }

    /**
     * Constrói o fluxo de desenho vetorial do Reverso (Página 2).
     */
    private function buildReversoStream(array $aluno, array $turma, array $registro): string
    {
        $p = "";

        // 1. Fundo Off-White
        $p .= sprintf("%.3f %.3f %.3f rg\n", self::COLOR_BG[0], self::COLOR_BG[1], self::COLOR_BG[2]);
        $p .= sprintf("0 0 %.2f %.2f re f\n", self::PAGE_WIDTH, self::PAGE_HEIGHT);

        // 2. Moldura Perimétrica
        $margin = 24.0;
        $p .= sprintf("%.3f %.3f %.3f RG\n", self::COLOR_PRIMARY[0], self::COLOR_PRIMARY[1], self::COLOR_PRIMARY[2]);
        $p .= "0.65 w\n";
        $p .= sprintf("%.2f %.2f %.2f %.2f re S\n", $margin + 2, $margin + 2, self::PAGE_WIDTH - ($margin + 2) * 2, self::PAGE_HEIGHT - ($margin + 2) * 2);
        $p .= sprintf("%.3f %.3f %.3f RG\n", self::COLOR_SECONDARY[0], self::COLOR_SECONDARY[1], self::COLOR_SECONDARY[2]);
        $p .= "0.35 w\n";
        $p .= sprintf("%.2f %.2f %.2f %.2f re S\n", $margin + 6, $margin + 6, self::PAGE_WIDTH - ($margin + 6) * 2, self::PAGE_HEIGHT - ($margin + 6) * 2);

        // 3. Cabeçalho Estrutural Notarial
        $p .= sprintf("%.3f %.3f %.3f rg\n", self::COLOR_PRIMARY[0], self::COLOR_PRIMARY[1], self::COLOR_PRIMARY[2]);
        $p .= "BT /F2 16 Tf 1 Tc 56 536 Td (REGISTRO OFICIAL E EMENTA PROGRAMÁTICA) Tj ET\n";
        $p .= sprintf("%.3f %.3f %.3f rg\n", self::COLOR_TEXT_MUTED[0], self::COLOR_TEXT_MUTED[1], self::COLOR_TEXT_MUTED[2]);
        $p .= "BT /F1 7.5 Tf 1 Tc 56 522 Td (DETALHAMENTO PEDAGOGICO, CRIPTOGRAFIA DE SEGURANCA E VALIDACAO PUBLICA PERPETUA) Tj ET\n";

        // Linha divisória
        $p .= "0.80 0.83 0.88 RG\n0.5 w\n56 512 m 786 512 l S\n";

        // 4. Coluna Esquerda: Ementa e Conteúdo Programático
        $colLeftX = 56.0;
        $colLeftW = 450.0;
        $yPos = 492.0;

        $p .= sprintf("%.3f %.3f %.3f rg\n", self::COLOR_TEXT_DARK[0], self::COLOR_TEXT_DARK[1], self::COLOR_TEXT_DARK[2]);
        $cursoTitle = "CURSO: " . mb_strtoupper((string)$turma['curso_nome'], 'UTF-8');
        $p .= sprintf("BT /F2 10 Tf 0 Tc %.2f %.2f Td (%s) Tj ET\n", $colLeftX, $yPos, self::escapePdfString($cursoTitle));
        $yPos -= 14;

        $cargaStr = sprintf("Carga Horária: %dh (%s)   |   Modalidade: %s", (int)$turma['carga_horaria'], self::formatCargaHorariaExtenso((int)$turma['carga_horaria']), (string)$turma['modalidade']);
        $p .= sprintf("%.3f %.3f %.3f rg\n", self::COLOR_TEXT_MUTED[0], self::COLOR_TEXT_MUTED[1], self::COLOR_TEXT_MUTED[2]);
        $p .= sprintf("BT /F1 8 Tf 0 Tc %.2f %.2f Td (%s) Tj ET\n", $colLeftX, $yPos, self::escapePdfString($cargaStr));
        $yPos -= 20;

        $p .= sprintf("%.3f %.3f %.3f rg\n", self::COLOR_PRIMARY[0], self::COLOR_PRIMARY[1], self::COLOR_PRIMARY[2]);
        $p .= sprintf("BT /F2 9.5 Tf 0.5 Tc %.2f %.2f Td (EMENTA E CONTEUDO PROGRAMATICO DETALHADO:) Tj ET\n", $colLeftX, $yPos);
        $yPos -= 16;

        // Ementa dividida em tópicos
        $ementaTexto = trim((string)($turma['ementa'] ?? ''));
        if (empty($ementaTexto)) {
            $ementaTexto = "1. Fundamentos e Conceitos Estruturais;\n2. Aplicações Práticas e Estudos de Caso;\n3. Melhores Práticas e Governança Operacional;\n4. Exercícios Aplicados e Avaliação Final de Aproveitamento.";
        }
        $linhasEmenta = explode("\n", $ementaTexto);
        $p .= sprintf("%.3f %.3f %.3f rg\n", self::COLOR_TEXT_DARK[0], self::COLOR_TEXT_DARK[1], self::COLOR_TEXT_DARK[2]);
        foreach ($linhasEmenta as $linha) {
            $l = trim($linha);
            if (!empty($l)) {
                $p .= sprintf("BT /F1 8 Tf 0 Tc %.2f %.2f Td (%s) Tj ET\n", $colLeftX + 6, $yPos, self::escapePdfString($l));
                $yPos -= 13;
            }
        }

        // Fundamentação Jurídica na Coluna Esquerda
        $boxJuridicoY = 70.0;
        $boxJuridicoH = 50.0;
        $p .= "q\n";
        $p .= "0.973 0.980 0.988 rg\n";
        $p .= sprintf("%.3f %.3f %.3f RG\n", self::COLOR_BORDER_LIGHT[0], self::COLOR_BORDER_LIGHT[1], self::COLOR_BORDER_LIGHT[2]);
        $p .= "0.5 w\n";
        $p .= sprintf("%.2f %.2f %.2f %.2f re B\n", $colLeftX, $boxJuridicoY, $colLeftW, $boxJuridicoH);
        $p .= sprintf("%.3f %.3f %.3f rg\n", self::COLOR_PRIMARY[0], self::COLOR_PRIMARY[1], self::COLOR_PRIMARY[2]);
        $p .= sprintf("BT /F2 7 Tf 0.5 Tc %.2f %.2f Td (FUNDAMENTACAO JURIDICA E VALIDADE NACIONAL) Tj ET\n", $colLeftX + 8, $boxJuridicoY + 36);
        $p .= sprintf("%.3f %.3f %.3f rg\n", self::COLOR_TEXT_MUTED[0], self::COLOR_TEXT_MUTED[1], self::COLOR_TEXT_MUTED[2]);
        $jurTxt1 = "Documento expedido em consonância com a Lei nº 9.394/1996 (arts. 39 a 42), Decreto Presidencial nº 5.154/2004";
        $jurTxt2 = "e Deliberação CEE. Válido para comprovação de capacitação continuada, horas extracurriculares e concursos.";
        $p .= sprintf("BT /F1 6.5 Tf 0 Tc %.2f %.2f Td (%s) Tj ET\n", $colLeftX + 8, $boxJuridicoY + 24, self::escapePdfString($jurTxt1));
        $p .= sprintf("BT /F1 6.5 Tf 0 Tc %.2f %.2f Td (%s) Tj ET\n", $colLeftX + 8, $boxJuridicoY + 12, self::escapePdfString($jurTxt2));
        $p .= "Q\n";

        // 5. Coluna Direita: Assento, QR Code Vetorial e Hash SHA-256
        $colRightX = 530.0;
        $colRightW = 256.0;

        // Card do Assento Oficial
        $p .= "q\n";
        $p .= "0.973 0.980 0.988 rg\n";
        $p .= sprintf("%.3f %.3f %.3f RG\n", self::COLOR_BORDER_LIGHT[0], self::COLOR_BORDER_LIGHT[1], self::COLOR_BORDER_LIGHT[2]);
        $p .= "0.75 w\n";
        $p .= sprintf("%.2f 448 %.2f 48 re B\n", $colRightX, $colRightW);
        $p .= sprintf("%.3f %.3f %.3f rg\n", self::COLOR_TEXT_MUTED[0], self::COLOR_TEXT_MUTED[1], self::COLOR_TEXT_MUTED[2]);
        $p .= sprintf("BT /F2 6.5 Tf 0.8 Tc %.2f 482 Td (LIVRO DE REGISTRO DIGITAL NOTARIAL) Tj ET\n", $colRightX + 10);
        $p .= sprintf("%.3f %.3f %.3f rg\n", self::COLOR_PRIMARY[0], self::COLOR_PRIMARY[1], self::COLOR_PRIMARY[2]);
        $assentoFull = sprintf("Livro: %d   |   Folha: %d   |   Registro: %d", (int)$registro['livro_numero'], (int)$registro['folha_numero'], (int)$registro['registro_numero']);
        $p .= sprintf("BT /F2 9.5 Tf 0 Tc %.2f 468 Td (%s) Tj ET\n", $colRightX + 10, self::escapePdfString($assentoFull));
        $dataEmissaoStr = self::formatDateExtenso((string)$registro['data_emissao']);
        $p .= sprintf("%.3f %.3f %.3f rg\n", self::COLOR_TEXT_DARK[0], self::COLOR_TEXT_DARK[1], self::COLOR_TEXT_DARK[2]);
        $p .= sprintf("BT /F1 7.5 Tf 0 Tc %.2f 456 Td (Data de Emissao Oficial: %s) Tj ET\n", $colRightX + 10, self::escapePdfString($dataEmissaoStr));
        $p .= "Q\n";

        // Card do QR Code Vetorial
        $qrBoxY = 248.0;
        $qrBoxH = 188.0;
        $p .= "q\n";
        $p .= "1 1 1 rg\n";
        $p .= sprintf("%.3f %.3f %.3f RG\n", self::COLOR_BORDER_LIGHT[0], self::COLOR_BORDER_LIGHT[1], self::COLOR_BORDER_LIGHT[2]);
        $p .= "0.75 w\n";
        $p .= sprintf("%.2f %.2f %.2f %.2f re B\n", $colRightX, $qrBoxY, $colRightW, $qrBoxH);

        // Título acima do QR
        $p .= sprintf("%.3f %.3f %.3f rg\n", self::COLOR_PRIMARY[0], self::COLOR_PRIMARY[1], self::COLOR_PRIMARY[2]);
        $p .= sprintf("BT /F2 8.5 Tf 0.5 Tc %.2f %.2f Td (CONSULTA PUBLICA DE AUTENTICIDADE) Tj ET\n", $colRightX + 22, $qrBoxY + 168);

        // QR Code Vetorial
        $codigoHash = strtoupper((string)$registro['codigo_autenticidade']);
        $qrUrl = "https://futurofacil.com.br/validar?validar={$codigoHash}";
        $p .= $this->renderVectorQrCode($qrUrl, $colRightX + 68, $qrBoxY + 44, 120.0);

        // Subtítulo sob o QR
        $p .= sprintf("%.3f %.3f %.3f rg\n", self::COLOR_TEXT_MUTED[0], self::COLOR_TEXT_MUTED[1], self::COLOR_TEXT_MUTED[2]);
        $p .= sprintf("BT /F1 6.5 Tf 0 Tc %.2f %.2f Td (Aponte a camera para checar a autenticidade oficial) Tj ET\n", $colRightX + 26, $qrBoxY + 28);
        $p .= sprintf("%.3f %.3f %.3f rg\n", self::COLOR_PRIMARY[0], self::COLOR_PRIMARY[1], self::COLOR_PRIMARY[2]);
        $p .= sprintf("BT /F2 7 Tf 0 Tc %.2f %.2f Td (futurofacil.com.br/validar) Tj ET\n", $colRightX + 80, $qrBoxY + 16);
        $p .= "Q\n";

        // Card do Hash SHA-256 em Blocos Monoespaçados
        $hashBoxY = 70.0;
        $hashBoxH = 168.0;
        $p .= "q\n";
        $p .= "0.973 0.980 0.988 rg\n";
        $p .= sprintf("%.3f %.3f %.3f RG\n", self::COLOR_BORDER_LIGHT[0], self::COLOR_BORDER_LIGHT[1], self::COLOR_BORDER_LIGHT[2]);
        $p .= "0.75 w\n";
        $p .= sprintf("%.2f %.2f %.2f %.2f re B\n", $colRightX, $hashBoxY, $colRightW, $hashBoxH);
        $p .= sprintf("%.3f %.3f %.3f rg\n", self::COLOR_TEXT_MUTED[0], self::COLOR_TEXT_MUTED[1], self::COLOR_TEXT_MUTED[2]);
        $p .= sprintf("BT /F2 7 Tf 0.5 Tc %.2f %.2f Td (CODIGO DE AUTENTICIDADE DIGITAL \(SHA-256\):) Tj ET\n", $colRightX + 10, $hashBoxY + 148);

        // Dividir os 64 caracteres do Hash em blocos de 4
        // Ex: 16 blocos total -> 2 linhas de 8 blocos
        $blocos = str_split($codigoHash, 4);
        $linha1 = implode(' ', array_slice($blocos, 0, 8));
        $linha2 = implode(' ', array_slice($blocos, 8, 8));

        $p .= "q\n";
        $p .= "1 1 1 rg\n";
        $p .= sprintf("%.3f %.3f %.3f RG\n", self::COLOR_BORDER_LIGHT[0], self::COLOR_BORDER_LIGHT[1], self::COLOR_BORDER_LIGHT[2]);
        $p .= "0.5 w\n";
        $p .= sprintf("%.2f %.2f %.2f 50 re B\n", $colRightX + 8, $hashBoxY + 84, $colRightW - 16);
        $p .= sprintf("%.3f %.3f %.3f rg\n", self::COLOR_PRIMARY[0], self::COLOR_PRIMARY[1], self::COLOR_PRIMARY[2]);
        $p .= sprintf("BT /F4 7.5 Tf 0.8 Tc %.2f %.2f Td (%s) Tj ET\n", $colRightX + 14, $hashBoxY + 114, $linha1);
        $p .= sprintf("BT /F4 7.5 Tf 0.8 Tc %.2f %.2f Td (%s) Tj ET\n", $colRightX + 14, $hashBoxY + 98, $linha2);
        $p .= "Q\n";

        // Explicação de segurança
        $p .= sprintf("%.3f %.3f %.3f rg\n", self::COLOR_TEXT_MUTED[0], self::COLOR_TEXT_MUTED[1], self::COLOR_TEXT_MUTED[2]);
        $secTxt1 = "Assinatura criptografica imutavel calculada no ato da expedicao.";
        $secTxt2 = "Garante que o registro nao sofreu qualquer alteracao ou falsificacao.";
        $secTxt3 = "Auditavel publicamente em tempo real sem expor dados sensiveis (LGPD).";
        $p .= sprintf("BT /F1 6.5 Tf 0 Tc %.2f %.2f Td (%s) Tj ET\n", $colRightX + 10, $hashBoxY + 56, self::escapePdfString($secTxt1));
        $p .= sprintf("BT /F1 6.5 Tf 0 Tc %.2f %.2f Td (%s) Tj ET\n", $colRightX + 10, $hashBoxY + 44, self::escapePdfString($secTxt2));
        $p .= sprintf("BT /F1 6.5 Tf 0 Tc %.2f %.2f Td (%s) Tj ET\n", $colRightX + 10, $hashBoxY + 32, self::escapePdfString($secTxt3));
        $p .= "Q\n";

        return $p;
    }

    /**
     * Renderiza a fita de ondas harmônicas contínua em L (Guilloché numismático com 16 ondas).
     */
    private function renderGuillocheLRibbon(): string
    {
        $p = "q\n";
        $numWaves = 16;
        $numSamples = 300;
        $xBase = 19.0;
        $yTop = self::PAGE_HEIGHT;
        $yCorner = 19.0;
        $xRight = self::PAGE_WIDTH;
        $radius = 20.0;
        $amplitude = 7.0;
        $frequency = 5.0;
        $spacing = 1.0;
        $phaseStep = 0.22;
        $lineWidth = 0.28;

        $lenV = $yTop - ($yCorner + $radius);
        $lenArc = (M_PI / 2.0) * $radius;
        $lenH = $xRight - ($xBase + $radius);
        $totalLen = $lenV + $lenArc + $lenH;

        for ($wIdx = 0; $wIdx < $numWaves; $wIdx++) {
            $tFactor = $wIdx / max(1, $numWaves - 1);
            // Interpolação de cor entre Primária (#0E7490) e Secundária (#EA580C)
            $r = self::COLOR_PRIMARY[0] + (self::COLOR_SECONDARY[0] - self::COLOR_PRIMARY[0]) * $tFactor;
            $g = self::COLOR_PRIMARY[1] + (self::COLOR_SECONDARY[1] - self::COLOR_PRIMARY[1]) * $tFactor;
            $b = self::COLOR_PRIMARY[2] + (self::COLOR_SECONDARY[2] - self::COLOR_PRIMARY[2]) * $tFactor;

            $p .= sprintf("%.3f %.3f %.3f RG\n", $r, $g, $b);
            $w = $lineWidth + 0.12 * (1.0 - $tFactor);
            $p .= sprintf("%.2f w\n", $w);

            $latOffset = ($wIdx - ($numWaves - 1) / 2.0) * $spacing;
            $phase = $wIdx * $phaseStep;

            for ($s = 0; $s <= $numSamples; $s++) {
                $d = ($s / $numSamples) * $totalLen;
                $frac = $s / $numSamples;

                if ($d <= $lenV) {
                    $t = $d / $lenV;
                    $pxBase = $xBase;
                    $pyBase = $yTop - $t * $lenV;
                    $nx = 1.0;
                    $ny = 0.0;
                } elseif ($d <= $lenV + $lenArc) {
                    $arcD = $d - $lenV;
                    $theta = ($arcD / $lenArc) * (M_PI / 2.0);
                    $ang = M_PI + $theta;
                    $cx = $xBase + $radius;
                    $cy = $yCorner + $radius;
                    $pxBase = $cx + $radius * cos($ang);
                    $pyBase = $cy + $radius * sin($ang);
                    $nx = -cos($ang);
                    $ny = -sin($ang);
                } else {
                    $hD = $d - ($lenV + $lenArc);
                    $t = $hD / $lenH;
                    $pxBase = ($xBase + $radius) + $t * $lenH;
                    $pyBase = $yCorner;
                    $nx = 0.0;
                    $ny = 1.0;
                }

                $angle = $frac * 2.0 * M_PI * $frequency + $phase;
                $disp = $latOffset + $amplitude * sin($angle) + ($amplitude * 0.35) * cos($angle * 2.1);

                $xPt = $pxBase + $nx * $disp;
                $yPt = $pyBase + $ny * $disp;

                if ($s === 0) {
                    $p .= sprintf("%.2f %.2f m ", $xPt, $yPt);
                } else {
                    $p .= sprintf("%.2f %.2f l ", $xPt, $yPt);
                }
            }
            $p .= "S\n";
        }

        $p .= "Q\n";
        return $p;
    }

    /**
     * Renderiza o QR Code em puro vetor PDF (retângulos preenchidos de alta precisão).
     */
    private function renderVectorQrCode(string $url, float $startX, float $startY, float $targetSize): string
    {
        $matrix = QRCodeGenerator::generateMatrix($url, 'qrm');
        $matrixSize = count($matrix);
        if ($matrixSize === 0) {
            return "";
        }

        $cellSize = $targetSize / $matrixSize;
        $stream = "q\n0 0 0 rg\n";

        for ($r = 0; $r < $matrixSize; $r++) {
            for ($c = 0; $c < $matrixSize; $c++) {
                if ($matrix[$r][$c]) {
                    $x = $startX + ($c * $cellSize);
                    // No PDF o eixo Y cresce de baixo para cima
                    $y = $startY + ($matrixSize - 1 - $r) * $cellSize;
                    $stream .= sprintf("%.2f %.2f %.2f %.2f re f\n", $x, $y, $cellSize, $cellSize);
                }
            }
        }

        $stream .= "Q\n";
        return $stream;
    }

    /**
     * Escapa caracteres reservados do formato de string literal em PDF.
     */
    public static function escapePdfString(string $text): string
    {
        // Translitera caracteres acentuados para representação limpa em Type 1 (Helvetica)
        $trans = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($trans === false || empty($trans)) {
            $trans = preg_replace('/[^\x20-\x7E]/', '', $text) ?? '';
        }
        $clean = str_replace('\\', '\\\\', $trans);
        $clean = str_replace('(', '\(', $clean);
        $clean = str_replace(')', '\)', $clean);
        return $clean;
    }
}

/**
 * Construtor Mínimo de Documentos PDF 1.4 compatível com ISO 32000-1
 */
class SimplePdfDocument
{
    private float $width;
    private float $height;
    private array $pages = [];
    private array $objects = [];

    public function __construct(float $width, float $height)
    {
        $this->width = $width;
        $this->height = $height;
    }

    public function addPage(string $contentStream): void
    {
        $this->pages[] = $contentStream;
    }

    public function output(): string
    {
        $pageCount = count($this->pages);
        if ($pageCount === 0) {
            throw new InvalidArgumentException("Documento PDF não possui nenhuma página.");
        }

        // 1. Objeto 1: Catalog
        // 2. Objeto 2: Pages Node
        // 3. Objeto 3..6: Fontes Type 1
        // Depois: para cada página, Objeto Page e Objeto Content Stream

        $pdf = "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n";
        $offsets = [];

        // Fontes padrão (Helvetica, Helvetica-Bold, Courier, Courier-Bold)
        $fontObjIds = [
            'F1' => 3,
            'F2' => 4,
            'F3' => 5,
            'F4' => 6,
        ];

        // IDs das páginas: cada página i (1..N) terá:
        // pageObjId = 6 + (i - 1) * 2 + 1
        // contentObjId = 6 + (i - 1) * 2 + 2
        $pageObjIds = [];
        for ($i = 0; $i < $pageCount; $i++) {
            $pageObjIds[] = 7 + ($i * 2);
        }

        // Objeto 1: Catalog
        $offsets[1] = strlen($pdf);
        $pdf .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";

        // Objeto 2: Pages
        $offsets[2] = strlen($pdf);
        $kidsStr = implode(" 0 R ", $pageObjIds) . " 0 R";
        $pdf .= sprintf(
            "2 0 obj\n<< /Type /Pages /Kids [ %s ] /Count %d /MediaBox [ 0 0 %.2f %.2f ] >>\nendobj\n",
            $kidsStr,
            $pageCount,
            $this->width,
            $this->height
        );

        // Objeto 3: Fonte F1 (Helvetica)
        $offsets[3] = strlen($pdf);
        $pdf .= "3 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>\nendobj\n";

        // Objeto 4: Fonte F2 (Helvetica-Bold)
        $offsets[4] = strlen($pdf);
        $pdf .= "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>\nendobj\n";

        // Objeto 5: Fonte F3 (Courier)
        $offsets[5] = strlen($pdf);
        $pdf .= "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>\nendobj\n";

        // Objeto 6: Fonte F4 (Courier-Bold)
        $offsets[6] = strlen($pdf);
        $pdf .= "6 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Courier-Bold /Encoding /WinAnsiEncoding >>\nendobj\n";

        // Páginas e Streams
        for ($i = 0; $i < $pageCount; $i++) {
            $pageId = 7 + ($i * 2);
            $contentId = $pageId + 1;
            $rawContent = $this->pages[$i];

            // Compressão Flate (zlib) opcional se disponível
            $filterDef = "";
            $streamData = $rawContent;
            if (function_exists('gzcompress')) {
                $compressed = gzcompress($rawContent);
                if ($compressed !== false && strlen($compressed) < strlen($rawContent)) {
                    $streamData = $compressed;
                    $filterDef = " /Filter /FlateDecode";
                }
            }

            // Objeto Page
            $offsets[$pageId] = strlen($pdf);
            $pdf .= sprintf(
                "%d 0 obj\n<< /Type /Page /Parent 2 0 R /Contents %d 0 R /Resources << /Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R /F4 6 0 R >> >> >>\nendobj\n",
                $pageId,
                $contentId
            );

            // Objeto Stream
            $offsets[$contentId] = strlen($pdf);
            $pdf .= sprintf(
                "%d 0 obj\n<< /Length %d%s >>\nstream\n%s\nendstream\nendobj\n",
                $contentId,
                strlen($streamData),
                $filterDef,
                $streamData
            );
        }

        // Tabela XREF
        $xrefOffset = strlen($pdf);
        $totalObjs = 6 + ($pageCount * 2);
        $pdf .= sprintf("xref\n0 %d\n", $totalObjs + 1);
        $pdf .= "0000000000 65535 f \n";
        for ($obj = 1; $obj <= $totalObjs; $obj++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$obj]);
        }

        // Trailer
        $pdf .= sprintf(
            "trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n",
            $totalObjs + 1,
            $xrefOffset
        );

        return $pdf;
    }
}
