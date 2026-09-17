<?php
/**
 * Gerador de Matrizes QR Code para Desenho Vetorial
 * Plataforma Integrada de Gestão Pedagógica, Diário e Certificados Futuro Fácil
 */

declare(strict_types=1);

namespace FuturoFacil\Utils;

require_once __DIR__ . '/QRCodeLib.php';

use QRCode;

class QRCodeGenerator
{
    /**
     * Gera a matriz booleana 2D de módulos de um QR Code.
     * Retorna array $matrix[$row][$col] (true = módulo escuro, false = módulo claro).
     */
    public static function generateMatrix(string $text, string $level = 'qrm'): array
    {
        $qr = new QRCode($text, ['s' => $level]);
        return $qr->get_matrix();
    }
}
