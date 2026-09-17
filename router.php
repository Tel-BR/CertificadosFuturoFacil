<?php
/**
 * Router para o Servidor Embutido do PHP (php -S)
 * Emula as regras de reescrita do Apache (.htaccess) para desenvolvimento local
 */

declare(strict_types=1);

 = is_dir(__DIR__ . '/public') ? __DIR__ . '/public' : __DIR__;
 = parse_url(['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

// 1. Arquivo estático real existente dentro de public/
 =  . ;
if ( !== '/' && file_exists() && !is_dir()) {
    return false; // Deixa o PHP servir o arquivo estático diretamente
}

// 2. Favicon silencioso se não existir
if ( === '/favicon.ico') {
    http_response_code(204);
    exit;
}

// 3. Raiz / -> Redireciona para /diario/
if ( === '/' ||  === '') {
    header('Location: /diario/');
    exit;
}

// 4. Rota /validar e /validar/<hash>
if (preg_match('#^/validar(?:/([A-Fa-f0-9]+))?/?$#', , )) {
    if (!empty([1])) {
        ['codigo'] = [1];
    }
    require  . '/validar/index.php';
    exit;
}

// 5. Rota /diario ou /diario/
if ( === '/diario' ||  === '/diario/') {
    require  . '/diario/index.php';
    exit;
}

// 6. Rotas amigáveis de /diario/<slug> (login, logout, turmas, turma, calendario, aula, fechamento)
if (preg_match('#^/diario/([a-zA-Z0-9_-]+)/?$#', , )) {
     =  . '/diario/' . [1] . '.php';
    if (file_exists()) {
        require ;
        exit;
    }
}

// 7. Se nada combinou, tenta servir como arquivo estático ou 404 padrão
return false;
