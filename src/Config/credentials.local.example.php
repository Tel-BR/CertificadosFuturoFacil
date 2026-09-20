<?php
/**
 * Exemplo de Credenciais do MariaDB de Produção (Hostinger)
 * Copie este arquivo para 'credentials.local.php' no servidor e preencha com os dados do hPanel.
 * NUNCA comite nem envie credentials.local.php para repositórios públicos.
 */
return [
    'DB_DRIVER'            => 'mysql',
    'DB_HOST'              => 'localhost',
    'DB_PORT'              => 3306,
    'DB_DATABASE'          => 'u505703191_ffprod',      // Substitua pelo nome do banco de produção criado no hPanel
    'DB_USERNAME'          => 'u505703191_ffprod',      // Substitua pelo usuário MariaDB criado no hPanel
    'DB_PASSWORD'          => 'SUA_SENHA_FORTE_AQUI',   // Substitua pela senha gerada
    'TURNSTILE_SITE_KEY'   => '1x00000000000000000000AA', // Site Key Cloudflare ou chave de teste
    'TURNSTILE_SECRET_KEY' => '1x0000000000000000000000000000000AA',
];
