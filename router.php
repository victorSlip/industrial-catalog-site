<?php
/*
 * Só para desenvolvimento local: faz o servidor embutido do PHP entender
 * os endereços limpos (/machines → machines.html), como o .htaccess faz no Apache.
 *   php -S localhost:8000 router.php
 */
if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$root = __DIR__;
$query = $_SERVER['QUERY_STRING'] ?? '';

// endereços antigos com .html redirecionam para o endereço limpo
if (preg_match('#^/(?:index\.html)?$#', $uri) && $uri !== '/') {
    header('Location: /' . ($query ? "?$query" : ''), true, 301);
    exit;
}
if (preg_match('#^/([^/.]+)\.html$#', $uri, $m)) {
    header('Location: /' . $m[1] . ($query ? "?$query" : ''), true, 301);
    exit;
}
// /machines abre machines.html
if (preg_match('#^/([^/.]+)$#', $uri, $m) && is_file("$root/{$m[1]}.html")) {
    header('Content-Type: text/html; charset=utf-8');
    readfile("$root/{$m[1]}.html");
    exit;
}
// pastas internas não podem ser abertas pelo navegador (no Apache, os .htaccess cuidam disso)
if (preg_match('#^/(admin/private|data/backups)(/|$)#', $uri)) {
    http_response_code(403);
    exit;
}
return false;
