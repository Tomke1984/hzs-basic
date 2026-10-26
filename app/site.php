<?php
declare(strict_types=1);

/* ================= Gegevens ================= */

function settings(): array {
    return load('settings', []) + [
        'hero_title' => 'Hasseltse Zwemvereniging', 'hero_title_accent' => 'Spartacus', 'hero_intro' => '',
        'cta_label' => 'Inschrijven zwemtest', 'cta_path' => '/zwemschool/inschrijven/',
        'footer_tagline' => '', 'emails' => [], 'facebook' => '', 'lanes' => [],
        'hero_buttons' => [],
    ];
}

function pages(): array { return load('pages', ['pages' => []])['pages']; }

function page_by_path(string $path): ?array {
    foreach (pages() as $p) if ($p['path'] === $path) return $p;
    return null;
}

function page_children(string $path, bool $menuOnly = true): array {
    $out = array_values(array_filter(pages(), fn($p) => ($p['parent'] ?? null) === $path && !empty($p['visible']) && (!$menuOnly || !empty($p['in_menu']))));
    usort($out, fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0) ?: strcmp($a['title'], $b['title']));
    return $out;
}

function top_pages(): array {
    $out = array_values(array_filter(pages(), fn($p) => empty($p['parent']) && !empty($p['visible']) && !empty($p['in_menu']) && $p['type'] !== 'home'));
    usort($out, fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
    return $out;
}

function page_href(array $p): string { return ($p['type'] ?? '') === 'link' ? ($p['target'] ?? '/') : $p['path']; }

function posts(bool $publishedOnly = true): array {
    $all = load('news', ['posts' => []])['posts'];
    if ($publishedOnly) $all = array_filter($all, fn($n) => !empty($n['published']) && $n['date'] <= today());
    usort($all, fn($a, $b) => strcmp($b['date'], $a['date']) ?: strcmp($b['id'], $a['id']));
    return array_values($all);
}

function post_path(array $n): string {
    return '/' . str_replace('-', '/', $n['date']) . '/' . $n['slug'] . '/';
}

function calendar(): array {
    return load('calendar', []) + ['seizoen' => ['begin' => '', 'einde' => ''], 'events' => [], 'vakanties' => [], 'sluitingsdagen' => []];
}

function groups(bool $visibleOnly = false): array {
    $g = load('groups', ['groups' => []])['groups'];
    if ($visibleOnly) $g = array_filter($g, fn($x) => !empty($x['zichtbaar']));
    foreach ($g as &$x) $x['trainers'] = is_array($x['trainers'] ?? null) ? array_values($x['trainers']) : trainer_ids_from_names((string)($x['trainers'] ?? ''));
    unset($x);
    usort($g, fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
    return array_values($g);
}

/* ---------- Trainers ---------- */

function trainers(): array {
    $t = array_map('trainer_normalize', load('trainers', ['trainers' => []])['trainers']);
    usort($t, fn($a, $b) => strcasecmp($a['voornaam'], $b['voornaam']) ?: strcasecmp($a['achternaam'], $b['achternaam']));
    return $t;
}

/** Voornaam en naam apart; 'naam' is altijd de volledige naam met de voornaam eerst. */
function trainer_normalize(array $t): array {
    if (!isset($t['voornaam'])) {
        $parts = preg_split('/\s+/', trim((string)($t['naam'] ?? '')), 2);
        $t['voornaam'] = $parts[0] ?? '';
        $t['achternaam'] = $parts[1] ?? '';
    }
    $t['achternaam'] = (string)($t['achternaam'] ?? '');
    $t['naam'] = trim($t['voornaam'] . ' ' . $t['achternaam']);
    return $t;
}

function trainer_map(): array {
    $m = [];
    foreach (trainers() as $t) $m[$t['id']] = $t;
    return $m;
}

/** Oude vrije tekst ("Lander en Antoinette") omzetten naar trainer-ids. */
function trainer_ids_from_names(string $names): array {
    $ids = [];
    foreach (preg_split('/\s*(?:,|\ben\b|&|\/)\s*/iu', trim($names)) as $n) {
        $n = mb_strtolower(trim($n));
        if ($n === '') continue;
        foreach (trainers() as $t) {
            if (mb_strtolower($t['naam']) === $n || mb_strtolower($t['voornaam']) === $n) { $ids[] = $t['id']; break; }
        }
    }
    return array_values(array_unique($ids));
}

/** Trainers van een groep, alfabetisch. */
function group_trainers(array $g): array {
    $ids = $g['trainers'] ?? [];
    return array_values(array_filter(trainers(), fn($t) => in_array($t['id'], $ids, true)));
}

/** Namen netjes opsommen: "A", "A en B", "A, B en C". */
function nl_list(array $names): string {
    $names = array_values(array_filter($names, fn($n) => $n !== ''));
    if (count($names) < 2) return $names[0] ?? '';
    return implode(', ', array_slice($names, 0, -1)) . ' en ' . end($names);
}

function phone_href(string $phone): string {
    $d = preg_replace('/[^0-9+]/', '', $phone);
    if (str_starts_with($d, '00')) $d = '+' . substr($d, 2);
    elseif (str_starts_with($d, '0')) $d = '+32' . substr($d, 1);
    return 'tel:' . $d;
}

const EVENT_TYPES = ['wedstrijd' => 'Wedstrijd', 'club' => 'Clubactiviteit', 'stage' => 'Stage', 'zwemschool' => 'Zwemschool'];
const AFDELINGEN = ['zwemschool' => 'Zwemschool', 'competitie' => 'Competitie'];

/* ================= Layout ================= */

function nav_html(string $current): string {
    $s = settings();
    $out = [];
    foreach (top_pages() as $p) {
        $href = page_href($p);
        $cur = $current === $p['path'] ? ' aria-current="page"' : '';
        $kids = page_children($p['path']);
        if ($kids) {
            $sub = '';
            foreach ($kids as $k) {
                $kc = $current === $k['path'] ? ' aria-current="page"' : '';
                $sub .= '<a href="' . e(url(page_href($k))) . '"' . $kc . '>' . e($k['menu_title'] ?: $k['title']) . '</a>';
            }
            $out[] = '<span class="nav-group"><a href="' . e(url($href)) . '"' . $cur . '>' . e($p['menu_title'] ?: $p['title']) . '</a><span class="sub">' . $sub . '</span></span>';
        } else {
            $out[] = '<a href="' . e(url($href)) . '"' . $cur . '>' . e($p['menu_title'] ?: $p['title']) . '</a>';
        }
    }
    if (!empty($s['cta_label'])) $out[] = '<a class="nav-cta" href="' . e(url($s['cta_path'])) . '">' . e($s['cta_label']) . '</a>';
    return implode("\n      ", $out);
}

function layout(string $route, string $title, string $body, string $description = ''): string {
    $s = settings();
    $full = $route === '/' ? 'Hasseltse Zwemvereniging Spartacus' : "$title · HZS";
    $description = $description ?: 'Zwemclub in Hasselt voor zwemschool, recreatie en competitie.';
    $mails = '';
    foreach ($s['emails'] as $m) $mails .= '<a href="mailto:' . e($m) . '" style="display:inline">' . e($m) . '</a><br>';
    if (!empty($s['facebook'])) $mails .= '<a href="' . e($s['facebook']) . '" style="display:inline">Facebook</a>';
    $club = ''; foreach (top_pages() as $p) if (($p['type'] ?? '') !== 'link') $club .= '<a href="' . e(url($p['path'])) . '">' . e($p['menu_title'] ?: $p['title']) . '</a>';
    $quick = '';
    foreach ($s['footer_links'] ?? [] as $l) $quick .= '<a href="' . e(url($l['path'])) . '">' . e($l['label']) . '</a>';
    $privacy = page_by_path('/privacy/') ? ' · <a href="' . e(url('/privacy/')) . '">Privacy</a>' : '';
    $year = date('Y');
    $nav = nav_html($route);
    $E = 'e'; $U = fn($p) => e(url($p)); $A = fn($f) => e(asset($f));
    $tag = e($s['footer_tagline']);
    return <<<HTML
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#05264d">
<meta name="description" content="{$E($description)}">
<title>{$E($full)}</title>
<link rel="icon" href="{$A('logo.png')}">
<link rel="stylesheet" href="{$A('site.css')}">
</head>
<body class="site">
<a class="skip" href="#inhoud">Naar de inhoud</a>
<header class="site-head">
  <div class="wrap bar">
    <a class="brand" href="{$U('/')}" aria-label="Hasseltse Zwemvereniging Spartacus"><img src="{$A('logo.png')}" alt="" width="58" height="51"><span><small>Hasseltse Zwemvereniging</small><b>Spartacus</b></span></a>
    <button class="menu-btn" type="button" aria-expanded="false" aria-controls="site-nav" hidden>Menu</button>
    <nav class="site-nav" id="site-nav" aria-label="Hoofdmenu">
      $nav
    </nav>
  </div>
  <div class="rope" aria-hidden="true"></div>
</header>
<main id="inhoud">
$body
</main>
<footer class="site-foot">
  <div class="rope thin" aria-hidden="true"></div>
  <div class="wrap inner">
    <div><b>Hasseltse Zwemvereniging Spartacus</b>$tag
      <p style="margin:12px 0 0">$mails</p></div>
    <div><b>De club</b>$club</div>
    <div><b>Snel naar</b>$quick</div>
  </div>
  <div class="wrap legal">© $year HZS$privacy</div>
</footer>
<script src="{$A('site.js')}"></script>
</body>
</html>
HTML;
}

function banner(string $title, string $sub = '', ?array $crumb = null): string {
    $c = $crumb ? '<p class="crumbs"><a href="' . e(url($crumb[1])) . '">' . e($crumb[0]) . '</a></p>' : '';
    $s = $sub !== '' ? '<p class="banner-sub">' . $sub . '</p>' : '';
    return "<div class=\"banner on-deep\">\n  <div class=\"wrap\">\n    $c<h1>" . e($title) . "</h1>$s\n  </div>\n</div>";
}

function top_ancestor(array $page): ?array {
    $seen = 0;
    while (!empty($page['parent']) && $seen++ < 10) {
        $up = page_by_path($page['parent']);
        if (!$up) break;
        $page = $up;
    }
    return $page;
}

function content_page(array $page, string $inner): string {
    $top = top_ancestor($page);
    $crumb = null;
    if (!empty($page['parent']) && ($par = page_by_path($page['parent']))) $crumb = [$par['menu_title'] ?: $par['title'], $par['path']];
    $side = '';
    if (!empty($page['side']) && $top && ($kids = page_children($top['path']))) {
        $links = '';
        foreach ($kids as $k) {
            if (($k['type'] ?? '') === 'link') continue;
            $cur = $k['path'] === $page['path'] ? ' aria-current="page"' : '';
            $links .= '<a href="' . e(url($k['path'])) . '"' . $cur . '>' . e($k['menu_title'] ?: $k['title']) . '</a>';
        }
        $side = '<nav class="side" aria-label="In deze rubriek"><b><a href="' . e(url($top['path'])) . '">' . e($top['menu_title'] ?: $top['title']) . '</a></b>' . $links . '</nav>';
    }
    $grid = $side ? 'page-grid' : 'page-grid solo';
    $wide = $side ? '' : ' wide';
    return banner($page['title'], e($page['sub'] ?? ''), $crumb) . "\n<div class=\"wrap $grid\">\n  $side\n  <article>\n    <div class=\"prose$wide\">\n$inner\n    </div>\n  </article>\n</div>";
}

/* ================= Blokken ================= */

function event_tile(array $ev): string {
    $s = strtotime($ev['start']);
    $en = strtotime($ev['einde'] ?: $ev['start']);
    $meta = [];
    if (!empty($ev['hele_dag'])) {
        if ($ev['einde'] && $ev['einde'] !== $ev['start']) $meta[] = 't/m ' . nl_date($ev['einde'], false);
    } else {
        $t = trim(($ev['van'] ?? '') . (!empty($ev['tot']) ? '–' . $ev['tot'] : ''), '–');
        if ($t) $meta[] = e($t);
        if ($ev['einde'] && $ev['einde'] !== $ev['start']) $meta[] = 't/m ' . nl_date($ev['einde'], false);
    }
    if (!empty($ev['locatie'])) $meta[] = e($ev['locatie']);
    $cls = $ev['type'] === 'wedstrijd' ? 'competition' : '';
    $chip = '<span class="chip">' . e(EVENT_TYPES[$ev['type']] ?? $ev['type']) . '</span> ';
    $omschr = !empty($ev['omschrijving']) ? '<p>' . e($ev['omschrijving']) . '</p>' : '';
    return '<div class="tile ' . $cls . '"><div class="date"><b>' . (int)date('j', $s) . '</b><i>' . MND[(int)date('n', $s) - 1] . '</i></div>'
        . '<div><h3>' . e($ev['titel']) . '</h3><p>' . $chip . implode(' · ', $meta) . '</p>' . $omschr . '</div></div>';
}

function upcoming_events(?int $n = null): array {
    $today = today();
    $ev = array_filter(calendar()['events'], fn($e) => ($e['einde'] ?: $e['start']) >= $today);
    usort($ev, fn($a, $b) => strcmp($a['start'], $b['start']) ?: strcmp($a['van'] ?? '', $b['van'] ?? ''));
    return $n ? array_slice($ev, 0, $n) : array_values($ev);
}

function block_kalender(): string {
    $cal = calendar();
    $out = []; $month = null;
    foreach (upcoming_events() as $ev) {
        $k = substr($ev['start'], 0, 7);
        if ($k !== $month) {
            if ($month) $out[] = '</div>';
            $t = strtotime($ev['start']);
            $out[] = '<h2 class="month">' . ucfirst(MAANDEN[(int)date('n', $t) - 1]) . ' ' . date('Y', $t) . '</h2><div class="tiles">';
            $month = $k;
        }
        $out[] = event_tile($ev);
    }
    if ($month) $out[] = '</div>';
    $html = $out ? implode("\n", $out) : '<p class="empty">Er staan geen activiteiten gepland.</p>';

    $gnames = [];
    foreach (groups() as $g) $gnames[$g['id']] = $g['naam'];
    $vak = '';
    $vs = $cal['vakanties']; usort($vs, fn($a, $b) => strcmp($a['van'], $b['van']));
    foreach ($vs as $v) {
        $geen = array_map(fn($id) => $gnames[$id] ?? $id, $v['geen_les'] ?? []);
        $vak .= '<tr><td>' . e($v['naam']) . '</td><td class="num">' . nl_date($v['van']) . ' – ' . nl_date($v['tot']) . '</td><td>'
            . ($geen ? 'Geen les voor: ' . e(implode(', ', $geen)) : 'Lessen en trainingen volgens de normale uren') . '</td></tr>';
    }
    $sl = '';
    $ss = $cal['sluitingsdagen']; usort($ss, fn($a, $b) => strcmp($a['datum'], $b['datum']));
    foreach ($ss as $s) $sl .= '<tr><td class="num">' . nl_date($s['datum']) . '</td><td>' . e($s['reden']) . '</td></tr>';
    $season = ($cal['seizoen']['begin'] ? substr($cal['seizoen']['begin'], 0, 4) . '–' . substr($cal['seizoen']['einde'], 0, 4) : '');
    if ($vak) $html .= "\n<h2 id=\"sluitingsdagen\">Vakanties en sluitingsdagen $season</h2>\n<div class=\"table-wrap\"><table><thead><tr><th>Vakantie</th><th>Periode</th><th>Lessen</th></tr></thead><tbody>$vak</tbody></table></div>";
    if ($sl) $html .= ($vak ? '' : "\n<h2 id=\"sluitingsdagen\">Sluitingsdagen</h2>") . "\n<div class=\"table-wrap\"><table><thead><tr><th>Datum</th><th>Sluitingsdag zwembad</th></tr></thead><tbody>$sl</tbody></table></div>";

    $ics = '<li><a href="' . e(url('/kalender/ics/alles.ics')) . '">Alles (activiteiten en sluitingsdagen)</a></li>';
    foreach (groups(true) as $g) $ics .= '<li><a href="' . e(url('/kalender/ics/' . $g['id'] . '.ics')) . '">' . e($g['naam']) . '</a></li>';
    $html .= "\n<h2 id=\"agenda\">In je eigen agenda</h2>\n<p>Voeg de link van je groep toe als agenda-abonnement in je gsm, Outlook of Google Agenda (“agenda toevoegen via URL”). Wijzigingen komen dan vanzelf mee.</p>\n<ul class=\"ics-list\">$ics</ul>";
    return $html;
}

function block_uren(string $afdeling): string {
    $gs = array_values(array_filter(groups(true), fn($g) => $g['afdeling'] === $afdeling));
    if (!$gs) return '<p class="empty">Er zijn nog geen uren ingevuld.</p>';
    $jump = '';
    foreach ($gs as $g) $jump .= '<a href="#' . e($g['id']) . '">' . e($g['naam']) . '</a>';
    $cards = '';
    foreach ($gs as $g) {
        $byDay = [];
        foreach ($g['trainingen'] ?? [] as $t) $byDay[$t['dag']][] = $t;
        $dl = '';
        foreach (array_keys(DAGNAMEN) as $d) {
            if (empty($byDay[$d])) continue;
            usort($byDay[$d], fn($a, $b) => strcmp($a['van'], $b['van']));
            $items = '';
            foreach ($byDay[$d] as $t) {
                $info = trim(implode(' · ', array_filter([$t['soort'] ?? '', $t['plaats'] ?? ''])));
                $items .= e($t['van'] . '–' . $t['tot']) . ($info ? '<small>' . e($info) . '</small>' : '');
            }
            $dl .= '<dt title="' . e(DAGNAMEN[$d]) . '">' . $d . '</dt><dd>' . $items . '</dd>';
        }
        $tn = array_map(fn($t) => $t['naam'], group_trainers($g));
        $tr = $tn ? '<p class="trainers">' . (count($tn) > 1 ? 'Trainers: ' : 'Trainer: ') . e(nl_list($tn)) . '</p>' : '';
        $om = !empty($g['omschrijving']) ? '<p class="trainers">' . e($g['omschrijving']) . '</p>' : '';
        $cls = $afdeling === 'competitie' ? 'slotcard competition' : 'slotcard';
        $cards .= '<section class="' . $cls . '" id="' . e($g['id']) . '"><h3>' . e($g['naam']) . '</h3>' . $tr . $om
            . ($dl ? "<dl>$dl</dl>" : '<p class="muted">Nog geen uren.</p>')
            . '<p class="links"><a href="' . e(url('/kalender/ics/' . $g['id'] . '.ics')) . '">In je agenda zetten</a></p></section>';
    }
    return '<nav class="goal-jump" style="margin-top:0">' . $jump . '</nav><div class="timetable" style="margin-top:20px">' . $cards . '</div>';
}

/** Afdelingen van een trainer ('competitie', 'zwemschool'). */
function trainer_afdelingen(array $t): array {
    return array_values(array_intersect(['competitie', 'zwemschool'], (array)($t['afdelingen'] ?? [])));
}

/** Foto van een trainer, of een rondje met initialen. */
function trainer_photo(array $t, string $cls = 'avatar'): string {
    if (!empty($t['foto'])) {
        $src = str_starts_with($t['foto'], '/uploads/') ? base_path() . ltrim($t['foto'], '/') : $t['foto'];
        return '<img class="' . $cls . '" src="' . e($src) . '" alt="' . e($t['naam']) . '" loading="lazy">';
    }
    $ini = '';
    foreach (array_slice(preg_split('/\s+/', trim($t['naam'])), 0, 2) as $w) $ini .= mb_strtoupper(mb_substr($w, 0, 1));
    return '<span class="' . $cls . ' initials" aria-hidden="true">' . e($ini) . '</span>';
}

/** Competitie: per groep de trainers met foto, telefoon en e-mail. */
function block_trainers_competitie(): string {
    $gs = [];
    foreach (groups(true) as $g) {
        if ($g['afdeling'] !== 'competitie') continue;
        $ts = array_values(array_filter(group_trainers($g), fn($t) => in_array('competitie', trainer_afdelingen($t), true)));
        if ($ts) $gs[] = ['g' => $g, 't' => $ts, 'key' => implode('|', array_column($ts, 'id'))];
    }
    if (!$gs) return '<p class="empty">Er zijn nog geen competitietrainers gekoppeld aan groepen.</p>';
    // Opeenvolgende groepen met dezelfde trainers samen tonen ("Competitie 1 nationaal en Kern")
    $blocks = [];
    foreach ($gs as $x) {
        $last = count($blocks) - 1;
        if ($last >= 0 && $blocks[$last]['key'] === $x['key']) $blocks[$last]['groups'][] = $x['g'];
        else $blocks[] = ['key' => $x['key'], 'groups' => [$x['g']], 'trainers' => $x['t']];
    }
    $html = '';
    foreach ($blocks as $b) {
        $title = nl_list(array_map(fn($g) => $g['naam'], $b['groups']));
        $html .= '<h2 id="' . e($b['groups'][0]['id']) . '">' . e($title) . '</h2><ul class="contacts with-photo">';
        foreach ($b['trainers'] as $t) {
            $lines = [];
            if (!empty($t['toon_contact'])) {
                if (!empty($t['telefoon'])) $lines[] = '<a href="' . e(phone_href($t['telefoon'])) . '">' . e($t['telefoon']) . '</a>';
                if (!empty($t['email'])) $lines[] = '<a href="mailto:' . e($t['email']) . '">' . e($t['email']) . '</a>';
            }
            $html .= '<li>' . trainer_photo($t) . '<div><strong>' . e($t['naam']) . '</strong>' . implode('<br>', $lines) . '</div></li>';
        }
        $html .= '</ul>';
    }
    return $html;
}

/** Zwemschool: overzicht van alle trainers met foto en naam. */
function block_trainers_zwemschool(): string {
    $ts = array_values(array_filter(trainers(), fn($t) => in_array('zwemschool', trainer_afdelingen($t), true)));
    if (!$ts) return '<p class="empty">Er zijn nog geen trainers voor de zwemschool.</p>';
    $html = '<ul class="people">';
    foreach ($ts as $t) {
        $gn = [];
        foreach (groups(true) as $g) if ($g['afdeling'] === 'zwemschool' && in_array($t['id'], $g['trainers'], true)) $gn[] = $g['naam'];
        $html .= '<li>' . trainer_photo($t, 'photo') . '<strong>' . e($t['naam']) . '</strong>' . ($gn ? '<span>' . e(nl_list($gn)) . '</span>' : '') . '</li>';
    }
    return $html . '</ul>';
}

/** Inhoudsblokken voor elke pagina: [[trainers:competitie]] (of [[trainers]]) en [[trainers:zwemschool]]. */
function render_shortcodes(string $body): string {
    return preg_replace_callback('#(?:<p>\s*)?\[\[trainers(?::(zwemschool|competitie))?\]\](?:\s*</p>)?#',
        fn($m) => ($m[1] ?? '') === 'zwemschool' ? block_trainers_zwemschool() : block_trainers_competitie(), $body);
}

function render_body(array $page): string {
    $body = render_shortcodes(relink($page['body'] ?? ''));
    $type = $page['type'] ?? 'page';
    $map = [
        'kalender' => fn() => block_kalender(),
        'lesuren' => fn() => block_uren('zwemschool'),
        'trainingsuren' => fn() => block_uren('competitie'),
        'trainers' => fn() => block_trainers_competitie(),
        'trainers_zwemschool' => fn() => block_trainers_zwemschool(),
    ];
    if (isset($map[$type])) {
        $block = $map[$type]();
        if (str_contains($body, '[[blok]]')) {
            $body = preg_replace('#<p>\s*\[\[blok\]\]\s*</p>#', '[[blok]]', $body);
            $body = str_replace('[[blok]]', $block, $body);
        } else {
            $body .= "\n" . $block;
        }
    }
    return $body;
}

/* ================= Pagina's ================= */

function render_home(): string {
    $s = settings();
    $home = page_by_path('/') ?? ['body' => ''];
    $items = '';
    foreach (array_slice(posts(), 0, 3) as $n) {
        $u = e(url(post_path($n)));
        $items .= '<article class="news-item"><time datetime="' . e($n['date']) . '">' . nl_date($n['date']) . ' · ' . e($n['category']) . '</time>'
            . '<h3><a href="' . $u . '">' . e($n['title']) . '</a></h3><p>' . e($n['intro']) . '</p><p><a href="' . $u . '">Lees meer</a></p></article>';
    }
    if (!$items) $items = '<p class="empty">Er is nog geen nieuws.</p>';
    $tiles = implode("\n", array_map('event_tile', upcoming_events(4))) ?: '<p class="empty">Er staan geen activiteiten gepland.</p>';
    $btns = '';
    foreach ($s['hero_buttons'] as $i => $b) $btns .= '<a class="btn ' . ($i === 0 ? 'btn-float' : 'btn-ghost') . '" href="' . e(url($b['path'])) . '">' . e($b['label']) . '</a>';
    $lanes = '';
    foreach ($s['lanes'] as $l) $lanes .= '<a class="lane" href="' . e(url($l['path'])) . '"><b>' . e($l['title']) . '</b><span>' . e($l['text']) . '</span></a>';
    $intro = relink($s['hero_intro']);
    $bottom = relink($home['body'] ?? '');
    $cal = e(url('/kalender/'));
    $E = 'e';
    $body = <<<HTML
<section class="hero on-deep">
  <div class="wrap hero-inner">
    <h1>{$E($s['hero_title'])} <span>{$E($s['hero_title_accent'])}</span></h1>
    <div>
      <div class="lede prose">$intro</div>
      <div class="hero-actions">$btns</div>
    </div>
  </div>
  <div class="lanes">$lanes</div>
</section>
<div class="wrap section home-cols">
  <section>
    <h2 id="nieuws">Laatste nieuws</h2>
    $items
  </section>
  <section>
    <h2>Eerstvolgende activiteiten</h2>
    <div class="tiles">$tiles</div>
    <p><a href="$cal">Volledige kalender</a></p>
  </section>
</div>
<div class="wrap" style="padding-bottom:clamp(36px,6vw,64px)">
$bottom
</div>
HTML;
    return layout('/', 'Home', $body);
}

function render_post(array $n): string {
    $all = posts();
    $idx = array_search($n['id'], array_column($all, 'id'), true);
    $newer = ($idx !== false && $idx > 0) ? $all[$idx - 1] : null;
    $older = ($idx !== false && isset($all[$idx + 1])) ? $all[$idx + 1] : null;
    $img = !empty($n['image']) ? '<p class="md-figure"><img src="' . e($n['image']) . '" alt=""></p>' : '';
    $pager = '<p class="pager">' . ($newer ? '<a href="' . e(url(post_path($newer))) . '">← ' . e($newer['title']) . '</a>' : '<span></span>')
        . ($older ? '<a href="' . e(url(post_path($older))) . '">' . e($older['title']) . ' →</a>' : '<span></span>') . '</p>';
    $inner = relink($img . ($n['body'] ?? '')) . "\n" . $pager;
    $body = banner($n['title'], nl_date($n['date']) . ' · ' . e($n['category']), ['Nieuws', '/#nieuws'])
        . "\n<div class=\"wrap page-grid solo\"><article><div class=\"prose\">\n$inner\n</div></article></div>";
    return layout(post_path($n), $n['title'], $body, $n['intro'] ?? '');
}

function render_404(): string {
    http_response_code(404);
    $body = banner('Pagina niet gevonden') . '<div class="wrap page-grid solo"><article><div class="prose"><p>Deze pagina bestaat niet (meer). Ga naar de <a href="' . e(url('/')) . '">startpagina</a> of kijk in het menu.</p></div></article></div>';
    return layout('/404/', 'Pagina niet gevonden', $body);
}

function render_route(string $route): string {
    if ($route === '/') return render_home();
    $p = page_by_path($route);
    if ($p && !empty($p['visible']) && ($p['type'] ?? '') !== 'link') {
        return layout($route, $p['title'], content_page($p, render_body($p)), $p['sub'] ?? '');
    }
    if (preg_match('#^/(\d{4})/(\d{2})/(\d{2})/([a-z0-9-]+)/$#', $route, $m)) {
        foreach (posts() as $n) if ($n['date'] === "$m[1]-$m[2]-$m[3]" && $n['slug'] === $m[4]) return render_post($n);
    }
    return render_404();
}

/** Voorbeeldweergave voor het beheer (ook onzichtbare pagina's). */
function render_preview(array $page): string {
    return layout($page['path'], $page['title'], content_page($page, render_body($page)));
}


/* ================= Gegevens bijwerken bij een nieuwe versie ================= */

const SCHEMA_VERSION = 5;

function run_migrations(): void {
    $meta = load('meta', ['schema' => 1]);
    if (($meta['schema'] ?? 1) >= SCHEMA_VERSION || !is_writable(DATA_DIR)) return;
    $lock = fopen(DATA_DIR . '/.migrate.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return;
    try {
        if (($meta['schema'] ?? 1) < 2) migrate_to_2();
        if (($meta['schema'] ?? 1) < 3) migrate_to_3();
        if (($meta['schema'] ?? 1) < 4) migrate_to_4();
        if (($meta['schema'] ?? 1) < 5) migrate_to_5();
        save('meta', ['schema' => SCHEMA_VERSION, 'updated' => date('c')], false);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** v2: trainers als aparte gegevens; groepen verwijzen naar trainer-ids; Trainers & contact wordt dynamisch. */
function migrate_to_2(): void {
    trainers(); // vult data/trainers.json vanuit seed/ als het nog niet bestaat
    $seedGroups = [];
    if (is_file(SEED_DIR . '/groups.json')) foreach (json_decode((string)file_get_contents(SEED_DIR . '/groups.json'), true)['groups'] ?? [] as $sg) $seedGroups[$sg['id']] = $sg['trainers'] ?? [];
    $data = load('groups', ['groups' => []]);
    $changed = false;
    foreach ($data['groups'] as &$g) {
        if (is_array($g['trainers'] ?? null)) continue;
        $g['trainers'] = (isset($seedGroups[$g['id']]) && is_array($seedGroups[$g['id']])) ? $seedGroups[$g['id']] : trainer_ids_from_names((string)($g['trainers'] ?? ''));
        $changed = true;
    }
    unset($g);
    if ($changed) save('groups', $data);

    $pd = load('pages', ['pages' => []]);
    $changed = false;
    foreach ($pd['pages'] as &$p) {
        if ($p['path'] !== '/competitie/trainers/' || ($p['type'] ?? 'page') !== 'page') continue;
        $p['type'] = 'trainers';
        // De oude, met de hand getypte contactlijst vervangen door het blok
        if (str_contains($p['body'] ?? '', 'class="contacts"')) $p['body'] = '<p>[[blok]]</p>';
        $changed = true;
    }
    unset($p);
    if ($changed) save('pages', $pd);
}

/** v3: trainers krijgen afdelingen (competitie/zwemschool) en een foto; nieuwe pagina "Trainers zwemschool". */
function migrate_to_3(): void {
    $gs = groups();
    $td = load('trainers', ['trainers' => []]);
    $changed = false;
    foreach ($td['trainers'] as &$t) {
        if (!array_key_exists('foto', $t)) { $t['foto'] = ''; $changed = true; }
        if (isset($t['afdelingen']) && is_array($t['afdelingen'])) continue;
        $af = [];
        foreach ($gs as $g) if (in_array($t['id'], $g['trainers'], true)) $af[] = $g['afdeling'];
        $t['afdelingen'] = array_values(array_intersect(['competitie', 'zwemschool'], array_unique($af ?: ['competitie'])));
        $changed = true;
    }
    unset($t);
    if ($changed) save('trainers', $td);

    $pd = load('pages', ['pages' => []]);
    $has = false;
    foreach ($pd['pages'] as $p) if (($p['type'] ?? '') === 'trainers_zwemschool') $has = true;
    if (!$has && page_by_path('/zwemschool/')) {
        $path = page_by_path('/zwemschool/trainers/') ? '/zwemschool/onze-trainers/' : '/zwemschool/trainers/';
        $pd['pages'][] = ['id' => new_id(), 'path' => $path, 'title' => 'Trainers zwemschool', 'menu_title' => 'Trainers',
            'sub' => 'Zij leren je kind zwemmen.', 'parent' => '/zwemschool/', 'order' => 35, 'in_menu' => true, 'visible' => true,
            'side' => true, 'type' => 'trainers_zwemschool', 'target' => '', 'body' => '<p>[[blok]]</p>'];
        save('pages', $pd);
    }
}

/** v4: voornaam en naam van trainers apart bewaren. */
function migrate_to_4(): void {
    $td = load('trainers', ['trainers' => []]);
    $changed = false;
    foreach ($td['trainers'] as &$t) {
        if (isset($t['voornaam'])) continue;
        $n = trainer_normalize($t);
        $t['voornaam'] = $n['voornaam'];
        $t['achternaam'] = $n['achternaam'];
        $t['naam'] = $n['naam'];
        $changed = true;
    }
    unset($t);
    if ($changed) save('trainers', $td);
}

/** v5: competitiegroepen in de volgorde Kern – Competitie 1 – Beloften – Competitie 2 – Competitie 3.
 *  Enkel als de volgorde nog de oorspronkelijke is (niet zelf aangepast in het beheer). */
function migrate_to_5(): void {
    $orig = ['competitie-3' => 40, 'competitie-2' => 50, 'beloften' => 60, 'competitie-1-provinciaal' => 70, 'competitie-1-nationaal' => 80, 'kern' => 90];
    $new = ['kern' => 110, 'competitie-1-nationaal' => 120, 'competitie-1-provinciaal' => 130, 'beloften' => 140, 'competitie-2' => 150, 'competitie-3' => 160];
    $d = load('groups', ['groups' => []]);
    $cur = array_column($d['groups'], 'order', 'id');
    foreach ($orig as $id => $o) if (isset($cur[$id]) && (int)$cur[$id] !== $o) return;
    foreach ($d['groups'] as &$g) if (isset($new[$g['id']])) $g['order'] = $new[$g['id']];
    unset($g);
    save('groups', $d);
}
