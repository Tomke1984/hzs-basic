<?php
declare(strict_types=1);

/** Agenda-bestand (.ics) voor "alles" of één groep, live uit de gegevens. */
function render_ics(string $which): ?string {
    $cal = calendar();
    $groups = [];
    foreach (groups(true) as $g) $groups[$g['id']] = $g;
    if ($which !== 'alles' && !isset($groups[$which])) return null;

    $esc = fn(string $t) => str_replace(["\\", ",", ";", "\r\n", "\n"], ["\\\\", "\\,", "\\;", "\\n", "\\n"], $t);
    $stamp = gmdate('Ymd\THis\Z');
    $host = $_SERVER['HTTP_HOST'] ?? 'khzs.be';
    $L = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//HZS//Website//NL', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
        'X-WR-CALNAME:' . $esc('HZS – ' . ($which === 'alles' ? 'Alles' : $groups[$which]['naam'])), 'X-WR-TIMEZONE:Europe/Brussels',
        'BEGIN:VTIMEZONE', 'TZID:Europe/Brussels',
        'BEGIN:DAYLIGHT', 'TZOFFSETFROM:+0100', 'TZOFFSETTO:+0200', 'TZNAME:CEST', 'DTSTART:19700329T020000', 'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU', 'END:DAYLIGHT',
        'BEGIN:STANDARD', 'TZOFFSETFROM:+0200', 'TZOFFSETTO:+0100', 'TZNAME:CET', 'DTSTART:19701025T030000', 'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU', 'END:STANDARD',
        'END:VTIMEZONE'];

    $ymd = fn(string $d) => str_replace('-', '', $d);
    $hm = fn(string $t) => str_replace(':', '', $t) . '00';
    $next = fn(string $d) => date('Ymd', strtotime("$d +1 day"));

    // Activiteiten
    foreach ($cal['events'] as $ev) {
        $gr = $ev['groepen'] ?? [];
        if ($which !== 'alles' && $gr && !in_array($which, $gr, true)) continue;
        $end = $ev['einde'] ?: $ev['start'];
        $L[] = 'BEGIN:VEVENT';
        $L[] = "UID:ev-{$ev['id']}@$host";
        $L[] = "DTSTAMP:$stamp";
        $L[] = 'SUMMARY:' . $esc($ev['titel']);
        if (!empty($ev['hele_dag']) || empty($ev['van'])) {
            $L[] = 'DTSTART;VALUE=DATE:' . $ymd($ev['start']);
            $L[] = 'DTEND;VALUE=DATE:' . $next($end);
        } else {
            $L[] = 'DTSTART;TZID=Europe/Brussels:' . $ymd($ev['start']) . 'T' . $hm($ev['van']);
            $L[] = 'DTEND;TZID=Europe/Brussels:' . $ymd($end) . 'T' . $hm($ev['tot'] ?: $ev['van']);
        }
        if (!empty($ev['locatie'])) $L[] = 'LOCATION:' . $esc($ev['locatie']);
        if (!empty($ev['omschrijving'])) $L[] = 'DESCRIPTION:' . $esc($ev['omschrijving']);
        $L[] = 'END:VEVENT';
    }

    // Sluitingsdagen
    foreach ($cal['sluitingsdagen'] as $s) {
        $L = array_merge($L, ['BEGIN:VEVENT', "UID:sluit-{$s['id']}@$host", "DTSTAMP:$stamp", 'SUMMARY:' . $esc('Zwembad gesloten: ' . $s['reden']),
            'DTSTART;VALUE=DATE:' . $ymd($s['datum']), 'DTEND;VALUE=DATE:' . $next($s['datum']), 'TRANSP:TRANSPARENT', 'END:VEVENT']);
    }

    // Wekelijkse trainingen (enkel per groep)
    $begin = $cal['seizoen']['begin'] ?? ''; $einde = $cal['seizoen']['einde'] ?? '';
    if ($which !== 'alles' && $begin && $einde) {
        $g = $groups[$which];
        $wd = ['ma' => 1, 'di' => 2, 'wo' => 3, 'do' => 4, 'vr' => 5, 'za' => 6, 'zo' => 7];
        $byday = ['ma' => 'MO', 'di' => 'TU', 'wo' => 'WE', 'do' => 'TH', 'vr' => 'FR', 'za' => 'SA', 'zo' => 'SU'];
        $closed = array_column($cal['sluitingsdagen'], 'datum');
        foreach ($cal['vakanties'] as $v) {
            if (!in_array($which, $v['geen_les'] ?? [], true)) continue;
            for ($d = $v['van']; $d <= $v['tot']; $d = date('Y-m-d', strtotime("$d +1 day"))) $closed[] = $d;
        }
        foreach (($g['trainingen'] ?? []) as $i => $t) {
            $d = $begin;
            while ((int)date('N', strtotime($d)) !== $wd[$t['dag']]) $d = date('Y-m-d', strtotime("$d +1 day"));
            $L[] = 'BEGIN:VEVENT';
            $L[] = "UID:tr-{$g['id']}-$i-" . substr(md5(json_encode($t)), 0, 6) . "@$host";
            $L[] = "DTSTAMP:$stamp";
            $L[] = 'SUMMARY:' . $esc(trim(($t['soort'] ?: 'Training') . ' ' . $g['naam']));
            $L[] = 'DTSTART;TZID=Europe/Brussels:' . $ymd($d) . 'T' . $hm($t['van']);
            $L[] = 'DTEND;TZID=Europe/Brussels:' . $ymd($d) . 'T' . $hm($t['tot']);
            $L[] = "RRULE:FREQ=WEEKLY;BYDAY={$byday[$t['dag']]};UNTIL=" . $ymd($einde) . 'T235959Z';
            foreach (array_unique($closed) as $c) {
                if ($c >= $d && $c <= $einde && (int)date('N', strtotime($c)) === $wd[$t['dag']]) $L[] = 'EXDATE;TZID=Europe/Brussels:' . $ymd($c) . 'T' . $hm($t['van']);
            }
            if (!empty($t['plaats'])) $L[] = 'LOCATION:' . $esc($t['plaats']);
            $L[] = 'END:VEVENT';
        }
    }
    $L[] = 'END:VCALENDAR';

    $out = [];
    foreach ($L as $line) { // vouwen op 75 octetten, zonder UTF-8-tekens te breken
        while (strlen($line) > 75) {
            $cut = 75;
            while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) $cut--;
            $out[] = substr($line, 0, $cut);
            $line = ' ' . substr($line, $cut);
        }
        $out[] = $line;
    }
    return implode("\r\n", $out) . "\r\n";
}
