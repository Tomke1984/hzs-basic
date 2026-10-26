<?php
// Lokale ontwikkelserver (de ingebouwde PHP-server leest .htaccess niet).
// Starten:  php -S localhost:8000 router.php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
if (preg_match('#^/(app|data|seed|tests)(/|$)|^/(config(\.example)?\.php|router\.php)$|^/\.#', $path)) {
    http_response_code(403); echo 'Forbidden'; return true;
}
if (preg_match('#^/uploads/.*\.(php\d?|phtml|phar|html?|svg|js)$#i', $path)) { http_response_code(403); echo 'Forbidden'; return true; }
if ($path !== '/' && is_file(__DIR__ . $path)) return false;
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/index.php';
