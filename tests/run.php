<?php
/**
 * Lokale tests voor de HZS-website en het beheer.
 *
 *   php tests/run.php
 *
 * Werkt op een tijdelijke kopie (eigen data, eigen config), dus je echte
 * inhoud in data/ en uploads/ wordt nooit aangeraakt. Start zelf een
 * PHP-ontwikkelserver op een vrije poort en stopt die achteraf.
 */
declare(strict_types=1);

$repo = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/hzs-test-' . bin2hex(random_bytes(4));
$code = 'TEST-' . bin2hex(random_bytes(4));

function copy_tree(string $from, string $to, array $skip): void {
    @mkdir($to, 0775, true);
    foreach (scandir($from) as $f) {
        if ($f === '.' || $f === '..' || in_array($f, $skip, true)) continue;
        $a = "$from/$f"; $b = "$to/$f";
        is_dir($a) ? copy_tree($a, $b, []) : copy($a, $b);
    }
}
function rm_tree(string $d): void {
    if (!is_dir($d)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($d);
}

copy_tree($repo, $tmp, ['.git', 'data', 'uploads', 'config.php', 'tests']);
mkdir("$tmp/data/backup", 0775, true);
mkdir("$tmp/uploads", 0775, true);
copy("$repo/uploads/.htaccess", "$tmp/uploads/.htaccess");
file_put_contents("$tmp/config.php", str_replace('VUL-HIER-EEN-EIGEN-CODE-IN', $code, file_get_contents("$repo/config.example.php")));

$sock = stream_socket_server('tcp://127.0.0.1:0');
$port = (int)substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
fclose($sock);
$log = "$tmp/server.log";
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", "$tmp/router.php"], [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $tmp);
$B = "http://127.0.0.1:$port";
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);

$cookies = [];
$fails = 0; $n = 0;
register_shutdown_function(function () use (&$proc, $tmp, &$fails, &$n) {
    if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
    rm_tree($tmp);
    echo "\n$n tests, " . ($fails ? "\033[31m$fails mislukt\033[0m" : "\033[32malles geslaagd\033[0m") . "\n";
    exit($fails ? 1 : 0);
});

function ok(bool $cond, string $msg): void {
    global $fails, $n;
    $n++;
    if (!$cond) $fails++;
    echo ($cond ? "\033[32m  ✓\033[0m " : "\033[31m  ✗\033[0m ") . $msg . "\n";
}

/** Eenvoudige HTTP-client met cookies, zonder redirects te volgen. */
function http(string $method, string $path, array $form = [], ?array $file = null): array {
    global $B, $cookies;
    $headers = [];
    if ($cookies) $headers[] = 'Cookie: ' . implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($cookies), $cookies));
    $content = null;
    if ($file) {
        $bd = '----hzs' . bin2hex(random_bytes(6));
        $content = '';
        foreach ($form as $k => $v) $content .= "--$bd\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n$v\r\n";
        $content .= "--$bd\r\nContent-Disposition: form-data; name=\"file\"; filename=\"{$file['name']}\"\r\nContent-Type: application/octet-stream\r\n\r\n{$file['data']}\r\n--$bd--\r\n";
        $headers[] = "Content-Type: multipart/form-data; boundary=$bd";
    } elseif ($method === 'POST') {
        $content = http_build_query($form);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $content,
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 20]]);
    $body = (string)@file_get_contents($B . $path, false, $ctx);
    $h = $http_response_header ?? [];
    $status = (int)(preg_match('#HTTP/\S+ (\d+)#', $h[0] ?? '', $m) ? $m[1] : 0);
    $loc = '';
    foreach ($h as $line) {
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $line, $m)) $cookies[$m[1]] = $m[2];
        if (preg_match('/^Location:\s*(.+)$/i', $line, $m)) $loc = trim($m[1]);
    }
    return ['status' => $status, 'body' => $body, 'location' => $loc, 'headers' => $h];
}
function get(string $p): array { return http('GET', $p); }
function csrf(string $html): string { return preg_match('/name="_csrf" value="([^"]+)"/', $html, $m) ? html_entity_decode($m[1]) : ''; }
function id_from(string $loc): string { return preg_match('/[?&]id=([^&#]+)/', $loc, $m) ? urldecode($m[1]) : ''; }

echo "Publieke website\n";
foreach (['/', '/zwemschool/', '/zwemschool/lesuren/', '/zwemschool/groepen/', '/competitie/trainingsuren/', '/competitie/clubrecords/',
             '/lid-worden/', '/club/contact/', '/kalender/', '/privacy/', '/2026/09/03/sluitingsdagen-zwembad/'] as $p) {
    $r = get($p);
    ok($r['status'] === 200 && str_contains($r['body'], 'site-nav'), "$p geeft 200");
}
ok(get('/bestaat-niet/')['status'] === 404, 'onbekende pagina geeft 404');
$home = get('/')['body'];
ok(str_contains($home, 'Laatste nieuws') && str_contains($home, 'class="tile'), 'startpagina toont nieuws en activiteiten');
ok(str_contains(get('/zwemschool/lesuren/')['body'], 'slotcard'), 'lesuren komen uit de groepen');
$ics = get('/kalender/ics/kern.ics');
ok($ics['status'] === 200 && str_starts_with($ics['body'], "BEGIN:VCALENDAR\r\n") && str_contains($ics['body'], 'RRULE:FREQ=WEEKLY'), 'agenda-bestand per groep met wekelijkse trainingen');
ok(get('/kalender/ics/bestaat-niet.ics')['status'] === 404, 'onbekend agenda-bestand geeft 404');
$maxLine = max(array_map('strlen', explode("\r\n", get('/kalender/ics/alles.ics')['body'])));
ok($maxLine <= 75, 'agenda-regels zijn correct gevouwen');

echo "\nAfscherming\n";
foreach (['/data/pages.json', '/app/admin.php', '/config.php', '/seed/pages.json', '/router.php'] as $p) ok(get($p)['status'] === 403, "$p is niet bereikbaar");

echo "\nEerste beheerder\n";
$r = get('/beheer/');
ok(str_contains($r['body'], 'installatiecode'), 'zonder beheerders verschijnt het installatieformulier');
$t = csrf($r['body']);
$r = http('POST', '/beheer/', ['_csrf' => $t, 'code' => 'fout', 'name' => 'Test', 'email' => 'test@example.be', 'password' => 'een-lang-wachtwoord']);
ok(str_contains($r['body'], 'klopt niet'), 'foute installatiecode wordt geweigerd');
$r = http('POST', '/beheer/', ['_csrf' => $t, 'code' => $code, 'name' => 'Test', 'email' => 'test@example.be', 'password' => 'kort']);
ok(str_contains($r['body'], 'minstens 10'), 'te kort wachtwoord wordt geweigerd');
$r = http('POST', '/beheer/', ['_csrf' => $t, 'code' => $code, 'name' => 'Test', 'email' => 'test@example.be', 'password' => 'een-lang-wachtwoord']);
ok($r['status'] === 303, 'beheerder aangemaakt');
$dash = get('/beheer/');
ok(str_contains($dash['body'], '<h1>Overzicht</h1>'), 'na installatie meteen aangemeld');
$t = csrf($dash['body']);

echo "\nBeveiliging\n";
ok(http('POST', '/beheer/nieuws/verwijder/', ['id' => 'x'])['status'] === 400, 'formulier zonder CSRF-token wordt geweigerd');
$r = http('POST', '/beheer/upload/', ['_csrf' => $t], ['name' => 'kwaad.php', 'data' => '<?php echo 1;']);
ok($r['status'] === 400 && str_contains($r['body'], 'niet toegestaan'), 'php-bestand uploaden wordt geweigerd');
$r = http('POST', '/beheer/upload/', ['_csrf' => $t], ['name' => 'nep.png', 'data' => 'geen afbeelding']);
ok($r['status'] === 400, 'nep-afbeelding wordt geweigerd');
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
$r = http('POST', '/beheer/upload/', ['_csrf' => $t], ['name' => 'Foto Zwemmen.png', 'data' => $png]);
$up = json_decode($r['body'], true);
ok($r['status'] === 200 && !empty($up['url']) && str_contains($up['url'], 'foto-zwemmen.png'), 'afbeelding uploaden werkt');
ok(!empty($up['url']) && get($up['url'])['status'] === 200, 'geüploade afbeelding is bereikbaar');

echo "\nPagina's\n";
$pages = json_decode(file_get_contents("$tmp/data/pages.json"), true)['pages'];
$lid = array_values(array_filter($pages, fn($p) => $p['path'] === '/lid-worden/lidgeld/'))[0];
$r = get('/beheer/paginas/bewerk/?id=' . $lid['id']);
ok(str_contains($r['body'], 'class="rte"'), 'pagina openen in de editor');
$body = $lid['body'] . '<p>TESTZIN-42</p><script>alert(1)</script><img src="x" onerror="alert(2)"><a href="javascript:alert(3)">x</a>';
$r = http('POST', '/beheer/paginas/bewerk/?id=' . $lid['id'], ['_csrf' => $t, 'id' => $lid['id'], 'title' => 'Lidgeld', 'menu_title' => '', 'parent' => '/lid-worden/',
    'slug' => 'lidgeld', 'sub' => '', 'type' => 'page', 'target' => '', 'visible' => '1', 'in_menu' => '1', 'side' => '1', 'order' => '10', 'body' => $body]);
ok($r['status'] === 303, 'pagina opslaan');
$site = get('/lid-worden/lidgeld/')['body'];
ok(str_contains($site, 'TESTZIN-42'), 'wijziging staat op de website');
ok(!str_contains($site, '<script>alert') && !str_contains($site, 'onerror') && !str_contains($site, 'javascript:alert'), 'gevaarlijke code wordt eruit gefilterd');
ok(count(glob("$tmp/data/backup/pages-*.json")) >= 1, 'back-up gemaakt bij opslaan');

$r = http('POST', '/beheer/paginas/bewerk/', ['_csrf' => $t, 'id' => '', 'title' => 'Zwemkampen', 'menu_title' => '', 'parent' => '/club/', 'slug' => '', 'sub' => 'Zomer 2027',
    'type' => 'page', 'target' => '', 'visible' => '1', 'in_menu' => '1', 'side' => '1', 'order' => '70', 'body' => '<p>Info over zwemkampen.</p>']);
ok($r['status'] === 303, 'nieuwe pagina aanmaken');
ok(str_contains(get('/club/zwemkampen/')['body'], 'Info over zwemkampen'), 'nieuwe pagina staat online op /club/zwemkampen/');
ok(str_contains(get('/')['body'], 'Zwemkampen'), 'nieuwe pagina staat in het menu');
$r = http('POST', '/beheer/paginas/bewerk/', ['_csrf' => $t, 'id' => '', 'title' => 'Dubbel', 'parent' => '/club/', 'slug' => 'zwemkampen', 'type' => 'page', 'body' => '']);
ok(str_contains($r['body'], 'bestaat al'), 'dubbel adres wordt geweigerd');
$r = http('POST', '/beheer/paginas/bewerk/', ['_csrf' => $t, 'id' => '', 'title' => 'Beheer', 'parent' => '', 'slug' => 'beheer', 'type' => 'page', 'body' => '']);
ok(str_contains($r['body'], 'gereserveerd'), 'gereserveerd adres wordt geweigerd');
$pages = json_decode(file_get_contents("$tmp/data/pages.json"), true)['pages'];
$kamp = array_values(array_filter($pages, fn($p) => $p['path'] === '/club/zwemkampen/'))[0]['id'];
ok(http('POST', '/beheer/paginas/verwijder/', ['_csrf' => $t, 'id' => $kamp])['status'] === 303 && get('/club/zwemkampen/')['status'] === 404, 'pagina verwijderen');
$kal = array_values(array_filter($pages, fn($p) => $p['type'] === 'kalender'))[0]['id'];
http('POST', '/beheer/paginas/verwijder/', ['_csrf' => $t, 'id' => $kal]);
ok(get('/kalender/')['status'] === 200, 'systeempagina (kalender) kan niet verwijderd worden');

echo "\nNieuws\n";
$r = http('POST', '/beheer/nieuws/bewerk/', ['_csrf' => $t, 'id' => '', 'title' => 'Testbericht', 'date' => date('Y-m-d'), 'category' => 'Clubnieuws',
    'slug' => '', 'intro' => 'Korte samenvatting.', 'image' => $up['url'] ?? '', 'body' => '<p>Tekst van het bericht.</p>', 'published' => '1']);
ok($r['status'] === 303, 'bericht aanmaken');
$home = get('/')['body'];
ok(str_contains($home, 'Testbericht'), 'bericht staat op de startpagina');
$path = '/' . str_replace('-', '/', date('Y-m-d')) . '/testbericht/';
$post = get($path);
ok($post['status'] === 200 && str_contains($post['body'], 'foto-zwemmen.png'), 'berichtpagina met foto');
http('POST', '/beheer/nieuws/bewerk/', ['_csrf' => $t, 'id' => '', 'title' => 'Toekomstig bericht', 'date' => date('Y-m-d', strtotime('+10 days')), 'category' => 'Clubnieuws',
    'slug' => '', 'intro' => 'x', 'image' => '', 'body' => '<p>x</p>', 'published' => '1']);
ok(!str_contains(get('/')['body'], 'Toekomstig bericht'), 'bericht met datum in de toekomst is nog niet zichtbaar');
http('POST', '/beheer/nieuws/bewerk/', ['_csrf' => $t, 'id' => '', 'title' => 'Concept', 'date' => date('Y-m-d'), 'category' => 'Clubnieuws', 'slug' => '', 'intro' => 'x', 'image' => '', 'body' => '<p>x</p>']);
ok(!str_contains(get('/')['body'], '>Concept<'), 'concept is niet zichtbaar');

echo "\nKalender\n";
$day = date('Y-m-d', strtotime('+3 days'));
$r = http('POST', '/beheer/kalender/activiteit/', ['_csrf' => $t, 'id' => '', 'titel' => 'Testwedstrijd', 'type' => 'wedstrijd', 'start' => $day, 'einde' => '',
    'hele_dag' => '1', 'locatie' => 'Hasselt', 'omschrijving' => '', 'groepen' => ['kern']]);
ok($r['status'] === 303, 'activiteit toevoegen');
ok(str_contains(get('/kalender/')['body'], 'Testwedstrijd'), 'activiteit staat op de kalender');
ok(str_contains(get('/kalender/ics/kern.ics')['body'], 'Testwedstrijd') && !str_contains(get('/kalender/ics/zwemschool.ics')['body'], 'Testwedstrijd'), 'activiteit enkel in de agenda van de gekozen groep');
$r = http('POST', '/beheer/kalender/activiteit/', ['_csrf' => $t, 'id' => '', 'titel' => 'Fout', 'type' => 'club', 'start' => $day, 'einde' => '2000-01-01', 'hele_dag' => '1']);
ok(str_contains($r['body'], 'einddatum'), 'einddatum voor startdatum wordt geweigerd');
http('POST', '/beheer/kalender/', ['_csrf' => $t, 'what' => 'sluiting', 'datum' => $day, 'reden' => 'Testsluiting']);
ok(str_contains(get('/kalender/')['body'], 'Testsluiting'), 'sluitingsdag toevoegen');

echo "\nGroepen & uren\n";
$groups = json_decode(file_get_contents("$tmp/data/groups.json"), true)['groups'];
$g = array_values(array_filter($groups, fn($x) => $x['id'] === 'pre-competitie-2'))[0];
$tr = $g['trainingen'];
$tr[] = ['dag' => 'wo', 'van' => '18:00', 'tot' => '19:15', 'soort' => 'Zwemmen', 'plaats' => 'Zwembad'];
$r = http('POST', '/beheer/groepen/bewerk/?id=pre-competitie-2', ['_csrf' => $t, 'orig_id' => 'pre-competitie-2', 'naam' => $g['naam'], 'afdeling' => 'zwemschool',
    'order' => '30', 'trainers' => ['ine-bijnens'], 'omschrijving' => '', 'zichtbaar' => '1', 'trainingen' => $tr]);
ok($r['status'] === 303, 'uren van een groep opslaan');
ok(str_contains(get('/zwemschool/lesuren/')['body'], '18:00–19:15'), 'nieuw uur staat op de lesuren-pagina');
$tr[] = ['dag' => 'ma', 'van' => '19:00', 'tot' => '18:00', 'soort' => '', 'plaats' => ''];
$r = http('POST', '/beheer/groepen/bewerk/?id=pre-competitie-2', ['_csrf' => $t, 'orig_id' => 'pre-competitie-2', 'naam' => $g['naam'], 'afdeling' => 'zwemschool', 'order' => '30', 'zichtbaar' => '1', 'trainingen' => $tr]);
ok(str_contains($r['body'], 'einde na begin'), 'einduur voor beginuur wordt geweigerd');

echo "\nTrainers\n";
$tp = get('/competitie/trainers/')['body'];
ok(str_contains($tp, 'Lander Mulkers') && str_contains($tp, 'tel:+32470571306') && str_contains($tp, 'mailto:lander.mulkers@khzs.be'), 'trainerspagina toont trainers met contactgegevens');
ok(str_contains($tp, 'Competitie 1 nationaal en Kern'), 'groepen met dezelfde trainers worden samengevoegd');
ok(str_contains(get('/competitie/trainingsuren/')['body'], 'Trainers: Antoinette Konings en Lander Mulkers'), 'trainersnamen bij de trainingsuren');
$r = http('POST', '/beheer/trainers/bewerk/', ['_csrf' => $t, 'id' => '', 'naam' => 'Sofie Peeters', 'telefoon' => '0499 11 22 33', 'email' => 'sofie@example.be', 'toon_contact' => '1', 'groepen' => ['zwemschool', 'kern']]);
ok($r['status'] === 303, 'trainer toevoegen');
ok(str_contains(get('/zwemschool/lesuren/')['body'], 'Trainer: Sofie Peeters'), 'nieuwe trainer staat bij de gekozen groep');
ok(str_contains(get('/competitie/trainers/')['body'], 'tel:+32499112233'), 'nieuwe trainer op de trainerspagina met belbare link');
$r = http('POST', '/beheer/trainers/bewerk/', ['_csrf' => $t, 'id' => '', 'naam' => 'Sofie Peeters']);
ok(str_contains($r['body'], 'bestaat al'), 'dubbele trainersnaam wordt geweigerd');
$r = http('POST', '/beheer/trainers/bewerk/', ['_csrf' => $t, 'id' => '', 'naam' => 'X', 'email' => 'geen-adres']);
ok(str_contains($r['body'], 'niet geldig'), 'ongeldig e-mailadres wordt geweigerd');
http('POST', '/beheer/trainers/bewerk/?id=sofie-peeters', ['_csrf' => $t, 'id' => 'sofie-peeters', 'naam' => 'Sofie Peeters', 'telefoon' => '0499 11 22 33', 'email' => 'sofie@example.be', 'groepen' => ['zwemschool']]);
$tp = get('/competitie/trainers/')['body'];
ok(str_contains($tp, 'Sofie Peeters') && !str_contains($tp, 'sofie@example.be'), 'contactgegevens verbergen werkt');
ok(!str_contains(get('/competitie/trainingsuren/')['body'], 'Sofie'), 'loskoppelen van een groep werkt');
$gr = get('/beheer/groepen/bewerk/?id=kern')['body'];
ok(str_contains($gr, 'name="trainers[]" value="lander-mulkers" checked'), 'groepsformulier toont trainers als keuzelijst');
$pages = json_decode(file_get_contents("$tmp/data/pages.json"), true)['pages'];
$contact = array_values(array_filter($pages, fn($p) => $p['path'] === '/club/contact/'))[0];
http('POST', '/beheer/paginas/bewerk/?id=' . $contact['id'], ['_csrf' => $t, 'id' => $contact['id'], 'title' => $contact['title'], 'menu_title' => '', 'parent' => '/club/', 'slug' => 'contact',
    'sub' => '', 'type' => 'page', 'target' => '', 'visible' => '1', 'in_menu' => '1', 'side' => '1', 'order' => '10', 'body' => $contact['body'] . '<h2>Trainers zwemschool</h2><p>[[trainers:zwemschool]]</p>']);
$cp = get('/club/contact/')['body'];
ok(str_contains($cp, 'Ine Bijnens') && !str_contains($cp, 'Lander Mulkers') && !str_contains($cp, '[[trainers'), '[[trainers:zwemschool]] werkt op een gewone pagina');
http('POST', '/beheer/trainers/verwijder/', ['_csrf' => $t, 'id' => 'sofie-peeters']);
ok(!str_contains(get('/zwemschool/lesuren/')['body'], 'Sofie') && !str_contains(file_get_contents("$tmp/data/groups.json"), 'sofie-peeters'), 'trainer verwijderen haalt hem ook uit de groepen');

echo "\nInstellingen en beheerders\n";
$s = json_decode(file_get_contents("$tmp/data/settings.json"), true);
http('POST', '/beheer/instellingen/', ['_csrf' => $t, 'hero_title' => $s['hero_title'], 'hero_title_accent' => $s['hero_title_accent'], 'hero_intro' => $s['hero_intro'],
    'cta_label' => 'Zwemtest aanvragen', 'cta_path' => '/zwemschool/inschrijven/', 'footer_tagline' => 'x', 'emails' => "secretariaat@khzs.be", 'facebook' => '',
    'hero_buttons' => $s['hero_buttons'], 'lanes' => $s['lanes'], 'footer_links' => $s['footer_links']]);
ok(str_contains(get('/')['body'], 'Zwemtest aanvragen'), 'instellingen worden toegepast');
http('POST', '/beheer/gebruikers/', ['_csrf' => $t, 'name' => 'Tweede', 'email' => 'tweede@example.be', 'password' => 'tijdelijk-wachtwoord']);
ok(count(json_decode(file_get_contents("$tmp/data/users.json"), true)['users']) === 2, 'tweede beheerder toevoegen');
$users = file_get_contents("$tmp/data/users.json");
ok(!str_contains($users, 'tijdelijk-wachtwoord') && !str_contains($users, 'een-lang-wachtwoord'), 'wachtwoorden worden niet leesbaar bewaard');

echo "\nAfmelden en aanmelden\n";
http('POST', '/beheer/logout/', ['_csrf' => $t]);
ok(get('/beheer/paginas/')['location'] !== '', 'na afmelden is het beheer afgeschermd');
$lt = csrf(get('/beheer/login/')['body']);
$r = http('POST', '/beheer/login/', ['_csrf' => $lt, 'email' => 'tweede@example.be', 'password' => 'tijdelijk-wachtwoord']);
ok($r['status'] === 303 && str_contains(get('/beheer/')['body'], '<h1>Overzicht</h1>'), 'tweede beheerder kan aanmelden');
$t = csrf(get('/beheer/')['body']);
http('POST', '/beheer/logout/', ['_csrf' => $t]);
$lt = csrf(get('/beheer/login/')['body']);
for ($i = 0; $i < 9; $i++) $r = http('POST', '/beheer/login/', ['_csrf' => $lt, 'email' => 'tweede@example.be', 'password' => 'fout']);
ok(str_contains($r['body'], 'Te veel'), 'na te veel foute pogingen wordt aanmelden geblokkeerd');

echo "\nBijwerken van bestaande gegevens (oude versie)\n";
$old = sys_get_temp_dir() . '/hzs-oud-' . bin2hex(random_bytes(4));
copy_tree($tmp, $old, ['data', 'uploads', 'server.log']);
mkdir("$old/data/backup", 0775, true);
$og = json_decode(file_get_contents("$repo/seed/groups.json"), true);
foreach ($og['groups'] as &$x) $x['trainers'] = ['kern' => 'Lander en Antoinette', 'competitie-3' => 'Jorien', 'nieuwe-groep' => 'Mirte'][$x['id']] ?? '';
unset($x);
$og['groups'][] = ['id' => 'nieuwe-groep', 'naam' => 'Nieuwe groep', 'afdeling' => 'competitie', 'trainers' => 'Mirte', 'zichtbaar' => true, 'order' => 99, 'trainingen' => []];
file_put_contents("$old/data/groups.json", json_encode($og));
$op = json_decode(file_get_contents("$repo/seed/pages.json"), true);
foreach ($op['pages'] as &$x) if ($x['path'] === '/competitie/trainers/') { $x['type'] = 'page'; $x['body'] = '<ul class="contacts"><li>oud</li></ul>'; }
unset($x);
file_put_contents("$old/data/pages.json", json_encode($op));
$sock = stream_socket_server('tcp://127.0.0.1:0'); $p2 = (int)substr(strrchr(stream_socket_get_name($sock, false), ':'), 1); fclose($sock);
$proc2 = proc_open([PHP_BINARY, '-S', "127.0.0.1:$p2", "$old/router.php"], [1 => ['file', "$old/s.log", 'a'], 2 => ['file', "$old/s.log", 'a']], $pp, $old);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $p2); $i++) usleep(100000);
$mb = (string)@file_get_contents("http://127.0.0.1:$p2/competitie/trainers/");
$mg = json_decode((string)@file_get_contents("$old/data/groups.json"), true)['groups'] ?? [];
$byId = array_column($mg, 'trainers', 'id');
ok(is_array($byId['kern'] ?? null) && in_array('lander-mulkers', $byId['kern'], true), 'oude trainersnamen worden omgezet naar gekoppelde trainers');
ok(($byId['nieuwe-groep'] ?? null) === ['mirte-bielen'], 'eigen groepen: namen worden herkend op voornaam');
ok(str_contains($mb, 'tel:+32470571306') && !str_contains($mb, '<li>oud</li>'), 'oude trainerspagina wordt het nieuwe dynamische blok');
ok(is_file("$old/data/trainers.json") && json_decode(file_get_contents("$old/data/meta.json"), true)['schema'] === 2, 'trainers aangemaakt en versie bijgehouden');
proc_terminate($proc2); proc_close($proc2);
rm_tree($old);

$errors = array_filter(explode("\n", (string)@file_get_contents($log)), fn($l) => preg_match('/PHP (Fatal|Warning|Notice|Deprecated|Parse)/i', $l));
ok(!$errors, 'geen PHP-fouten of waarschuwingen in de serverlog' . ($errors ? ":\n    " . implode("\n    ", array_slice($errors, 0, 5)) : ''));
