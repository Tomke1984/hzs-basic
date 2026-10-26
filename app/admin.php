<?php
declare(strict_types=1);

/* =====================================================================
   Beheer van de HZS-website (/beheer)
   ===================================================================== */

const SYSTEM_TYPES = ['home' => 'Startpagina', 'kalender' => 'Kalender', 'lesuren' => 'Lesuren (uit groepen)', 'trainingsuren' => 'Trainingsuren (uit groepen)', 'trainers' => 'Trainers (uit trainers)'];
const RESERVED_PATHS = ['/beheer/', '/assets/', '/uploads/', '/app/', '/data/'];

/* ---------- Sessie, login, CSRF ---------- */

function admin_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('hzs_beheer');
    session_set_cookie_params(['lifetime' => 0, 'path' => base_path(), 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
    if (!empty($_SESSION['uid']) && (time() - ($_SESSION['seen'] ?? 0)) > 60 * 60 * 8) { $_SESSION = []; session_regenerate_id(true); }
    $_SESSION['seen'] = time();
}

function users(): array { return load('users', ['users' => []])['users']; }

function current_user(): ?array {
    foreach (users() as $u) if ($u['id'] === ($_SESSION['uid'] ?? null)) return $u;
    return null;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">'; }
function check_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    $t = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
        http_response_code(400);
        exit('Het formulier is verlopen. Ga terug, vernieuw de pagina en probeer opnieuw.');
    }
}

function flash(string $msg, string $type = 'success'): void { $_SESSION['flash'][] = [$type, $msg]; }
function take_flash(): string {
    $out = '';
    foreach ($_SESSION['flash'] ?? [] as [$t, $m]) $out .= '<div class="alert alert-' . e($t) . '">' . e($m) . '</div>';
    unset($_SESSION['flash']);
    return $out;
}

function client_key(): string { return hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|hzs'); }

function login_blocked(): bool {
    $a = load('attempts', []);
    $k = client_key();
    if (empty($a[$k])) return false;
    return $a[$k]['n'] >= 8 && (time() - $a[$k]['t']) < 900;
}
function login_failed(): void {
    $a = load('attempts', []);
    $k = client_key();
    foreach ($a as $key => $v) if (time() - $v['t'] > 900) unset($a[$key]);
    $a[$k] = ['n' => ($a[$k]['n'] ?? 0) + 1, 't' => $a[$k]['t'] ?? time()];
    save('attempts', $a, false);
}
function login_ok(): void {
    $a = load('attempts', []);
    unset($a[client_key()]);
    save('attempts', $a, false);
}

function post(string $k, $default = '') {
    $v = $_POST[$k] ?? $default;
    return is_string($v) ? trim($v) : $v;
}
function valid_date(string $d): bool { return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false; }
function valid_time(string $t): bool { return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t); }

/* ---------- Dispatcher ---------- */

function admin_dispatch(string $route): void {
    admin_session();
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    check_csrf();
    $sub = trim(substr($route, strlen('/beheer/')), '/');

    if (!users()) { admin_setup(); return; }
    if ($sub === 'login') { admin_login(); return; }
    if (!current_user()) { redirect('/beheer/login/'); }

    $map = [
        '' => 'admin_dashboard',
        'logout' => 'admin_logout',
        'paginas' => 'admin_pages', 'paginas/bewerk' => 'admin_page_edit', 'paginas/verwijder' => 'admin_page_delete', 'paginas/voorbeeld' => 'admin_page_preview',
        'nieuws' => 'admin_news', 'nieuws/bewerk' => 'admin_news_edit', 'nieuws/verwijder' => 'admin_news_delete',
        'kalender' => 'admin_calendar', 'kalender/activiteit' => 'admin_event_edit', 'kalender/verwijder' => 'admin_calendar_delete',
        'groepen' => 'admin_groups', 'groepen/bewerk' => 'admin_group_edit', 'groepen/verwijder' => 'admin_group_delete',
        'trainers' => 'admin_trainers', 'trainers/bewerk' => 'admin_trainer_edit', 'trainers/verwijder' => 'admin_trainer_delete',
        'instellingen' => 'admin_settings',
        'gebruikers' => 'admin_users', 'gebruikers/verwijder' => 'admin_user_delete', 'wachtwoord' => 'admin_password',
        'media' => 'admin_media', 'media/verwijder' => 'admin_media_delete', 'upload' => 'admin_upload',
        'backups' => 'admin_backups',
    ];
    if (!isset($map[$sub])) { http_response_code(404); admin_page('Niet gevonden', '<p>Deze beheerpagina bestaat niet.</p>'); return; }
    $map[$sub]();
}

/* ---------- Layout beheer ---------- */

function admin_page(string $title, string $content, string $active = ''): void {
    $u = current_user();
    $nav = '';
    $items = ['' => 'Overzicht', 'paginas' => "Pagina's", 'nieuws' => 'Nieuws', 'kalender' => 'Kalender', 'groepen' => 'Groepen & uren', 'trainers' => 'Trainers',
        'media' => 'Bestanden', 'instellingen' => 'Instellingen', 'gebruikers' => 'Beheerders'];
    if ($u) foreach ($items as $k => $label) {
        $cur = $k === $active ? ' aria-current="page"' : '';
        $nav .= '<a href="' . e(url('/beheer/' . ($k ? "$k/" : ''))) . '"' . $cur . '>' . e($label) . '</a>';
    }
    $userbar = $u ? '<div class="who"><span>' . e($u['name']) . '</span> · <a href="' . e(url('/beheer/wachtwoord/')) . '">Wachtwoord</a>'
        . '<form method="post" action="' . e(url('/beheer/logout/')) . '" class="inline">' . csrf_field() . '<button class="linkbtn">Afmelden</button></form></div>' : '';
    $flash = take_flash();
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>' . e($title) . ' · Beheer HZS</title>'
        . '<link rel="icon" href="' . e(asset('logo.png')) . '"><link rel="stylesheet" href="' . e(asset('admin.css')) . '"></head>'
        . '<body class="adm" data-csrf="' . e(csrf_token()) . '" data-upload="' . e(url('/beheer/upload/')) . '" data-legacy="' . e(rtrim((string)cfg('legacy_media_host', ''), '/')) . '">'
        . '<header class="adm-head"><a class="brand" href="' . e(url('/beheer/')) . '"><img src="' . e(asset('logo.png')) . '" alt="" width="40"><span>Beheer <b>HZS</b></span></a>'
        . ($u ? '<button class="navbtn" type="button" aria-expanded="false" aria-controls="adm-nav">Menu</button>' : '')
        . '<a class="site-link" href="' . e(url('/')) . '" target="_blank" rel="noopener">Website bekijken ↗</a>' . $userbar . '</header>'
        . '<div class="adm-body">' . ($u ? '<nav class="adm-nav" id="adm-nav">' . $nav . '</nav>' : '')
        . '<main class="adm-main"><h1>' . e($title) . '</h1>' . $flash . $content . '</main></div>'
        . '<script src="' . e(asset('admin.js')) . '"></script></body></html>';
}

function field(string $label, string $input, string $hint = ''): string {
    return '<label class="field"><span>' . e($label) . '</span>' . $input . ($hint ? '<small>' . $hint . '</small>' : '') . '</label>';
}
function input(string $name, $value = '', string $type = 'text', string $extra = ''): string {
    return '<input type="' . $type . '" name="' . e($name) . '" value="' . e($value) . '" ' . $extra . '>';
}
function checkbox(string $name, bool $checked, string $label, string $value = '1'): string {
    return '<label class="check"><input type="checkbox" name="' . e($name) . '" value="' . e($value) . '"' . ($checked ? ' checked' : '') . '> ' . e($label) . '</label>';
}
function select(string $name, array $options, $value, string $extra = ''): string {
    $o = '';
    foreach ($options as $k => $v) $o .= '<option value="' . e($k) . '"' . ((string)$k === (string)$value ? ' selected' : '') . '>' . e($v) . '</option>';
    return '<select name="' . e($name) . '" ' . $extra . '>' . $o . '</select>';
}
function rte(string $name, string $html, string $hint = ''): string {
    return '<div class="rte" data-name="' . e($name) . '"><textarea name="' . e($name) . '" rows="18">' . e($html) . '</textarea></div>'
        . ($hint ? '<small class="hint">' . $hint . '</small>' : '');
}
function post_form(string $action, string $inner, string $cls = 'form'): string {
    return '<form method="post" action="' . e(url($action)) . '" class="' . $cls . '">' . csrf_field() . $inner . '</form>';
}
function delete_button(string $action, string $id, string $confirm, string $label = 'Verwijderen'): string {
    return '<form method="post" action="' . e(url($action)) . '" class="inline" data-confirm="' . e($confirm) . '">' . csrf_field()
        . '<input type="hidden" name="id" value="' . e($id) . '"><button class="btn danger small">' . e($label) . '</button></form>';
}

/* ---------- Eerste beheerder, login ---------- */

function admin_setup(): void {
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $code = (string)post('code');
        $real = (string)cfg('setup_code', '');
        if (login_blocked()) {
            $err = 'Te veel pogingen. Probeer over 15 minuten opnieuw.';
        } elseif ($real === '' || str_contains($real, '__SETUP') || !hash_equals($real, $code)) {
            login_failed();
            $err = 'De installatiecode klopt niet. Je vindt hem in config.php.';
        } else {
            $name = post('name'); $email = strtolower(post('email')); $pw = (string)post('password');
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Vul je naam en een geldig e-mailadres in.';
            elseif (strlen($pw) < 10) $err = 'Kies een wachtwoord van minstens 10 tekens.';
            else {
                save('users', ['users' => [['id' => new_id(), 'name' => $name, 'email' => $email, 'hash' => password_hash($pw, PASSWORD_DEFAULT), 'created' => date('c')]]]);
                session_regenerate_id(true);
                $_SESSION['uid'] = users()[0]['id'] ?? null;
                flash('Welkom! Je account is aangemaakt. Hieronder kun je de site beheren.');
                redirect('/beheer/');
            }
        }
    }
    $form = post_form('/beheer/', ($err ? '<div class="alert alert-error">' . e($err) . '</div>' : '')
        . '<p>Er is nog geen beheerder. Maak de eerste aan met de installatiecode uit <code>config.php</code>.</p>'
        . field('Installatiecode', input('code', '', 'text', 'required autocomplete="off"'))
        . field('Naam', input('name', post('name'), 'text', 'required'))
        . field('E-mailadres', input('email', post('email'), 'email', 'required autocomplete="username"'))
        . field('Wachtwoord', input('password', '', 'password', 'required minlength="10" autocomplete="new-password"'), 'Minstens 10 tekens.')
        . '<button class="btn">Beheerder aanmaken</button>', 'form narrow card');
    admin_page('Beheer instellen', $form);
}

function admin_login(): void {
    if (current_user()) redirect('/beheer/');
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (login_blocked()) {
            $err = 'Te veel mislukte pogingen. Probeer over 15 minuten opnieuw.';
        } else {
            $email = strtolower(post('email')); $pw = (string)post('password');
            $found = null;
            foreach (users() as $u) if (hash_equals($u['email'], $email)) $found = $u;
            if ($found && password_verify($pw, $found['hash'])) {
                login_ok();
                session_regenerate_id(true);
                $_SESSION['uid'] = $found['id'];
                $_SESSION['csrf'] = bin2hex(random_bytes(24));
                if (password_needs_rehash($found['hash'], PASSWORD_DEFAULT)) {
                    $all = load('users');
                    foreach ($all['users'] as &$x) if ($x['id'] === $found['id']) $x['hash'] = password_hash($pw, PASSWORD_DEFAULT);
                    save('users', $all);
                }
                redirect('/beheer/');
            }
            if (!$found) password_verify($pw, password_hash('gelijke-duur', PASSWORD_DEFAULT));
            login_failed();
            $err = 'E-mailadres of wachtwoord klopt niet.';
        }
    }
    $form = post_form('/beheer/login/', ($err ? '<div class="alert alert-error">' . e($err) . '</div>' : '')
        . field('E-mailadres', input('email', post('email'), 'email', 'required autofocus autocomplete="username"'))
        . field('Wachtwoord', input('password', '', 'password', 'required autocomplete="current-password"'))
        . '<button class="btn">Aanmelden</button>', 'form narrow card');
    admin_page('Aanmelden', $form);
}

function admin_logout(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $_SESSION = [];
        session_regenerate_id(true);
        session_destroy();
    }
    redirect('/beheer/login/');
}

/* ---------- Overzicht ---------- */

function admin_dashboard(): void {
    $np = count(pages()); $nn = count(posts(false)); $ne = count(upcoming_events()); $ng = count(groups());
    $latest = '';
    foreach (array_slice(posts(false), 0, 3) as $n) $latest .= '<li><a href="' . e(url('/beheer/nieuws/bewerk/?id=' . $n['id'])) . '">' . e($n['title']) . '</a> <span class="muted">' . nl_date($n['date']) . (empty($n['published']) ? ' · concept' : '') . '</span></li>';
    $next = '';
    foreach (upcoming_events(4) as $ev) $next .= '<li><a href="' . e(url('/beheer/kalender/activiteit/?id=' . $ev['id'])) . '">' . e($ev['titel']) . '</a> <span class="muted">' . nl_date($ev['start']) . '</span></li>';
    $c = '<div class="tiles4">'
        . '<a class="stat" href="' . e(url('/beheer/paginas/')) . '"><b>' . $np . '</b><span>pagina\'s</span></a>'
        . '<a class="stat" href="' . e(url('/beheer/nieuws/')) . '"><b>' . $nn . '</b><span>nieuwsberichten</span></a>'
        . '<a class="stat" href="' . e(url('/beheer/kalender/')) . '"><b>' . $ne . '</b><span>komende activiteiten</span></a>'
        . '<a class="stat" href="' . e(url('/beheer/groepen/')) . '"><b>' . $ng . '</b><span>groepen</span></a></div>'
        . '<div class="cols2"><section class="card"><h2>Laatste nieuws</h2><ul class="plain">' . ($latest ?: '<li class="muted">Nog geen nieuws.</li>') . '</ul>'
        . '<p><a class="btn small" href="' . e(url('/beheer/nieuws/bewerk/')) . '">Nieuw bericht</a></p></section>'
        . '<section class="card"><h2>Eerstvolgende activiteiten</h2><ul class="plain">' . ($next ?: '<li class="muted">Niets gepland.</li>') . '</ul>'
        . '<p><a class="btn small" href="' . e(url('/beheer/kalender/activiteit/')) . '">Activiteit toevoegen</a></p></section></div>';
    admin_page('Overzicht', $c, '');
}

/* ---------- Pagina's ---------- */

function save_pages(array $list): void { save('pages', ['pages' => array_values($list)]); }

function admin_pages(): void {
    $all = pages();
    $tops = array_values(array_filter($all, fn($p) => empty($p['parent'])));
    usort($tops, fn($a, $b) => ($a['type'] === 'home' ? -1 : 0) <=> ($b['type'] === 'home' ? -1 : 0) ?: ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
    $rows = '';
    $row = function (array $p, int $level) {
        $flags = [];
        if (empty($p['visible'])) $flags[] = '<span class="tag off">verborgen</span>';
        elseif (empty($p['in_menu']) && $p['type'] !== 'home') $flags[] = '<span class="tag">niet in menu</span>';
        if (isset(SYSTEM_TYPES[$p['type']])) $flags[] = '<span class="tag sys">' . e(SYSTEM_TYPES[$p['type']]) . '</span>';
        if ($p['type'] === 'link') $flags[] = '<span class="tag">menulink</span>';
        $view = $p['type'] === 'link' ? '' : '<a href="' . e(url($p['path'])) . '" target="_blank" rel="noopener">Bekijken</a>';
        return '<tr><td class="lvl' . $level . '"><a href="' . e(url('/beheer/paginas/bewerk/?id=' . $p['id'])) . '"><b>' . e($p['title']) . '</b></a> ' . implode(' ', $flags)
            . '</td><td class="muted small">' . e($p['type'] === 'link' ? '→ ' . ($p['target'] ?? '') : $p['path']) . '</td><td class="num">' . (int)($p['order'] ?? 0) . '</td><td class="actions">' . $view . '</td></tr>';
    };
    foreach ($tops as $t) {
        $rows .= $row($t, 0);
        $kids = array_values(array_filter($all, fn($p) => ($p['parent'] ?? null) === $t['path']));
        usort($kids, fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
        foreach ($kids as $k) $rows .= $row($k, 1);
    }
    $c = '<p class="toolbar"><a class="btn" href="' . e(url('/beheer/paginas/bewerk/')) . '">Nieuwe pagina</a></p>'
        . '<div class="table-wrap"><table class="list"><thead><tr><th>Pagina</th><th>Adres</th><th>Volgorde</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
        . '<p class="muted small">Subpagina\'s verschijnen in het uitklapmenu van hun hoofdpagina. Een lagere volgorde komt eerst.</p>';
    admin_page("Pagina's", $c, 'paginas');
}

function admin_page_edit(): void {
    $all = pages();
    $id = (string)($_GET['id'] ?? post('id'));
    $page = null;
    foreach ($all as $p) if ($p['id'] === $id) $page = $p;
    if ($id !== '' && !$page) { flash('Pagina niet gevonden.', 'error'); redirect('/beheer/paginas/'); }
    $isNew = !$page;
    $page = $page ?? ['id' => '', 'path' => '', 'title' => '', 'menu_title' => '', 'sub' => '', 'parent' => null, 'order' => 50,
        'in_menu' => true, 'visible' => true, 'side' => true, 'type' => 'page', 'target' => '', 'body' => ''];
    $errors = [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $old = $page;
        $page['title'] = post('title');
        $page['menu_title'] = post('menu_title');
        $page['sub'] = post('sub');
        $page['order'] = (int)post('order', 50);
        $page['visible'] = $page['type'] === 'home' ? true : (bool)post('visible', '');
        $page['in_menu'] = (bool)post('in_menu', '');
        $page['side'] = (bool)post('side', '');
        if (!isset(SYSTEM_TYPES[$page['type']])) $page['type'] = post('type') === 'link' ? 'link' : 'page';
        $page['target'] = $page['type'] === 'link' ? post('target') : '';
        $page['body'] = clean_html((string)post('body'));
        if ($page['type'] !== 'home') {
            $parent = post('parent');
            $page['parent'] = $parent !== '' ? normalize_path($parent) : null;
            $slug = slugify(post('slug') ?: $page['title']);
            $page['path'] = normalize_path(($page['parent'] ?? '/') . $slug);
        }
        if ($page['title'] === '') $errors[] = 'Geef de pagina een titel.';
        if ($page['type'] === 'link' && !preg_match('#^(/|https?://)#', $page['target'])) $errors[] = 'Een menulink moet naar een adres wijzen dat begint met / of https://.';
        foreach (RESERVED_PATHS as $r) if (str_starts_with($page['path'], $r)) $errors[] = 'Dit adres is gereserveerd. Kies een andere naam.';
        if (preg_match('#^/\d{4}/#', $page['path'])) $errors[] = 'Een adres mag niet met een jaartal beginnen (dat is voor nieuws).';
        foreach ($all as $p) if ($p['id'] !== $page['id'] && $p['path'] === $page['path']) $errors[] = 'Er bestaat al een pagina op dit adres.';
        if ($page['parent'] && !page_by_path($page['parent'])) $errors[] = 'De gekozen hoofdpagina bestaat niet.';
        if ($page['parent'] === $page['path']) $errors[] = 'Een pagina kan niet onder zichzelf staan.';
        if (!$errors) {
            if ($isNew) { $page['id'] = new_id(); $all[] = $page; }
            else foreach ($all as &$p) {
                if ($p['id'] === $page['id']) $p = $page;
                elseif ($old['path'] !== $page['path'] && ($p['parent'] ?? null) === $old['path']) {
                    $p['parent'] = $page['path'];
                    $p['path'] = normalize_path($page['path'] . basename(rtrim($p['path'], '/')));
                }
            }
            unset($p);
            save_pages($all);
            flash($isNew ? 'Pagina aangemaakt.' : 'Pagina opgeslagen.');
            redirect('/beheer/paginas/bewerk/?id=' . $page['id']);
        }
    }

    $parents = ['' => '— Geen (hoofdmenu) —'];
    foreach ($all as $p) if (empty($p['parent']) && $p['type'] !== 'home' && $p['type'] !== 'link' && $p['id'] !== $page['id']) $parents[$p['path']] = $p['title'];
    $isHome = $page['type'] === 'home';
    $sys = isset(SYSTEM_TYPES[$page['type']]);
    $slug = $page['path'] ? basename(rtrim($page['path'], '/')) : '';
    $hint = match ($page['type']) {
        'kalender' => 'De activiteiten, vakanties en agenda-links komen op de plek van <code>[[blok]]</code>. Haal je die weg, dan komen ze onderaan.',
        'lesuren', 'trainingsuren' => 'De uren komen uit <a href="' . e(url('/beheer/groepen/')) . '">Groepen &amp; uren</a> en verschijnen op de plek van <code>[[blok]]</code>.',
        'trainers' => 'De trainers per groep, met hun contactgegevens, komen uit <a href="' . e(url('/beheer/trainers/')) . '">Trainers</a> en verschijnen op de plek van <code>[[blok]]</code>.',
        'home' => 'Dit is het onderste deel van de startpagina. De bovenkant (titel, tekst en knoppen) pas je aan bij <a href="' . e(url('/beheer/instellingen/')) . '">Instellingen</a>; nieuws en activiteiten komen er vanzelf op.',
        default => 'Tip: typ <code>[[trainers]]</code> op een eigen regel om de trainers per groep te tonen (of <code>[[trainers:zwemschool]]</code> / <code>[[trainers:competitie]]</code>).',
    };

    $f = ($errors ? '<div class="alert alert-error"><ul>' . implode('', array_map(fn($x) => '<li>' . e($x) . '</li>', array_unique($errors))) . '</ul></div>' : '')
        . '<input type="hidden" name="id" value="' . e($page['id']) . '">'
        . '<div class="grid2">' . field('Titel', input('title', $page['title'], 'text', 'required maxlength="120"'))
        . field('Naam in het menu', input('menu_title', $page['menu_title'], 'text', 'maxlength="60"'), 'Leeg = gelijk aan de titel.') . '</div>';
    if (!$isHome) {
        $f .= '<div class="grid2">' . field('Hoofdpagina', select('parent', $parents, $page['parent'] ?? ''))
            . field('Adres (laatste deel)', input('slug', $slug, 'text', 'pattern="[a-z0-9-]*" maxlength="60"'), 'Kleine letters, cijfers en streepjes. Leeg = afgeleid van de titel. Huidig adres: <code>' . e($page['path'] ?: '—') . '</code>') . '</div>';
        $f .= field('Ondertitel in de banner', input('sub', $page['sub'], 'text', 'maxlength="200"'));
        if (!$sys) {
            $f .= '<div class="grid2">' . field('Soort', select('type', ['page' => 'Gewone pagina', 'link' => 'Alleen een menulink'], $page['type'], 'data-toggle-link'))
                . field('Menulink gaat naar', input('target', $page['target'] ?? '', 'text', 'placeholder="/lid-worden/documenten/#competitie"'), 'Alleen voor een menulink.') . '</div>';
        }
        $f .= '<div class="checks">' . checkbox('visible', !empty($page['visible']), 'Zichtbaar op de website')
            . checkbox('in_menu', !empty($page['in_menu']), 'In het menu tonen')
            . checkbox('side', !empty($page['side']), 'Zijmenu van de rubriek tonen')
            . '<label class="field inline-num"><span>Volgorde</span>' . input('order', (string)($page['order'] ?? 50), 'number', 'min="0" max="999"') . '</label></div>';
    } else {
        $f .= '<input type="hidden" name="order" value="0"><input type="hidden" name="in_menu" value="">';
    }
    $f .= '<div class="editor-field" data-hide-for-link><span class="label">Inhoud</span>' . rte('body', $page['body'] ?? '', $hint) . '</div>';
    $f .= '<div class="form-actions"><button class="btn">Opslaan</button>'
        . (!$isNew && $page['type'] !== 'link' ? '<a class="btn ghost" href="' . e(url('/beheer/paginas/voorbeeld/?id=' . $page['id'])) . '" target="_blank" rel="noopener">Voorbeeld (opgeslagen versie)</a>' : '')
        . '<a class="btn ghost" href="' . e(url('/beheer/paginas/')) . '">Terug</a></div>';
    $c = post_form('/beheer/paginas/bewerk/' . ($page['id'] ? '?id=' . $page['id'] : ''), $f, 'form wide');
    if (!$isNew && !$sys) {
        $kids = array_filter($all, fn($p) => ($p['parent'] ?? null) === $page['path']);
        $c .= '<div class="danger-zone">' . ($kids ? '<p class="muted small">Deze pagina heeft subpagina\'s. Verplaats of verwijder die eerst.</p>'
            : delete_button('/beheer/paginas/verwijder/', $page['id'], 'Pagina "' . $page['title'] . '" definitief verwijderen?', 'Pagina verwijderen')) . '</div>';
    }
    admin_page($isNew ? 'Nieuwe pagina' : 'Pagina bewerken: ' . $page['title'], $c, 'paginas');
}

function admin_page_delete(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/beheer/paginas/');
    $id = post('id');
    $all = pages();
    $target = null;
    foreach ($all as $p) if ($p['id'] === $id) $target = $p;
    if (!$target || isset(SYSTEM_TYPES[$target['type']])) { flash('Deze pagina kan niet verwijderd worden.', 'error'); redirect('/beheer/paginas/'); }
    foreach ($all as $p) if (($p['parent'] ?? null) === $target['path']) { flash('Verwijder of verplaats eerst de subpagina\'s.', 'error'); redirect('/beheer/paginas/'); }
    save_pages(array_filter($all, fn($p) => $p['id'] !== $id));
    flash('Pagina "' . $target['title'] . '" verwijderd. Een kopie staat in de back-ups.');
    redirect('/beheer/paginas/');
}

function admin_page_preview(): void {
    $id = (string)($_GET['id'] ?? '');
    foreach (pages() as $p) if ($p['id'] === $id) {
        header('Content-Type: text/html; charset=utf-8');
        echo $p['type'] === 'home' ? render_home() : render_preview($p);
        return;
    }
    redirect('/beheer/paginas/');
}

/* ---------- Nieuws ---------- */

function news_categories(): array {
    $c = ['Clubnieuws', 'Wedstrijden & uitslagen', 'Zwemschool', 'Stages & activiteiten'];
    foreach (posts(false) as $n) $c[] = $n['category'];
    return array_values(array_unique(array_filter($c)));
}

function admin_news(): void {
    $rows = '';
    foreach (posts(false) as $n) {
        $state = empty($n['published']) ? '<span class="tag off">concept</span>' : ($n['date'] > today() ? '<span class="tag">gepland</span>' : '');
        $rows .= '<tr><td><a href="' . e(url('/beheer/nieuws/bewerk/?id=' . $n['id'])) . '"><b>' . e($n['title']) . '</b></a> ' . $state . '</td><td class="num">' . nl_date($n['date'])
            . '</td><td class="muted">' . e($n['category']) . '</td><td class="actions">' . (!empty($n['published']) ? '<a href="' . e(url(post_path($n))) . '" target="_blank" rel="noopener">Bekijken</a>' : '') . '</td></tr>';
    }
    $c = '<p class="toolbar"><a class="btn" href="' . e(url('/beheer/nieuws/bewerk/')) . '">Nieuw bericht</a></p>'
        . '<p class="muted small">De drie nieuwste gepubliceerde berichten staan op de startpagina. Elk bericht heeft ook een eigen pagina.</p>'
        . ($rows ? '<div class="table-wrap"><table class="list"><thead><tr><th>Titel</th><th>Datum</th><th>Categorie</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div>' : '<p class="empty">Nog geen nieuws.</p>');
    admin_page('Nieuws', $c, 'nieuws');
}

function admin_news_edit(): void {
    $data = load('news', ['posts' => []]);
    $id = (string)($_GET['id'] ?? post('id'));
    $n = null;
    foreach ($data['posts'] as $x) if ($x['id'] === $id) $n = $x;
    $isNew = !$n;
    $n = $n ?? ['id' => '', 'title' => '', 'slug' => '', 'date' => today(), 'category' => 'Clubnieuws', 'intro' => '', 'body' => '', 'image' => '', 'published' => true];
    $errors = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $n['title'] = post('title');
        $n['date'] = post('date');
        $n['category'] = post('category') ?: 'Clubnieuws';
        $n['intro'] = mb_substr(post('intro'), 0, 400);
        $n['body'] = clean_html((string)post('body'));
        $img = post('image');
        $n['image'] = ($img === '' || preg_match('#^(/uploads/|https://)#', $img)) ? $img : '';
        $n['published'] = (bool)post('published', '');
        $n['slug'] = slugify(post('slug') ?: $n['title']);
        if ($n['title'] === '') $errors[] = 'Geef het bericht een titel.';
        if (!valid_date($n['date'])) $errors[] = 'Kies een geldige datum.';
        foreach ($data['posts'] as $x) if ($x['id'] !== $n['id'] && $x['date'] === $n['date'] && $x['slug'] === $n['slug']) $errors[] = 'Er is al een bericht met deze titel op deze datum.';
        if ($n['intro'] === '') $n['intro'] = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($n['body']))), 0, 180) . '…';
        if (!$errors) {
            if ($isNew) { $n['id'] = new_id(); $data['posts'][] = $n; }
            else foreach ($data['posts'] as &$x) if ($x['id'] === $n['id']) $x = $n;
            unset($x);
            save('news', $data);
            flash($isNew ? 'Bericht aangemaakt.' : 'Bericht opgeslagen.');
            redirect('/beheer/nieuws/bewerk/?id=' . $n['id']);
        }
    }
    $cats = '';
    foreach (news_categories() as $cat) $cats .= '<option value="' . e($cat) . '">';
    $f = ($errors ? '<div class="alert alert-error"><ul>' . implode('', array_map(fn($x) => '<li>' . e($x) . '</li>', $errors)) . '</ul></div>' : '')
        . '<input type="hidden" name="id" value="' . e($n['id']) . '">'
        . field('Titel', input('title', $n['title'], 'text', 'required maxlength="140"'))
        . '<div class="grid3">' . field('Datum', input('date', $n['date'], 'date', 'required'), 'Een datum in de toekomst = pas dan zichtbaar.')
        . field('Categorie', input('category', $n['category'], 'text', 'list="cats" maxlength="60"') . '<datalist id="cats">' . $cats . '</datalist>')
        . field('Adres (laatste deel)', input('slug', $n['slug'], 'text', 'pattern="[a-z0-9-]*" maxlength="80"'), 'Leeg = afgeleid van de titel.') . '</div>'
        . field('Korte samenvatting', '<textarea name="intro" rows="3" maxlength="400">' . e($n['intro']) . '</textarea>', 'Verschijnt op de startpagina. Leeg = begin van de tekst.')
        . '<div class="field image-field"><span>Hoofdafbeelding</span><div class="image-pick">' . input('image', $n['image'], 'text', 'placeholder="Geen" readonly')
        . '<button type="button" class="btn ghost small" data-upload-to="image" data-accept="image/*">Afbeelding kiezen</button><button type="button" class="btn ghost small" data-clear="image">Weghalen</button></div>'
        . ($n['image'] ? '<img class="thumb" src="' . e(str_starts_with($n['image'], '/uploads/') ? base_path() . ltrim($n['image'], '/') : $n['image']) . '" alt="">' : '') . '</div>'
        . '<div class="editor-field"><span class="label">Tekst</span>' . rte('body', $n['body']) . '</div>'
        . '<div class="checks">' . checkbox('published', !empty($n['published']), 'Gepubliceerd') . '</div>'
        . '<div class="form-actions"><button class="btn">Opslaan</button>'
        . (!$isNew && !empty($n['published']) ? '<a class="btn ghost" href="' . e(url(post_path($n))) . '" target="_blank" rel="noopener">Bekijken</a>' : '')
        . '<a class="btn ghost" href="' . e(url('/beheer/nieuws/')) . '">Terug</a></div>';
    $c = post_form('/beheer/nieuws/bewerk/' . ($n['id'] ? '?id=' . $n['id'] : ''), $f, 'form wide');
    if (!$isNew) $c .= '<div class="danger-zone">' . delete_button('/beheer/nieuws/verwijder/', $n['id'], 'Bericht "' . $n['title'] . '" verwijderen?', 'Bericht verwijderen') . '</div>';
    admin_page($isNew ? 'Nieuw bericht' : 'Bericht bewerken', $c, 'nieuws');
}

function admin_news_delete(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $d = load('news', ['posts' => []]);
        $d['posts'] = array_values(array_filter($d['posts'], fn($x) => $x['id'] !== post('id')));
        save('news', $d);
        flash('Bericht verwijderd.');
    }
    redirect('/beheer/nieuws/');
}

/* ---------- Kalender ---------- */

function group_checks(string $name, array $selected, string $emptyLabel): string {
    $o = '<div class="checkgrid">';
    foreach (groups() as $g) $o .= checkbox($name . '[]', in_array($g['id'], $selected, true), $g['naam'], $g['id']);
    return $o . '</div><small>' . e($emptyLabel) . '</small>';
}

function admin_calendar(): void {
    $cal = calendar();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $what = post('what');
        if ($what === 'seizoen') {
            $b = post('begin'); $e2 = post('einde');
            if (valid_date($b) && valid_date($e2) && $b < $e2) { $cal['seizoen'] = ['begin' => $b, 'einde' => $e2]; save('calendar', $cal); flash('Seizoen opgeslagen.'); }
            else flash('Kies een geldige begin- en einddatum.', 'error');
        } elseif ($what === 'vakantie') {
            $v = ['id' => post('id') ?: new_id(), 'naam' => post('naam'), 'van' => post('van'), 'tot' => post('tot'), 'geen_les' => array_values(array_filter((array)($_POST['geen_les'] ?? []), 'is_string'))];
            if ($v['naam'] === '' || !valid_date($v['van']) || !valid_date($v['tot']) || $v['van'] > $v['tot']) flash('Vul een naam en een geldige periode in.', 'error');
            else {
                $cal['vakanties'] = array_values(array_filter($cal['vakanties'], fn($x) => $x['id'] !== $v['id']));
                $cal['vakanties'][] = $v; save('calendar', $cal); flash('Vakantie opgeslagen.');
            }
        } elseif ($what === 'sluiting') {
            $s = ['id' => post('id') ?: new_id(), 'datum' => post('datum'), 'reden' => post('reden')];
            if (!valid_date($s['datum']) || $s['reden'] === '') flash('Vul een datum en een reden in.', 'error');
            else {
                $cal['sluitingsdagen'] = array_values(array_filter($cal['sluitingsdagen'], fn($x) => $x['id'] !== $s['id']));
                $cal['sluitingsdagen'][] = $s; save('calendar', $cal); flash('Sluitingsdag opgeslagen.');
            }
        }
        redirect('/beheer/kalender/' . (post('what') ? '#' . post('what') : ''));
    }

    $showPast = isset($_GET['alles']);
    $ev = $cal['events'];
    usort($ev, fn($a, $b) => strcmp($a['start'], $b['start']));
    $rows = '';
    foreach ($ev as $x) {
        $past = ($x['einde'] ?: $x['start']) < today();
        if ($past && !$showPast) continue;
        $rows .= '<tr' . ($past ? ' class="past"' : '') . '><td class="num">' . nl_date($x['start']) . ($x['einde'] && $x['einde'] !== $x['start'] ? ' – ' . nl_date($x['einde']) : '') . '</td><td><a href="' . e(url('/beheer/kalender/activiteit/?id=' . $x['id'])) . '"><b>' . e($x['titel']) . '</b></a></td><td>' . e(EVENT_TYPES[$x['type']] ?? $x['type']) . '</td><td class="muted">' . e($x['locatie'] ?? '') . '</td></tr>';
    }
    $gnames = array_column(groups(), 'naam', 'id');
    $vrows = '';
    $vs = $cal['vakanties']; usort($vs, fn($a, $b) => strcmp($a['van'], $b['van']));
    foreach ($vs as $v) {
        $edit = '<details><summary>Bewerken</summary>' . post_form('/beheer/kalender/', '<input type="hidden" name="what" value="vakantie"><input type="hidden" name="id" value="' . e($v['id']) . '">'
            . '<div class="grid3">' . field('Naam', input('naam', $v['naam'], 'text', 'required')) . field('Van', input('van', $v['van'], 'date', 'required')) . field('Tot en met', input('tot', $v['tot'], 'date', 'required')) . '</div>'
            . '<div class="field"><span>Geen les voor</span>' . group_checks('geen_les', $v['geen_les'] ?? [], 'Niets aangevinkt = alle groepen trainen normaal.') . '</div><button class="btn small">Opslaan</button>') . '</details>';
        $vrows .= '<tr><td><b>' . e($v['naam']) . '</b>' . $edit . '</td><td class="num">' . nl_date($v['van']) . ' – ' . nl_date($v['tot']) . '</td><td>' . e(implode(', ', array_map(fn($i) => $gnames[$i] ?? $i, $v['geen_les'] ?? [])) ?: '—') . '</td><td class="actions">'
            . delete_button('/beheer/kalender/verwijder/', 'v:' . $v['id'], 'Vakantie "' . $v['naam'] . '" verwijderen?') . '</td></tr>';
    }
    $srows = '';
    $ss = $cal['sluitingsdagen']; usort($ss, fn($a, $b) => strcmp($a['datum'], $b['datum']));
    foreach ($ss as $s) $srows .= '<tr><td class="num">' . nl_date($s['datum']) . '</td><td>' . e($s['reden']) . '</td><td class="actions">' . delete_button('/beheer/kalender/verwijder/', 's:' . $s['id'], 'Sluitingsdag verwijderen?') . '</td></tr>';

    $c = '<section class="card"><div class="cardhead"><h2>Activiteiten</h2><div><a class="btn small" href="' . e(url('/beheer/kalender/activiteit/')) . '">Activiteit toevoegen</a> '
        . ($showPast ? '<a class="btn ghost small" href="' . e(url('/beheer/kalender/')) . '">Alleen komende</a>' : '<a class="btn ghost small" href="' . e(url('/beheer/kalender/?alles=1')) . '">Ook voorbije tonen</a>') . '</div></div>'
        . ($rows ? '<div class="table-wrap"><table class="list"><thead><tr><th>Datum</th><th>Activiteit</th><th>Soort</th><th>Plaats</th></tr></thead><tbody>' . $rows . '</tbody></table></div>' : '<p class="empty">Geen komende activiteiten.</p>') . '</section>'
        . '<section class="card" id="vakantie"><h2>Vakanties</h2><p class="muted small">Bepaalt de vakantietabel op de kalenderpagina en welke trainingen wegvallen in de agenda-bestanden.</p>'
        . ($vrows ? '<div class="table-wrap"><table class="list"><thead><tr><th>Vakantie</th><th>Periode</th><th>Geen les voor</th><th></th></tr></thead><tbody>' . $vrows . '</tbody></table></div>' : '')
        . '<details class="add"><summary class="btn ghost small">Vakantie toevoegen</summary>' . post_form('/beheer/kalender/', '<input type="hidden" name="what" value="vakantie">'
            . '<div class="grid3">' . field('Naam', input('naam', '', 'text', 'required placeholder="Kerstvakantie"')) . field('Van', input('van', '', 'date', 'required')) . field('Tot en met', input('tot', '', 'date', 'required')) . '</div>'
            . '<div class="field"><span>Geen les voor</span>' . group_checks('geen_les', [], 'Niets aangevinkt = alle groepen trainen normaal.') . '</div><button class="btn small">Toevoegen</button>') . '</details></section>'
        . '<section class="card" id="sluiting"><h2>Sluitingsdagen zwembad</h2>'
        . ($srows ? '<div class="table-wrap"><table class="list"><thead><tr><th>Datum</th><th>Reden</th><th></th></tr></thead><tbody>' . $srows . '</tbody></table></div>' : '')
        . post_form('/beheer/kalender/', '<input type="hidden" name="what" value="sluiting"><div class="grid3">' . field('Datum', input('datum', '', 'date', 'required')) . field('Reden', input('reden', '', 'text', 'required placeholder="Wapenstilstand"'))
            . '<div class="field"><span>&nbsp;</span><button class="btn small">Toevoegen</button></div></div>', 'form') . '</section>'
        . '<section class="card" id="seizoen"><h2>Seizoen</h2><p class="muted small">De wekelijkse trainingen in de agenda-bestanden lopen van begin tot einde van het seizoen.</p>'
        . post_form('/beheer/kalender/', '<input type="hidden" name="what" value="seizoen"><div class="grid3">' . field('Begin', input('begin', $cal['seizoen']['begin'] ?? '', 'date', 'required'))
            . field('Einde', input('einde', $cal['seizoen']['einde'] ?? '', 'date', 'required')) . '<div class="field"><span>&nbsp;</span><button class="btn small">Opslaan</button></div></div>', 'form') . '</section>';
    admin_page('Kalender', $c, 'kalender');
}

function admin_event_edit(): void {
    $cal = calendar();
    $id = (string)($_GET['id'] ?? post('id'));
    $ev = null;
    foreach ($cal['events'] as $x) if ($x['id'] === $id) $ev = $x;
    $isNew = !$ev;
    $ev = $ev ?? ['id' => '', 'titel' => '', 'type' => 'wedstrijd', 'start' => today(), 'einde' => '', 'hele_dag' => true, 'van' => '', 'tot' => '', 'locatie' => '', 'omschrijving' => '', 'groepen' => []];
    $errors = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $ev['titel'] = post('titel');
        $ev['type'] = array_key_exists(post('type'), EVENT_TYPES) ? post('type') : 'club';
        $ev['start'] = post('start');
        $ev['einde'] = post('einde');
        $ev['hele_dag'] = (bool)post('hele_dag', '');
        $ev['van'] = $ev['hele_dag'] ? '' : post('van');
        $ev['tot'] = $ev['hele_dag'] ? '' : post('tot');
        $ev['locatie'] = post('locatie');
        $ev['omschrijving'] = mb_substr(post('omschrijving'), 0, 300);
        $ev['groepen'] = array_values(array_filter((array)($_POST['groepen'] ?? []), 'is_string'));
        if ($ev['titel'] === '') $errors[] = 'Geef de activiteit een naam.';
        if (!valid_date($ev['start'])) $errors[] = 'Kies een geldige startdatum.';
        if ($ev['einde'] !== '' && (!valid_date($ev['einde']) || $ev['einde'] < $ev['start'])) $errors[] = 'De einddatum moet na de startdatum liggen.';
        if ($ev['einde'] === $ev['start']) $ev['einde'] = '';
        if (!$ev['hele_dag'] && (!valid_time($ev['van']) || ($ev['tot'] !== '' && !valid_time($ev['tot'])))) $errors[] = 'Vul een geldig uur in (bv. 14:00), of vink "hele dag" aan.';
        if (!$errors) {
            if ($isNew) { $ev['id'] = new_id(); $cal['events'][] = $ev; }
            else foreach ($cal['events'] as &$x) if ($x['id'] === $ev['id']) $x = $ev;
            unset($x);
            save('calendar', $cal);
            flash($isNew ? 'Activiteit toegevoegd.' : 'Activiteit opgeslagen.');
            redirect('/beheer/kalender/');
        }
    }
    $f = ($errors ? '<div class="alert alert-error"><ul>' . implode('', array_map(fn($x) => '<li>' . e($x) . '</li>', $errors)) . '</ul></div>' : '')
        . '<input type="hidden" name="id" value="' . e($ev['id']) . '">'
        . '<div class="grid2">' . field('Naam', input('titel', $ev['titel'], 'text', 'required maxlength="120"')) . field('Soort', select('type', EVENT_TYPES, $ev['type'])) . '</div>'
        . '<div class="grid2">' . field('Datum (van)', input('start', $ev['start'], 'date', 'required')) . field('Tot en met', input('einde', $ev['einde'], 'date'), 'Leeg voor één dag.') . '</div>'
        . '<div class="checks">' . checkbox('hele_dag', !empty($ev['hele_dag']), 'Hele dag', '1') . '</div>'
        . '<div class="grid2" data-times>' . field('Van (uur)', input('van', $ev['van'], 'time')) . field('Tot (uur)', input('tot', $ev['tot'], 'time')) . '</div>'
        . field('Plaats', input('locatie', $ev['locatie'], 'text', 'maxlength="120"'))
        . field('Korte omschrijving', input('omschrijving', $ev['omschrijving'] ?? '', 'text', 'maxlength="300"'), 'Optioneel, verschijnt onder de activiteit.')
        . '<div class="field"><span>Voor welke groepen?</span>' . group_checks('groepen', $ev['groepen'] ?? [], 'Bepaalt in welke agenda-bestanden de activiteit staat. Niets aangevinkt = voor iedereen. Op de website zie je alle activiteiten.') . '</div>'
        . '<div class="form-actions"><button class="btn">Opslaan</button><a class="btn ghost" href="' . e(url('/beheer/kalender/')) . '">Terug</a></div>';
    $c = post_form('/beheer/kalender/activiteit/' . ($ev['id'] ? '?id=' . $ev['id'] : ''), $f, 'form');
    if (!$isNew) $c .= '<div class="danger-zone">' . delete_button('/beheer/kalender/verwijder/', 'e:' . $ev['id'], 'Activiteit "' . $ev['titel'] . '" verwijderen?', 'Activiteit verwijderen') . '</div>';
    admin_page($isNew ? 'Activiteit toevoegen' : 'Activiteit bewerken', $c, 'kalender');
}

function admin_calendar_delete(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        [$kind, $id] = array_pad(explode(':', (string)post('id'), 2), 2, '');
        $cal = calendar();
        $key = ['e' => 'events', 'v' => 'vakanties', 's' => 'sluitingsdagen'][$kind] ?? null;
        if ($key) {
            $cal[$key] = array_values(array_filter($cal[$key], fn($x) => $x['id'] !== $id));
            save('calendar', $cal);
            flash('Verwijderd.');
        }
    }
    redirect('/beheer/kalender/');
}

/* ---------- Groepen & uren ---------- */

function admin_groups(): void {
    $rows = '';
    foreach (groups() as $g) {
        $n = count($g['trainingen'] ?? []);
        $rows .= '<tr><td><a href="' . e(url('/beheer/groepen/bewerk/?id=' . $g['id'])) . '"><b>' . e($g['naam']) . '</b></a>' . (empty($g['zichtbaar']) ? ' <span class="tag off">verborgen</span>' : '')
            . '</td><td>' . e(AFDELINGEN[$g['afdeling']] ?? $g['afdeling']) . '</td><td class="muted">' . e(nl_list(array_map(fn($t) => $t['naam'], group_trainers($g))) ?: '—') . '</td><td class="num">' . $n . ' per week</td></tr>';
    }
    $c = '<p class="toolbar"><a class="btn" href="' . e(url('/beheer/groepen/bewerk/')) . '">Groep toevoegen</a></p>'
        . '<p class="muted small">Groepen van de zwemschool verschijnen op <a href="' . e(url('/zwemschool/lesuren/')) . '" target="_blank" rel="noopener">Lesuren</a>, die van de competitie op <a href="' . e(url('/competitie/trainingsuren/')) . '" target="_blank" rel="noopener">Trainingsuren</a>. Elke groep krijgt ook een eigen agenda-bestand.</p>'
        . '<div class="table-wrap"><table class="list"><thead><tr><th>Groep</th><th>Afdeling</th><th>Trainers</th><th>Uren</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    admin_page('Groepen & uren', $c, 'groepen');
}

function training_row(int $i, array $t): string {
    $dagen = ['' => '—'] + DAGNAMEN;
    return '<tr class="trow"><td>' . select("trainingen[$i][dag]", $dagen, $t['dag'] ?? '') . '</td><td>' . input("trainingen[$i][van]", $t['van'] ?? '', 'time')
        . '</td><td>' . input("trainingen[$i][tot]", $t['tot'] ?? '', 'time') . '</td><td>' . input("trainingen[$i][soort]", $t['soort'] ?? '', 'text', 'placeholder="Zwemmen" list="soorten"')
        . '</td><td>' . input("trainingen[$i][plaats]", $t['plaats'] ?? '', 'text', 'placeholder="Zwembad"') . '</td><td><button type="button" class="btn ghost small" data-remove-row aria-label="Rij verwijderen">✕</button></td></tr>';
}

function admin_group_edit(): void {
    $data = load('groups', ['groups' => []]);
    $id = (string)($_GET['id'] ?? post('orig_id'));
    $g = null;
    foreach (groups() as $x) if ($x['id'] === $id) $g = $x;
    $isNew = !$g;
    $g = $g ?? ['id' => '', 'naam' => '', 'afdeling' => 'zwemschool', 'trainers' => [], 'omschrijving' => '', 'zichtbaar' => true, 'order' => 50, 'trainingen' => []];
    $errors = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $g['naam'] = post('naam');
        $g['afdeling'] = array_key_exists(post('afdeling'), AFDELINGEN) ? post('afdeling') : 'zwemschool';
        $known = array_column(trainers(), 'id');
        $g['trainers'] = array_values(array_intersect(array_filter((array)($_POST['trainers'] ?? []), 'is_string'), $known));
        $g['omschrijving'] = post('omschrijving');
        $g['zichtbaar'] = (bool)post('zichtbaar', '');
        $g['order'] = (int)post('order', 50);
        $rows = [];
        foreach ((array)($_POST['trainingen'] ?? []) as $t) {
            if (!is_array($t)) continue;
            $t = array_map(fn($v) => is_string($v) ? trim($v) : '', $t + ['dag' => '', 'van' => '', 'tot' => '', 'soort' => '', 'plaats' => '']);
            if ($t['dag'] === '' && $t['van'] === '' && $t['tot'] === '') continue;
            if (!isset(DAGNAMEN[$t['dag']]) || !valid_time($t['van']) || !valid_time($t['tot']) || $t['tot'] <= $t['van']) { $errors[] = 'Elke training heeft een dag en een geldig begin- en einduur nodig (einde na begin).'; continue; }
            $rows[] = ['dag' => $t['dag'], 'van' => $t['van'], 'tot' => $t['tot'], 'soort' => mb_substr($t['soort'], 0, 40), 'plaats' => mb_substr($t['plaats'], 0, 60)];
        }
        $order = array_flip(array_keys(DAGNAMEN));
        usort($rows, fn($a, $b) => $order[$a['dag']] <=> $order[$b['dag']] ?: strcmp($a['van'], $b['van']));
        $g['trainingen'] = $rows;
        if ($g['naam'] === '') $errors[] = 'Geef de groep een naam.';
        if ($isNew) {
            $base = slugify($g['naam']); $slug = $base; $i = 2;
            while (in_array($slug, array_column($data['groups'], 'id'), true) || $slug === 'alles') $slug = $base . '-' . $i++;
            $g['id'] = $slug;
        }
        if (!$errors) {
            if ($isNew) $data['groups'][] = $g;
            else foreach ($data['groups'] as &$x) if ($x['id'] === $g['id']) $x = $g;
            unset($x);
            save('groups', $data);
            flash($isNew ? 'Groep toegevoegd.' : 'Groep opgeslagen.');
            redirect('/beheer/groepen/bewerk/?id=' . $g['id']);
        }
    }
    $trs = '';
    foreach ($g['trainingen'] as $i => $t) $trs .= training_row($i, $t);
    $tpl = training_row(999, []);
    $f = ($errors ? '<div class="alert alert-error"><ul>' . implode('', array_map(fn($x) => '<li>' . e($x) . '</li>', array_unique($errors))) . '</ul></div>' : '')
        . '<input type="hidden" name="orig_id" value="' . e($g['id']) . '">'
        . '<div class="grid3">' . field('Naam', input('naam', $g['naam'], 'text', 'required maxlength="60"')) . field('Afdeling', select('afdeling', AFDELINGEN, $g['afdeling']))
        . field('Volgorde', input('order', (string)$g['order'], 'number', 'min="0" max="999"')) . '</div>'
        . field('Korte omschrijving', input('omschrijving', $g['omschrijving'] ?? '', 'text', 'maxlength="120" placeholder="Zwemles · Zwembad – groot bad"'))
        . '<div class="field"><span>Trainers</span>' . trainer_checks($g['trainers'] ?? []) . '</div>'
        . '<div class="checks">' . checkbox('zichtbaar', !empty($g['zichtbaar']), 'Tonen op de website en als agenda-bestand') . '</div>'
        . '<h2>Uren per week</h2><div class="table-wrap"><table class="list trainings"><thead><tr><th>Dag</th><th>Van</th><th>Tot</th><th>Soort</th><th>Plaats</th><th></th></tr></thead><tbody data-rows>' . $trs . '</tbody></table></div>'
        . '<template data-row-template>' . $tpl . '</template><datalist id="soorten"><option value="Zwemles"><option value="Zwemmen"><option value="Droogtraining"></datalist>'
        . '<p><button type="button" class="btn ghost small" data-add-row>Uur toevoegen</button></p>'
        . '<div class="form-actions"><button class="btn">Opslaan</button><a class="btn ghost" href="' . e(url('/beheer/groepen/')) . '">Terug</a></div>';
    $c = post_form('/beheer/groepen/bewerk/' . ($g['id'] ? '?id=' . $g['id'] : ''), $f, 'form wide');
    if (!$isNew) $c .= '<div class="danger-zone">' . delete_button('/beheer/groepen/verwijder/', $g['id'], 'Groep "' . $g['naam'] . '" verwijderen? De uren verdwijnen van de website.', 'Groep verwijderen') . '</div>';
    admin_page($isNew ? 'Groep toevoegen' : 'Groep: ' . $g['naam'], $c, 'groepen');
}

function admin_group_delete(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = post('id');
        $d = load('groups', ['groups' => []]);
        $d['groups'] = array_values(array_filter($d['groups'], fn($x) => $x['id'] !== $id));
        save('groups', $d);
        $cal = calendar();
        foreach ($cal['events'] as &$ev) $ev['groepen'] = array_values(array_diff($ev['groepen'] ?? [], [$id]));
        foreach ($cal['vakanties'] as &$v) $v['geen_les'] = array_values(array_diff($v['geen_les'] ?? [], [$id]));
        unset($ev, $v);
        save('calendar', $cal);
        flash('Groep verwijderd.');
    }
    redirect('/beheer/groepen/');
}

/* ---------- Instellingen ---------- */

function pairs_from_post(string $name, array $keys, int $max = 8): array {
    $out = [];
    foreach ((array)($_POST[$name] ?? []) as $row) {
        if (!is_array($row)) continue;
        $r = [];
        foreach ($keys as $k) $r[$k] = is_string($row[$k] ?? null) ? trim($row[$k]) : '';
        if (implode('', $r) === '') continue;
        $out[] = $r;
        if (count($out) >= $max) break;
    }
    return $out;
}

function admin_settings(): void {
    $s = settings();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $s['hero_title'] = post('hero_title');
        $s['hero_title_accent'] = post('hero_title_accent');
        $s['hero_intro'] = clean_html((string)post('hero_intro'));
        $s['cta_label'] = post('cta_label');
        $s['cta_path'] = post('cta_path') ?: '/';
        $s['footer_tagline'] = post('footer_tagline');
        $s['facebook'] = preg_match('#^https://#', post('facebook')) ? post('facebook') : '';
        $s['emails'] = array_values(array_filter(array_map('trim', explode("\n", (string)post('emails'))), fn($m) => filter_var($m, FILTER_VALIDATE_EMAIL)));
        $s['hero_buttons'] = pairs_from_post('hero_buttons', ['label', 'path'], 3);
        $s['lanes'] = pairs_from_post('lanes', ['title', 'text', 'path'], 2);
        $s['footer_links'] = pairs_from_post('footer_links', ['label', 'path'], 8);
        save('settings', $s);
        flash('Instellingen opgeslagen.');
        redirect('/beheer/instellingen/');
    }
    $pairs = function (string $name, array $rows, array $cols, int $n) {
        $h = '<div class="table-wrap"><table class="list"><thead><tr>';
        foreach ($cols as $label) $h .= '<th>' . e($label) . '</th>';
        $h .= '</tr></thead><tbody>';
        for ($i = 0; $i < $n; $i++) {
            $h .= '<tr>';
            foreach (array_keys($cols) as $k) $h .= '<td>' . input("{$name}[$i][$k]", $rows[$i][$k] ?? '', 'text') . '</td>';
            $h .= '</tr>';
        }
        return $h . '</tbody></table></div>';
    };
    $f = '<section class="card"><h2>Startpagina – bovenaan</h2><div class="grid2">' . field('Titel', input('hero_title', $s['hero_title'])) . field('Titel, tweede regel (lichtblauw)', input('hero_title_accent', $s['hero_title_accent'])) . '</div>'
        . '<div class="editor-field"><span class="label">Welkomsttekst</span>' . rte('hero_intro', $s['hero_intro']) . '</div>'
        . '<div class="field"><span>Knoppen (de eerste is geel)</span>' . $pairs('hero_buttons', $s['hero_buttons'], ['label' => 'Tekst', 'path' => 'Gaat naar (bv. /lid-worden/)'], 3) . '</div>'
        . '<div class="field"><span>Twee grote vlakken onder de titel</span>' . $pairs('lanes', $s['lanes'], ['title' => 'Titel', 'text' => 'Tekst', 'path' => 'Gaat naar'], 2) . '</div></section>'
        . '<section class="card"><h2>Menu</h2><div class="grid2">' . field('Gele knop in het menu', input('cta_label', $s['cta_label']), 'Leeg = geen knop.') . field('Gele knop gaat naar', input('cta_path', $s['cta_path'])) . '</div></section>'
        . '<section class="card"><h2>Voettekst</h2>' . field('Korte zin onder de clubnaam', input('footer_tagline', $s['footer_tagline']))
        . field('E-mailadressen (één per regel)', '<textarea name="emails" rows="3">' . e(implode("\n", $s['emails'])) . '</textarea>')
        . field('Facebookpagina', input('facebook', $s['facebook'], 'url', 'placeholder="https://www.facebook.com/…"'))
        . '<div class="field"><span>Links onder "Snel naar"</span>' . $pairs('footer_links', $s['footer_links'] ?? [], ['label' => 'Tekst', 'path' => 'Gaat naar'], 7) . '</div></section>'
        . '<div class="form-actions"><button class="btn">Opslaan</button></div>';
    admin_page('Instellingen', post_form('/beheer/instellingen/', $f, 'form wide'), 'instellingen');
}

/* ---------- Beheerders ---------- */

function admin_users(): void {
    $me = current_user();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = post('name'); $email = strtolower(post('email')); $pw = (string)post('password');
        $data = load('users', ['users' => []]);
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) flash('Vul een naam en een geldig e-mailadres in.', 'error');
        elseif (in_array($email, array_column($data['users'], 'email'), true)) flash('Dit e-mailadres heeft al een account.', 'error');
        elseif (strlen($pw) < 10) flash('Het wachtwoord moet minstens 10 tekens hebben.', 'error');
        else {
            $data['users'][] = ['id' => new_id(), 'name' => $name, 'email' => $email, 'hash' => password_hash($pw, PASSWORD_DEFAULT), 'created' => date('c')];
            save('users', $data);
            flash("Beheerder $name toegevoegd. Geef het wachtwoord persoonlijk door.");
        }
        redirect('/beheer/gebruikers/');
    }
    $rows = '';
    foreach (users() as $u) $rows .= '<tr><td><b>' . e($u['name']) . '</b>' . ($u['id'] === $me['id'] ? ' <span class="tag">jij</span>' : '') . '</td><td>' . e($u['email']) . '</td><td class="actions">'
        . ($u['id'] !== $me['id'] ? delete_button('/beheer/gebruikers/verwijder/', $u['id'], 'Beheerder ' . $u['name'] . ' verwijderen?') : '') . '</td></tr>';
    $c = '<div class="table-wrap"><table class="list"><thead><tr><th>Naam</th><th>E-mailadres</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
        . '<section class="card"><h2>Beheerder toevoegen</h2>' . post_form('/beheer/gebruikers/', '<div class="grid3">' . field('Naam', input('name', '', 'text', 'required'))
            . field('E-mailadres', input('email', '', 'email', 'required autocomplete="off"')) . field('Tijdelijk wachtwoord', input('password', '', 'text', 'required minlength="10" autocomplete="new-password"'), 'Minstens 10 tekens. Laat de persoon het daarna zelf wijzigen.') . '</div><button class="btn small">Toevoegen</button>', 'form') . '</section>';
    admin_page('Beheerders', $c, 'gebruikers');
}

function admin_user_delete(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = post('id');
        if ($id === current_user()['id']) { flash('Je kunt jezelf niet verwijderen.', 'error'); redirect('/beheer/gebruikers/'); }
        $d = load('users', ['users' => []]);
        $d['users'] = array_values(array_filter($d['users'], fn($u) => $u['id'] !== $id));
        save('users', $d);
        flash('Beheerder verwijderd.');
    }
    redirect('/beheer/gebruikers/');
}

function admin_password(): void {
    $me = current_user();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $old = (string)post('old'); $new = (string)post('new'); $again = (string)post('again');
        if (!password_verify($old, $me['hash'])) flash('Je huidige wachtwoord klopt niet.', 'error');
        elseif (strlen($new) < 10) flash('Kies een nieuw wachtwoord van minstens 10 tekens.', 'error');
        elseif ($new !== $again) flash('De twee nieuwe wachtwoorden zijn niet gelijk.', 'error');
        else {
            $d = load('users');
            foreach ($d['users'] as &$u) if ($u['id'] === $me['id']) $u['hash'] = password_hash($new, PASSWORD_DEFAULT);
            unset($u);
            save('users', $d);
            session_regenerate_id(true);
            flash('Je wachtwoord is gewijzigd.');
        }
        redirect('/beheer/wachtwoord/');
    }
    $f = field('Huidig wachtwoord', input('old', '', 'password', 'required autocomplete="current-password"'))
        . field('Nieuw wachtwoord', input('new', '', 'password', 'required minlength="10" autocomplete="new-password"'), 'Minstens 10 tekens.')
        . field('Nieuw wachtwoord (nogmaals)', input('again', '', 'password', 'required minlength="10" autocomplete="new-password"'))
        . '<button class="btn">Wijzigen</button>';
    admin_page('Wachtwoord wijzigen', post_form('/beheer/wachtwoord/', $f, 'form narrow card'));
}

/* ---------- Bestanden ---------- */

const UPLOAD_TYPES = [
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
    'pdf' => 'application/pdf', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'doc' => 'application/msword', 'xls' => 'application/vnd.ms-excel',
];

function admin_upload(): void {
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['file'])) { http_response_code(400); echo json_encode(['error' => 'Geen bestand ontvangen.']); return; }
    $f = $_FILES['file'];
    if ($f['error'] !== UPLOAD_ERR_OK) { http_response_code(400); echo json_encode(['error' => 'Het uploaden is mislukt (te groot?).']); return; }
    $max = (int)cfg('max_upload_mb', 10) * 1024 * 1024;
    if ($f['size'] > $max) { http_response_code(400); echo json_encode(['error' => 'Het bestand is groter dan ' . cfg('max_upload_mb', 10) . ' MB.']); return; }
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!isset(UPLOAD_TYPES[$ext])) { http_response_code(400); echo json_encode(['error' => 'Dit soort bestand is niet toegestaan. Gebruik jpg, png, webp, gif, pdf, docx of xlsx.']); return; }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $isImg = str_starts_with(UPLOAD_TYPES[$ext], 'image/');
    if ($isImg && (!@getimagesize($f['tmp_name']) || !str_starts_with((string)$mime, 'image/'))) { http_response_code(400); echo json_encode(['error' => 'Dit is geen geldige afbeelding.']); return; }
    if ($ext === 'pdf' && $mime !== 'application/pdf') { http_response_code(400); echo json_encode(['error' => 'Dit is geen geldig pdf-bestand.']); return; }
    $dir = UPLOAD_DIR . '/' . date('Y/m');
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $base = slugify(pathinfo($f['name'], PATHINFO_FILENAME)) ?: 'bestand';
    $name = "$base.$ext"; $i = 2;
    while (is_file("$dir/$name")) $name = "$base-" . $i++ . ".$ext";
    if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) { http_response_code(500); echo json_encode(['error' => 'Kon het bestand niet bewaren.']); return; }
    @chmod("$dir/$name", 0644);
    $rel = '/uploads/' . date('Y/m') . '/' . $name;
    echo json_encode(['url' => $rel, 'src' => base_path() . ltrim($rel, '/'), 'name' => $name, 'image' => $isImg]);
}

function list_uploads(): array {
    $out = [];
    if (!is_dir(UPLOAD_DIR)) return $out;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(UPLOAD_DIR, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || str_starts_with($f->getFilename(), '.')) continue;
        $rel = '/uploads/' . ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen(UPLOAD_DIR))), '/');
        $out[] = ['rel' => $rel, 'size' => $f->getSize(), 'time' => $f->getMTime()];
    }
    usort($out, fn($a, $b) => $b['time'] <=> $a['time']);
    return $out;
}

function admin_media(): void {
    $rows = '';
    foreach (list_uploads() as $f) {
        $src = base_path() . ltrim($f['rel'], '/');
        $isImg = preg_match('/\.(jpe?g|png|gif|webp)$/i', $f['rel']);
        $rows .= '<tr><td>' . ($isImg ? '<img class="thumb small" src="' . e($src) . '" alt="">' : '📄') . '</td><td><a href="' . e($src) . '" target="_blank" rel="noopener">' . e(basename($f['rel'])) . '</a><br><code class="small">' . e($f['rel']) . '</code></td><td class="num">'
            . number_format($f['size'] / 1024, 0, ',', '.') . ' kB</td><td class="num">' . date('d/m/Y', $f['time']) . '</td><td class="actions">' . delete_button('/beheer/media/verwijder/', $f['rel'], 'Bestand verwijderen? Pagina\'s die het gebruiken tonen het dan niet meer.') . '</td></tr>';
    }
    $c = '<p class="toolbar"><button type="button" class="btn" data-upload-only>Bestand uploaden</button></p>'
        . '<p class="muted small">Afbeeldingen en documenten voeg je meestal rechtstreeks in vanuit de editor. Hier zie je alles wat al geüpload is.</p>'
        . ($rows ? '<div class="table-wrap"><table class="list"><thead><tr><th></th><th>Bestand</th><th>Grootte</th><th>Datum</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div>' : '<p class="empty">Nog geen bestanden.</p>');
    admin_page('Bestanden', $c, 'media');
}

function admin_media_delete(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $rel = (string)post('id');
        $real = realpath(ROOT_DIR . $rel);
        $root = realpath(UPLOAD_DIR);
        if ($real && $root && str_starts_with($real, $root . DIRECTORY_SEPARATOR) && is_file($real)) { unlink($real); flash('Bestand verwijderd.'); }
        else flash('Bestand niet gevonden.', 'error');
    }
    redirect('/beheer/media/');
}

function admin_backups(): void {
    $files = glob(DATA_DIR . '/backup/*.json') ?: [];
    rsort($files);
    $rows = '';
    foreach (array_slice($files, 0, 60) as $f) $rows .= '<tr><td><code>' . e(basename($f)) . '</code></td><td class="num">' . date('d/m/Y H:i', filemtime($f)) . '</td></tr>';
    admin_page('Back-ups', '<p class="muted">Bij elke wijziging bewaart de site automatisch een kopie in <code>data/backup</code> (de laatste 40 per onderdeel). Terugzetten: kopieer het gewenste bestand via FTP over het bestand in <code>data/</code> en hernoem het (bv. <code>pages.json</code>).</p>'
        . ($rows ? '<div class="table-wrap"><table class="list"><thead><tr><th>Bestand</th><th>Datum</th></tr></thead><tbody>' . $rows . '</tbody></table></div>' : '<p class="empty">Nog geen back-ups.</p>'), '');
}


/* ---------- Trainers ---------- */

function trainer_checks(array $selected): string {
    $ts = trainers();
    $add = '<a href="' . e(url('/beheer/trainers/bewerk/')) . '">Trainer toevoegen</a>';
    if (!$ts) return '<p class="muted small">Er zijn nog geen trainers. ' . $add . '</p>';
    $o = '<div class="checkgrid">';
    foreach ($ts as $t) $o .= checkbox('trainers[]', in_array($t['id'], $selected, true), $t['naam'], $t['id']);
    return $o . '</div><small>Op de website staan de namen alfabetisch. ' . $add . '</small>';
}

function trainer_groups(string $id): array {
    return array_values(array_filter(groups(), fn($g) => in_array($id, $g['trainers'] ?? [], true)));
}

function admin_trainers(): void {
    $rows = '';
    foreach (trainers() as $t) {
        $gn = nl_list(array_map(fn($g) => $g['naam'], trainer_groups($t['id'])));
        $rows .= '<tr><td><a href="' . e(url('/beheer/trainers/bewerk/?id=' . $t['id'])) . '"><b>' . e($t['naam']) . '</b></a>' . (empty($t['toon_contact']) ? ' <span class="tag">contact verborgen</span>' : '')
            . '</td><td class="num">' . e($t['telefoon'] ?: '—') . '</td><td>' . e($t['email'] ?: '—') . '</td><td class="muted">' . e($gn ?: 'geen groep') . '</td></tr>';
    }
    $page = page_by_path('/competitie/trainers/');
    $c = '<p class="toolbar"><a class="btn" href="' . e(url('/beheer/trainers/bewerk/')) . '">Trainer toevoegen</a></p>'
        . '<p class="muted small">Trainers koppel je aan groepen (hier of bij <a href="' . e(url('/beheer/groepen/')) . '">Groepen &amp; uren</a>). Hun namen verschijnen bij de uren, en met contactgegevens op '
        . ($page ? '<a href="' . e(url($page['path'])) . '" target="_blank" rel="noopener">' . e($page['title']) . '</a>' : 'de trainerspagina') . ' en overal waar <code>[[trainers]]</code> in een pagina staat.</p>'
        . ($rows ? '<div class="table-wrap"><table class="list"><thead><tr><th>Naam</th><th>Telefoon</th><th>E-mail</th><th>Groepen</th></tr></thead><tbody>' . $rows . '</tbody></table></div>' : '<p class="empty">Nog geen trainers.</p>');
    admin_page('Trainers', $c, 'trainers');
}

function admin_trainer_edit(): void {
    $data = load('trainers', ['trainers' => []]);
    $id = (string)($_GET['id'] ?? post('id'));
    $t = null;
    foreach ($data['trainers'] as $x) if ($x['id'] === $id) $t = $x;
    if ($id !== '' && !$t) { flash('Trainer niet gevonden.', 'error'); redirect('/beheer/trainers/'); }
    $isNew = !$t;
    $t = $t ?? ['id' => '', 'naam' => '', 'telefoon' => '', 'email' => '', 'toon_contact' => true];
    $sel = $isNew ? [] : array_column(trainer_groups($t['id']), 'id');
    $errors = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $t['naam'] = mb_substr(post('naam'), 0, 80);
        $t['telefoon'] = mb_substr(post('telefoon'), 0, 30);
        $t['email'] = strtolower(post('email'));
        $t['toon_contact'] = (bool)post('toon_contact', '');
        $sel = array_values(array_filter((array)($_POST['groepen'] ?? []), 'is_string'));
        if ($t['naam'] === '') $errors[] = 'Vul de naam van de trainer in.';
        if ($t['email'] !== '' && !filter_var($t['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Het e-mailadres is niet geldig.';
        if ($t['telefoon'] !== '' && !preg_match('/^[0-9 +().\/-]{6,30}$/', $t['telefoon'])) $errors[] = 'Het telefoonnummer mag enkel cijfers, spaties en + . / - ( ) bevatten.';
        foreach ($data['trainers'] as $x) if ($x['id'] !== $t['id'] && mb_strtolower($x['naam']) === mb_strtolower($t['naam'])) $errors[] = 'Er bestaat al een trainer met deze naam.';
        if (!$errors) {
            if ($isNew) {
                $base = slugify($t['naam']); $slug = $base; $i = 2;
                while (in_array($slug, array_column($data['trainers'], 'id'), true)) $slug = $base . '-' . $i++;
                $t['id'] = $slug;
                $data['trainers'][] = $t;
            } else foreach ($data['trainers'] as &$x) if ($x['id'] === $t['id']) $x = $t;
            unset($x);
            save('trainers', $data);
            // Koppeling met groepen bijwerken
            $gd = load('groups', ['groups' => []]);
            foreach ($gd['groups'] as &$g) {
                $cur = is_array($g['trainers'] ?? null) ? $g['trainers'] : trainer_ids_from_names((string)($g['trainers'] ?? ''));
                $has = in_array($t['id'], $cur, true);
                $want = in_array($g['id'], $sel, true);
                if ($want && !$has) $cur[] = $t['id'];
                if (!$want && $has) $cur = array_values(array_diff($cur, [$t['id']]));
                $g['trainers'] = array_values($cur);
            }
            unset($g);
            save('groups', $gd);
            flash($isNew ? 'Trainer toegevoegd.' : 'Trainer opgeslagen.');
            redirect('/beheer/trainers/bewerk/?id=' . $t['id']);
        }
    }
    $f = ($errors ? '<div class="alert alert-error"><ul>' . implode('', array_map(fn($x) => '<li>' . e($x) . '</li>', array_unique($errors))) . '</ul></div>' : '')
        . '<input type="hidden" name="id" value="' . e($t['id']) . '">'
        . field('Naam', input('naam', $t['naam'], 'text', 'required maxlength="80" placeholder="Voornaam Achternaam"'))
        . '<div class="grid2">' . field('Telefoon', input('telefoon', $t['telefoon'], 'tel', 'maxlength="30" placeholder="0470 12 34 56"'))
        . field('E-mailadres', input('email', $t['email'], 'email', 'maxlength="120"')) . '</div>'
        . '<div class="checks">' . checkbox('toon_contact', !empty($t['toon_contact']), 'Telefoon en e-mail tonen op de website') . '</div>'
        . '<div class="field"><span>Groepen</span>' . group_checks('groepen', $sel, 'De trainer verschijnt bij de uren en op de trainerspagina van deze groepen.') . '</div>'
        . '<div class="form-actions"><button class="btn">Opslaan</button><a class="btn ghost" href="' . e(url('/beheer/trainers/')) . '">Terug</a></div>';
    $c = post_form('/beheer/trainers/bewerk/' . ($t['id'] ? '?id=' . $t['id'] : ''), $f, 'form');
    if (!$isNew) $c .= '<div class="danger-zone">' . delete_button('/beheer/trainers/verwijder/', $t['id'], 'Trainer "' . $t['naam'] . '" verwijderen? De trainer verdwijnt ook uit alle groepen.', 'Trainer verwijderen') . '</div>';
    admin_page($isNew ? 'Trainer toevoegen' : 'Trainer: ' . $t['naam'], $c, 'trainers');
}

function admin_trainer_delete(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = post('id');
        $d = load('trainers', ['trainers' => []]);
        $d['trainers'] = array_values(array_filter($d['trainers'], fn($x) => $x['id'] !== $id));
        save('trainers', $d);
        $gd = load('groups', ['groups' => []]);
        foreach ($gd['groups'] as &$g) if (is_array($g['trainers'] ?? null)) $g['trainers'] = array_values(array_diff($g['trainers'], [$id]));
        unset($g);
        save('groups', $gd);
        flash('Trainer verwijderd en losgekoppeld van de groepen.');
    }
    redirect('/beheer/trainers/');
}
