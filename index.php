<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/site.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: SAMEORIGIN');

try {
    $route = current_route();

    if ($route === '/beheer/' || str_starts_with($route, '/beheer/')) {
        require __DIR__ . '/app/admin.php';
        admin_dispatch($route);
        exit;
    }

    if (preg_match('#^/kalender/ics/([a-z0-9-]+)\.ics$#', $route, $m)) {
        require __DIR__ . '/app/ics.php';
        $ics = render_ics($m[1]);
        if ($ics === null) { http_response_code(404); exit('Niet gevonden'); }
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: inline; filename="hzs-' . $m[1] . '.ics"');
        header('Cache-Control: max-age=900');
        echo $ics;
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo render_route($route);
} catch (Throwable $ex) {
    error_log('[HZS] ' . $ex);
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>Fout</title><p style="font-family:sans-serif;padding:2em">Er ging iets mis bij het tonen van deze pagina. Probeer het later opnieuw.</p>';
}
