<?php
/**
 * Entrada de desenvolvimento na raiz: todas as rotas usam o mesmo despachante
 * público, independentemente do document root escolhido para php -S.
 */

declare(strict_types=1);

require __DIR__ . '/public/router.php';
