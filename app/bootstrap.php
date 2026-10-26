<?php
declare(strict_types=1);

const APP_DIR = __DIR__;
define('ROOT_DIR', dirname(__DIR__));
const DATA_DIR = ROOT_DIR . '/data';
const UPLOAD_DIR = ROOT_DIR . '/uploads';

const SEED_DIR = ROOT_DIR . '/seed';

if (!is_file(ROOT_DIR . '/config.php')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit("config.php ontbreekt. Kopieer config.example.php naar config.php en vul een eigen setup_code in.\n");
}
$CONFIG = require ROOT_DIR . '/config.php';
date_default_timezone_set($CONFIG['timezone'] ?? 'Europe/Brussels');

function cfg(string $key, $default = null) {
    global $CONFIG;
    return $CONFIG[$key] ?? $default;
}

/* ---------- Escapen ---------- */
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/* ---------- Opslag (JSON-bestanden met vergrendeling en back-up) ---------- */
function store_path(string $name): string {
    if (!preg_match('/^[a-z0-9_-]+$/', $name)) throw new RuntimeException('Ongeldige opslagnaam');
    return DATA_DIR . "/$name.json";
}

function load(string $name, $default = []) {
    $cache = &$GLOBALS['__store_cache'];
    if (isset($cache) && array_key_exists($name, $cache)) return $cache[$name];
    $file = store_path($name);
    if (!is_file($file)) {
        // Eerste keer: start met de inhoud uit seed/ (wordt nooit overschreven)
        $seed = SEED_DIR . "/$name.json";
        if (is_file($seed) && is_writable(DATA_DIR)) @copy($seed, $file);
        if (!is_file($file)) {
            if (is_file($seed)) { $d = json_decode((string)file_get_contents($seed), true); return $cache[$name] = is_array($d) ? $d : $default; }
            return $cache[$name] = $default;
        }
    }
    $fh = fopen($file, 'rb');
    flock($fh, LOCK_SH);
    $raw = stream_get_contents($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    $data = json_decode($raw, true);
    if (!is_array($data)) throw new RuntimeException("Gegevensbestand $name is beschadigd. Zet een back-up terug uit data/backup.");
    return $cache[$name] = $data;
}

function save(string $name, array $data, bool $backup = true): void {
    $file = store_path($name);
    if ($backup && is_file($file)) {
        $bdir = DATA_DIR . '/backup';
        if (!is_dir($bdir)) mkdir($bdir, 0775, true);
        copy($file, "$bdir/$name-" . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(2)), 0, 4) . '.json');
        $old = glob("$bdir/$name-*.json") ?: [];
        sort($old);
        while (count($old) > 40) unlink(array_shift($old));
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) throw new RuntimeException('Kon gegevens niet opslaan');
    $tmp = $file . '.tmp' . bin2hex(random_bytes(3));
    if (file_put_contents($tmp, $json, LOCK_EX) === false) throw new RuntimeException('Kon niet schrijven in de map data/. Controleer de rechten.');
    rename($tmp, $file);
    unset($GLOBALS['__store_cache'][$name]);
}

function new_id(): string { return bin2hex(random_bytes(6)); }

/* ---------- Adressen ---------- */
function base_path(): string {
    static $b = null;
    if ($b === null) {
        $b = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/') . '/';
    }
    return $b;
}

/** Interne route (/zwemschool/lesuren/) -> echte link. */
function url(string $path = '/'): string {
    $frag = '';
    if (($p = strpos($path, '#')) !== false) { $frag = substr($path, $p); $path = substr($path, 0, $p); }
    $query = '';
    if (($p = strpos($path, '?')) !== false) { $query = substr($path, $p + 1); $path = substr($path, 0, $p); }
    if ($path === '') $path = '/';
    if (cfg('pretty_urls', true)) {
        $u = base_path() . ltrim($path, '/');
        return $u . ($query !== '' ? "?$query" : '') . $frag;
    }
    $u = base_path() . ($path === '/' ? '' : 'index.php?p=' . rawurlencode($path));
    if ($query !== '') $u .= ($path === '/' ? '?' : '&') . $query;
    return $u . $frag;
}

function asset(string $file): string {
    $v = @filemtime(ROOT_DIR . '/assets/' . $file) ?: 1;
    return base_path() . 'assets/' . $file . '?v=' . $v;
}

/** Root-links in opgeslagen HTML omzetten naar echte links. */
function relink(string $html): string {
    $legacy = rtrim((string)cfg('legacy_media_host', ''), '/');
    return preg_replace_callback('/\b(href|src)=(["\'])(\/[^"\']*)\2/i', function ($m) use ($legacy) {
        [$all, $attr, $q, $u] = $m;
        if (str_starts_with($u, '//')) return $all;
        if (str_starts_with($u, '/wp-content/') || str_starts_with($u, '/_w/')) return $legacy ? "$attr=$q$legacy$u$q" : $all;
        if (str_starts_with($u, '/uploads/')) return "$attr=$q" . base_path() . ltrim($u, '/') . $q;
        return "$attr=$q" . e(url(html_entity_decode($u))) . $q;
    }, $html);
}

function normalize_path(string $p): string {
    $p = '/' . trim(preg_replace('#/+#', '/', $p), '/') . '/';
    return $p === '//' ? '/' : $p;
}

function current_route(): string {
    if (isset($_GET['p'])) { $p = (string)$_GET['p']; return preg_match('#\.ics$#', $p) ? '/' . ltrim($p, '/') : normalize_path($p); }
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $uri = rawurldecode($uri);
    $base = base_path();
    if (str_starts_with($uri, $base)) $uri = substr($uri, strlen($base) - 1);
    if (str_ends_with($uri, '/index.php')) $uri = substr($uri, 0, -9);
    if (preg_match('#\.ics$#', $uri)) return $uri;
    return normalize_path($uri);
}

function redirect(string $route, int $code = 303): never {
    header('Location: ' . url($route), true, $code);
    exit;
}

function slugify(string $s): string {
    $s = strtolower(trim($s));
    $s = strtr($s, ['à'=>'a','á'=>'a','â'=>'a','ä'=>'a','è'=>'e','é'=>'e','ê'=>'e','ë'=>'e','ì'=>'i','í'=>'i','î'=>'i','ï'=>'i','ò'=>'o','ó'=>'o','ô'=>'o','ö'=>'o','ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n','&'=>'en']);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-') ?: 'pagina';
}

/* ---------- Datums ---------- */
const MAANDEN = ['januari','februari','maart','april','mei','juni','juli','augustus','september','oktober','november','december'];
const MND = ['jan','feb','mrt','apr','mei','jun','jul','aug','sep','okt','nov','dec'];
const DAGNAMEN = ['ma' => 'maandag','di' => 'dinsdag','wo' => 'woensdag','do' => 'donderdag','vr' => 'vrijdag','za' => 'zaterdag','zo' => 'zondag'];

function nl_date(string $ymd, bool $year = true): string {
    $t = strtotime($ymd);
    if (!$t) return $ymd;
    return (int)date('j', $t) . ' ' . MAANDEN[(int)date('n', $t) - 1] . ($year ? ' ' . date('Y', $t) : '');
}

function today(): string { return date('Y-m-d'); }

/* ---------- HTML opschonen (tegen scripts in opgeslagen inhoud) ---------- */
function clean_html(string $html): string {
    $html = trim($html);
    if ($html === '') return '';
    $allowed = ['p','br','strong','b','em','i','u','s','a','ul','ol','li','h2','h3','h4','blockquote','img','figure','figcaption',
        'table','thead','tbody','tfoot','tr','th','td','caption','div','span','section','nav','dl','dt','dd','hr','small','sub','sup','code','pre','time','details','summary'];
    $attrs = ['href','src','alt','title','class','id','colspan','rowspan','target','rel','style','width','height','datetime','open'];
    $doc = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="__root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $root = $doc->getElementById('__root');
    if (!$root) return '';
    $walk = function (DOMNode $node) use (&$walk, $allowed, $attrs) {
        for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
            $c = $node->childNodes->item($i);
            if ($c instanceof DOMComment) { $node->removeChild($c); continue; }
            if (!($c instanceof DOMElement)) continue;
            $tag = strtolower($c->nodeName);
            if (in_array($tag, ['script','style','iframe','object','embed','form','input','button','textarea','select','link','meta','base','svg','math'], true)) {
                $node->removeChild($c); continue;
            }
            $walk($c);
            if (!in_array($tag, $allowed, true)) {
                while ($c->firstChild) $node->insertBefore($c->firstChild, $c);
                $node->removeChild($c);
                continue;
            }
            for ($a = $c->attributes->length - 1; $a >= 0; $a--) {
                $at = $c->attributes->item($a);
                $n = strtolower($at->nodeName);
                $v = trim($at->nodeValue);
                $keep = in_array($n, $attrs, true);
                if ($keep && in_array($n, ['href','src'], true)) {
                    $keep = (bool)preg_match('#^(https?:|mailto:|tel:|/|\#)#i', $v);
                }
                if ($keep && $n === 'style') {
                    $keep = !preg_match('/expression|url\s*\(|javascript|@import|behavior/i', $v);
                }
                if (!$keep) $c->removeAttribute($at->nodeName);
            }
            if ($tag === 'a' && $c->getAttribute('target') === '_blank') $c->setAttribute('rel', 'noopener');
        }
    };
    $walk($root);
    $out = '';
    foreach ($root->childNodes as $c) $out .= $doc->saveHTML($c);
    return trim($out);
}
