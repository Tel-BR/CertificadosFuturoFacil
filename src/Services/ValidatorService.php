<?php
/**
 * Serviço de Validação Pública de Autenticidade de Certificados
 * Plataforma Integrada de Gestão Pedagógica, Diário e Certificados Futuro Fácil
 * 
 * Conformidade com LGPD (ADR-0002):
 * - Nome do aluno mascarado (ex: A** L**** S***** N*********)
 * - CPF do aluno estritamente mascarado (***.123.456-**)
 * - Consulta estritamente somente-leitura
 * - Nenhuma exposição do PDF original ou dados administrativos
 */

declare(strict_types=1);

namespace FuturoFacil\Services;

use FuturoFacil\Config\Database;
use PDO;

class ValidatorService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getConnection();
    }

    /**
     * Higieniza o código de autenticidade recebido via URL ou formulário.
     * Remove quebras de linha, tabulações, hífens e espaços, convertendo para maiúsculas.
     */
    public static function cleanAuthCode(?string $code): string
    {
        if ($code === null) {
            return '';
        }
        return strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $code) ?? '');
    }

    /**
     * Valida se a string é um hash SHA-256 hexadecimal válido de 64 caracteres.
     */
    public static function isValidSha256(string $code): bool
    {
        return strlen($code) === 64 && ctype_xdigit($code);
    }

    /**
     * Aplica o mascaramento LGPD no nome do aluno.
     * Preserva a primeira letra de cada termo e mascara as demais com asteriscos.
     * Exemplo: 'Ana Luiza Santos Nascimento' -> 'A** L**** S***** N*********'
     */
    public static function maskName(?string $name): string
    {
        if ($name === null || trim($name) === '') {
            return '—';
        }

        $cleanName = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        $words = explode(' ', $cleanName);
        $maskedWords = [];

        foreach ($words as $word) {
            $len = mb_strlen($word, 'UTF-8');
            if ($len <= 1) {
                $maskedWords[] = mb_strtoupper($word, 'UTF-8');
            } else {
                $firstChar = mb_strtoupper(mb_substr($word, 0, 1, 'UTF-8'), 'UTF-8');
                $maskedWords[] = $firstChar . str_repeat('*', $len - 1);
            }
        }

        return implode(' ', $maskedWords);
    }

    /**
     * Aplica o mascaramento LGPD no CPF do aluno.
     * Formato: ***.123.456-**
     */
    public static function maskCpf(?string $cpf): string
    {
        if ($cpf === null || trim($cpf) === '') {
            return '—';
        }

        // Se já vier com máscara contendo asteriscos, retorna padronizado
        if (str_contains($cpf, '*')) {
            return trim($cpf);
        }

        $digits = preg_replace('/\D/', '', $cpf) ?? '';
        if (strlen($digits) === 11) {
            $mid1 = substr($digits, 3, 3);
            $mid2 = substr($digits, 6, 3);
            return "***.{$mid1}.{$mid2}-**";
        }

        return '***.***.***-**';
    }

    /**
     * Remove todos os caracteres não numéricos do CPF.
     */
    public static function cleanCpf(?string $cpf): string
    {
        if ($cpf === null) {
            return '';
        }
        return preg_replace('/\D/', '', $cpf) ?? '';
    }

    /**
     * Formata CPF no padrão brasileiro 000.000.000-00.
     */
    public static function formatCpf(?string $cpf): string
    {
        $cleaned = self::cleanCpf($cpf);
        if (strlen($cleaned) === 11) {
            return substr($cleaned, 0, 3) . '.' .
                   substr($cleaned, 3, 3) . '.' .
                   substr($cleaned, 6, 3) . '-' .
                   substr($cleaned, 9, 2);
        }
        return $cleaned;
    }

    /**
     * Valida matematicamente um CPF brasileiro segundo o algoritmo módulo 11.
     * Rejeita tamanhos diferentes de 11, dígitos idênticos repetidos e dígitos verificadores inconsistentes.
     */
    public static function validateCpf(?string $cpf): bool
    {
        $cleaned = self::cleanCpf($cpf);
        if (strlen($cleaned) !== 11) {
            return false;
        }

        // Rejeita sequências com todos os dígitos idênticos (ex: 11111111111)
        if ($cleaned === str_repeat($cleaned[0], 11)) {
            return false;
        }

        // Primeiro dígito verificador (pesos 10 a 2)
        $soma1 = 0;
        for ($i = 0; $i < 9; $i++) {
            $soma1 += (int)$cleaned[$i] * (10 - $i);
        }
        $resto1 = $soma1 % 11;
        $dv1 = ($resto1 < 2) ? 0 : 11 - $resto1;
        if ((int)$cleaned[9] !== $dv1) {
            return false;
        }

        // Segundo dígito verificador (pesos 11 a 2)
        $soma2 = 0;
        for ($i = 0; $i < 10; $i++) {
            $soma2 += (int)$cleaned[$i] * (11 - $i);
        }
        $resto2 = $soma2 % 11;
        $dv2 = ($resto2 < 2) ? 0 : 11 - $resto2;
        if ((int)$cleaned[10] !== $dv2) {
            return false;
        }

        return true;
    }

    /**
     * Valida um código de autenticidade contra a base de registros.
     * Executa estritamente consulta SELECT preparada somente-leitura.
     */
    public function validarCodigo(?string $rawCode): array
    {
        $code = self::cleanAuthCode($rawCode);

        if ($code === '') {
            return [
                'autentico' => false,
                'status'    => 'vazio',
                'mensagem'  => 'Por favor, informe o código de autenticidade para consulta.',
                'codigo'    => '',
            ];
        }

        if (!self::isValidSha256($code)) {
            return [
                'autentico' => false,
                'status'    => 'invalido',
                'mensagem'  => 'O código informado não possui o formato de autenticidade válido (SHA-256 de 64 caracteres).',
                'codigo'    => $code,
            ];
        }

        $sql = "SELECT 
                    codigo_autenticidade,
                    aluno_nome,
                    aluno_cpf,
                    aluno_cpf_mascarado,
                    curso_nome,
                    carga_horaria,
                    carga_horaria_extenso,
                    data_inicio,
                    data_conclusao,
                    data_emissao,
                    modalidade,
                    instrutor,
                    cidade,
                    ementa,
                    livro_numero,
                    folha_numero,
                    registro_numero,
                    frequencia
                FROM registros_certificados 
                WHERE codigo_autenticidade = ? 
                LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$code]);
        $registro = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$registro) {
            return [
                'autentico' => false,
                'status'    => 'nao_encontrado',
                'mensagem'  => 'Certificado não localizado na base oficial ou código não registrado.',
                'codigo'    => $code,
            ];
        }

        // Determinação do CPF mascarado
        $cpfMascarado = self::maskCpf($registro['aluno_cpf_mascarado'] ?: $registro['aluno_cpf']);
        $nomeMascarado = self::maskName($registro['aluno_nome']);

        return [
            'autentico'             => true,
            'status'                => 'valido',
            'mensagem'              => 'Certificado autêntico registrado formalmente no Livro Digital de Certificados da Futuro Fácil.',
            'codigo_autenticidade'  => $registro['codigo_autenticidade'],
            'aluno_nome_mascarado'  => $nomeMascarado,
            'aluno_cpf_mascarado'   => $cpfMascarado,
            'curso_nome'            => $registro['curso_nome'],
            'carga_horaria'         => (int)$registro['carga_horaria'],
            'carga_horaria_extenso' => $registro['carga_horaria_extenso'] ?? '',
            'modalidade'            => $registro['modalidade'] ?? 'Presencial',
            'data_inicio'           => $registro['data_inicio'] ?? '',
            'data_conclusao'        => $registro['data_conclusao'],
            'data_emissao'          => $registro['data_emissao'],
            'livro_numero'          => (int)$registro['livro_numero'],
            'folha_numero'          => (int)$registro['folha_numero'],
            'registro_numero'       => (int)$registro['registro_numero'],
            'instrutor'             => $registro['instrutor'] ?? 'Tel Santana Leite',
            'cidade'                => $registro['cidade'] ?? 'Goiânia',
            'ementa'                => $registro['ementa'] ?? '',
            'frequencia'            => (int)($registro['frequencia'] ?? 100),
        ];
    }
}
