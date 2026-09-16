<?php
/**
 * Teste de renderização HTML da página pública de validação
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/Config/Database.php';
\FuturoFacil\Config\Database::setConfig([
    'driver' => 'sqlite',
    'database' => __DIR__ . '/../registros.db',
]);

// Consulta hash real de Ana Luiza para conferir os dados esperados
$dbCheck = \FuturoFacil\Config\Database::getConnection();
$realHash = $dbCheck->query("SELECT codigo_autenticidade FROM registros_certificados WHERE aluno_nome LIKE '%Ana Luiza%' LIMIT 1")->fetchColumn();
$_GET['validar'] = $realHash ?: '3A83BC60D234691233DE0DE8C194C72036AAF09CA27DD135AA47864D68229541';


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
