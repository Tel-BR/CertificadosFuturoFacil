<?php
/**
 * Teste de renderização HTML da página pública de validação
 */
declare(strict_types=1);

$_GET['validar'] = '6FB1ED64F19C8BBC4DA014D0AEAB6E78CC8D72A661523C23F01751D22CA998FD';

require_once __DIR__ . '/../src/Config/Database.php';
\FuturoFacil\Config\Database::setConfig([
    'driver' => 'sqlite',
    'database' => __DIR__ . '/../registros.db',
]);

ob_start();
require __DIR__ . '/../public/validar/index.php';
$html = ob_get_clean();

$passou = true;
$checks = [
    'Certificado Autêntico e Válido',
    'A** L**** S***** N*********',
    '***.367.631-**',
    'Excel Intermediário/Avançado',
    'Livro nº 1',
    'Folha nº 1',
    'Registro nº 1',
    'Garantia de Privacidade e LGPD',
];

foreach ($checks as $check) {
    if (!str_contains($html, $check)) {
        echo "FALHA: Não encontrou no HTML: '{$check}'\n";
        $passou = false;
    }
}

if ($passou) {
    echo "TESTE WEB HTML: Aprovado com 100% de sucesso na renderização e mascaramento LGPD!\n";
    exit(0);
} else {
    exit(1);
}
