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

    /**
     * Gera a marcação vetorial nativa em SVG puro para o QR Code.
     * Renderização de alta precisão baseada em path único com crispEdges.
     */
    public static function generateSvg(
        string $text,
        int $size = 320,
        string $fg = '#000000',
        string $bg = '#ffffff',
        int $margin = 4,
        string $level = 'qrm'
    ): string {
        $matrix = self::generateMatrix($text, $level);
        $matrixCount = count($matrix);
        if ($matrixCount === 0) {
            return '';
        }

        $totalCells = $matrixCount + ($margin * 2);
        $pathData = '';

        for ($r = 0; $r < $matrixCount; $r++) {
            for ($c = 0; $c < $matrixCount; $c++) {
                if ($matrix[$r][$c]) {
                    $x = $c + $margin;
                    $y = $r + $margin;
                    $pathData .= "M{$x},{$y}h1v1h-1z ";
                }
            }
        }

        $safeFg = htmlspecialchars($fg, ENT_QUOTES, 'UTF-8');
        $safeBg = htmlspecialchars($bg, ENT_QUOTES, 'UTF-8');

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="%d" height="%d" shape-rendering="crispEdges">',
            $totalCells,
            $totalCells,
            $size,
            $size
        );

        if ($bg !== 'transparent' && !empty($bg)) {
            $svg .= sprintf('<rect width="%d" height="%d" fill="%s"/>', $totalCells, $totalCells, $safeBg);
        }

        $svg .= sprintf('<path d="%s" fill="%s"/>', trim($pathData), $safeFg);
        $svg .= '</svg>';

        return $svg;
    }

    /**
     * Retorna a Data URI formatada do SVG para uso direto em src de img ou background CSS.
     */
    public static function generateDataUri(
        string $text,
        int $size = 320,
        string $fg = '#000000',
        string $bg = '#ffffff',
        int $margin = 4,
        string $level = 'qrm'
    ): string {
        $svg = self::generateSvg($text, $size, $fg, $bg, $margin, $level);
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
