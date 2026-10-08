<?php

/**
 * Prüfskript für Normalisierung, Mindestversionen, Patch-Rückstand und Mailformat.
 * Ohne REDAXO-Boot und ohne Datenbank, keine Abhängigkeiten:
 *
 *   php redaxo/src/addons/project_manager_report/tests/run.php
 *
 * Exit-Code 0 = alles in Ordnung, 1 = mindestens eine Prüfung fehlgeschlagen.
 */

$lib = dirname(__DIR__) . '/lib/';
require_once $lib . 'report_version.php';
require_once $lib . 'report_collector.php';
require_once $lib . 'report_builder.php';

date_default_timezone_set('Europe/Berlin');

$failed = 0;
$total = 0;
$check = static function (string $name, bool $condition) use (&$failed, &$total): void {
    ++$total;
    if (!$condition) {
        ++$failed;
        echo 'FAIL ', $name, "\n";
    }
};

$V = 'rex_project_manager_report_version';
$C = 'rex_project_manager_report_collector';
$B = 'rex_project_manager_report_builder';

// ---------------------------------------------------------------- Normalisierung
$php = $V::php('8.4.24-nmm1');
$check('php 8.4.24-nmm1', ['full' => '8.4.24', 'line' => '8.4', 'patch' => 24] === $php);
$check('php 8.3.2+deb', '8.3.2' === $V::php('8.3.2+deb12u1')['full']);
$check('php ohne Patch', ['full' => '8.4.0', 'line' => '8.4', 'patch' => 0] === $V::php('8.4'));
$check('php Unsinn', null === $V::php('abc') && null === $V::php(null));

$db = $V::database('10.11.14-MariaDB-0ubuntu0.24.04.1-log');
$check('db MariaDB mit Suffix', ['engine' => 'MariaDB', 'version' => '10.11.14', 'line' => '10.11'] === $db);
$db = $V::database('5.5.5-10.3.39-MariaDB');
$check('db 5.5.5-Praefix mit Version', ['engine' => 'MariaDB', 'version' => '10.3.39', 'line' => '10.3'] === $db);
$db = $V::database('5.5.5-MariaDB');
$check('db 5.5.5-MariaDB unbekannt', ['engine' => 'MariaDB', 'version' => null, 'line' => null] === $db);
$db = $V::database('5.7.42-MySQL');
$check('db 5.7.42-MySQL', ['engine' => 'MySQL', 'version' => '5.7.42', 'line' => '5.7'] === $db);
$db = $V::database('8.0.36');
$check('db 8.0.36 ist MySQL', ['engine' => 'MySQL', 'version' => '8.0.36', 'line' => '8.0'] === $db);
$check('db leer unbekannt', null === $V::database('')['version'] && null === $V::database(null)['version']);

$check('Muster gueltig', $V::isValid('8.3') && $V::isValid('5.21.2') && !$V::isValid('8') && !$V::isValid('8.3.2.1') && !$V::isValid('8.x') && !$V::isValid(''));
$check('setting leer/ungueltig = null', null === $V::setting('') && null === $V::setting('abc') && '8.3' === $V::setting(' 8.3 '));
$check('lineBelow nur major.minor', $V::lineBelow('8.2.34', '8.3') && !$V::lineBelow('8.3.0', '8.3.9') && !$V::lineBelow('8.4.1', '8.3') && !$V::lineBelow('10.11.4', '10.11') && $V::lineBelow('10.4.34', '10.11'));
$check('below voll', $V::below('5.20.2', '5.21.2') && !$V::below('5.21.2', '5.21.2') && !$V::below('5.21.4', '5.21.2'));

// ---------------------------------------------------------------- Einstellungen: Hilfsfunktionen
$C0 = 'rex_project_manager_report_collector';
$check('parseList: Komma, Semikolon, Zeilenumbruch', ['a@x.de', 'b@y.de', 'c@z.de'] === $C0::parseList("a@x.de, b@y.de;\n c@z.de ,,"));
$check('parseLines: eine Angabe pro Zeile', ['Undefined variable $params', 'a/b.php:12'] === $C0::parseLines("  Undefined variable \$params \r\n\n  a/b.php:12\n"));
$check('invalidAddresses', ['kaputt', '<b>x</b>@y'] === $C0::invalidAddresses(['a@example.org', 'kaputt', '<b>x</b>@y']) && [] === $C0::invalidAddresses([]));
$check('Startwerte: Syslog-Typen stimmen mit der Konstante ueberein', implode(',', $C0::DEFAULT_SYSLOG_IGNORE) === $C0::SETTING_DEFAULTS['syslog_ignore'] && (string) $C0::DEFAULT_STALE_HOURS === $C0::SETTING_DEFAULTS['stale_hours']);
$check('Startwerte: alle Einstellungen vorhanden', [] === array_diff(['min_rex', 'min_php', 'min_mariadb', 'min_mysql', 'max_patch_lag', 'ignore_prerelease', 'recipients', 'recipients_report', 'stale_hours', 'syslog_ignore', 'syslog_ignore_messages'], array_keys($C0::SETTING_DEFAULTS)));

// ---------------------------------------------------------------- Hilfen
$now = new DateTimeImmutable('2026-10-08 06:30:00');
$ignore = $C::DEFAULT_SYSLOG_IGNORE;
$global = ['min_rex' => '5.21.2', 'min_php' => '8.3', 'min_mariadb' => '10.11', 'min_mysql' => '8.4', 'max_patch_lag' => 3, 'ignore_prerelease' => true, 'skip_addon' => [], 'skip_addon_version' => [], 'overrides' => []];

$raw = static fn (string $php, string $rex, string $db, array $sys = []): string => (string) json_encode([
    'rex_version' => $rex, 'php_version' => $php, 'mysql_version' => $db, 'rex_addons' => [], 'syslog' => $sys,
]);
/** Projekt aus Versionsangaben; $status '1' = Abruf ok */
$project = static function (string $domain, string $php, string $rex, string $db, string $status = '1', string $cms = '5', array $sys = []) use ($C, $raw, $now, $ignore, &$global): array {
    $row = ['domain' => $domain, 'cms' => $cms, 'status' => $status, 'createdate' => '2026-10-08 00:00:12', 'raw' => '1' === $status ? $raw($php, $rex, $db, $sys) : null];

    return $C::analyse($row, $now, 30, $ignore, [], $global);
};
$keys = static fn (array $p): string => implode(',', $B::belowMin($p));

// ---------------------------------------------------------------- Mindestversionen
$p = $project('a.de', '8.2.34-x', '5.20.2', '5.7.42-MySQL');
$check('below_min db,php,rex', 'db,php,rex' === $keys($p));
$check('Violation-Texte', ['PHP 8.2.34', 'REDAXO 5.20.2', 'MySQL 5.7.42'] === array_column($B::violations($p), 'label') && ['8.3', '5.21.2', '8.4'] === array_column($B::violations($p), 'min'));
$p = $project('a.de', '8.4.24', '5.21.4', '10.4.34-MariaDB-x');
$check('MariaDB 10.4 gegen min_mariadb', 'db' === $keys($p) && 'MariaDB 10.4.34' === $B::violations($p)[0]['label'] && '10.11' === $B::violations($p)[0]['min']);
$check('MariaDB 10.11.14 ok', '' === $keys($project('a.de', '8.4.24', '5.21.4', '10.11.14-MariaDB-0ubuntu0.24.04.1-log')));
$check('MySQL 8.0.36 < 8.4', 'db' === $keys($project('a.de', '8.4.24', '5.21.4', '8.0.36')));
$check('MySQL nutzt nicht min_mariadb', '' === $keys($project('a.de', '8.4.24', '5.21.4', '8.4.1')));
$check('Engine entscheidet das Minimum (MariaDB 10.6 vs min_mysql 8.4 irrelevant)', 'db' === $keys($project('a.de', '8.4.24', '5.21.4', '10.6.23-MariaDB')));
$p = $project('a.de', '8.4.24', '5.21.4', '5.5.5-MariaDB');
$check('DB unbekannt: kein Befund', '' === $keys($p) && null === $p['db_version'] && 'unbekannt' === $p['db'] && 'DB unbekannt' === $p['db_label']);
$check('Gleichstand ok', '' === $keys($project('a.de', '8.3.0', '5.21.2', '10.11.0-MariaDB')));
$check('REDAXO 4 ueberspringt min_rex', '' === $keys($project('a.de', '8.4.24', '4.7.2', '10.11.14-MariaDB', '1', '4')));
$check('REDAXO 4 prueft PHP weiter', 'php' === $keys($project('a.de', '7.4.33', '4.7.2', '10.11.14-MariaDB', '1', '4')));
$check('FEHLER ohne Versionsbefund', [] === $project('a.de', '7.4.33', '5.1.0', '5.7.1', '0')['violations']);

// leere Einstellungen deaktivieren die Regeln einzeln
$saved = $global;
$global['min_php'] = null;
$check('min_php leer: kein php-Befund', 'db,rex' === $keys($project('a.de', '7.4.33', '5.20.2', '5.7.42-MySQL')));
$global['min_rex'] = null;
$global['min_mysql'] = null;
$global['min_mariadb'] = null;
$check('alle Minima leer: kein Befund', '' === $keys($project('a.de', '7.4.33', '5.20.2', '5.7.42-MySQL')));
$global = $saved;

// Override schlaegt global; 0.0 schaltet ab; leer = global
$global['overrides'] = ['o.de' => ['min_rex' => '5.20.0', 'min_php' => '0.0', 'min_mariadb' => null, 'min_mysql' => '5.7']];
$check('Override: rex 5.20.2 >= 5.20.0, php 0.0 aus, mysql 5.7 ok', '' === $keys($project('o.de', '7.4.33', '5.20.2', '5.7.42-MySQL')));
$check('Override gilt nur fuer die Domain', 'db,php,rex' === $keys($project('x.de', '8.2.34', '5.20.2', '5.7.42-MySQL')));
$check('Override leer = global (mariadb)', 'db' === $keys($project('o.de', '8.4.24', '5.21.4', '10.4.34-MariaDB')));
$global = $saved;

// ---------------------------------------------------------------- Patch-Rueckstand
$fleet = [
    $project('f1.de', '8.4.26', '5.21.4', '10.11.14-MariaDB'),
    $project('f2.de', '8.4.23', '5.21.4', '10.11.14-MariaDB'),   // lag 3: ok
    $project('f3.de', '8.4.22', '5.21.4', '10.11.14-MariaDB'),   // lag 4: Rueckstand
    $project('f4.de', '8.3.31', '5.21.4', '10.11.14-MariaDB'),   // Linie mit einem Projekt
    $project('f5.de', '8.4.1', '5.21.4', '10.11.14-MariaDB', '0'), // FEHLER: zaehlt nicht
];
$fleet = $C::applyPatchLag($fleet, 3);
$behind = static fn (array $f): array => array_values(array_map(static fn (array $p) => $p['domain'], array_filter($f, static fn (array $p) => null !== $p['behind'])));
$check('Rueckstand: nur f3', ['f3.de'] === $behind($fleet));
$check('Rueckstand-Daten', ['line' => '8.4', 'actual' => '8.4.22', 'max' => '8.4.26', 'lag' => 4] === $fleet[2]['behind']);
$check('lag == max_patch_lag ist kein Rueckstand', null === $fleet[1]['behind']);
$check('Linie mit einem Projekt: kein Rueckstand', null === $fleet[3]['behind']);
$check('FEHLER-Projekt: kein Rueckstand', null === $fleet[4]['behind']);
$only = $C::applyPatchLag([$project('f4.de', '8.3.31', '5.21.4', '10.11.14-MariaDB')], 3);
$check('einzelnes Projekt', null === $only[0]['behind']);
$check('FEHLER-Projekt zaehlt nicht fuers Flottenmaximum', [] === $behind($C::applyPatchLag([$project('a.de', '8.4.20', '5.21.4', '10.11.14-MariaDB'), $project('b.de', '8.4.19', '5.21.4', '10.11.14-MariaDB'), $project('c.de', '8.4.90', '5.21.4', '10.11.14-MariaDB', '0')], 3)));
$clean = static fn (array $f): array => array_map(static fn (array $p) => array_merge($p, ['behind' => null]), $f);
$check('max_patch_lag null: Regel aus', [] === $behind($C::applyPatchLag($clean($fleet), null)));
$check('max_patch_lag 0: jede Abweichung', ['f2.de', 'f3.de'] === $behind($C::applyPatchLag($clean($fleet), 0)));

// ---------------------------------------------------------------- Akzeptanz (Vorgabe des Nutzers)
$global = $saved;
$maria = '10.11.14-MariaDB-0ubuntu0.24.04.1-log';
$my57 = '5.7.44-0ubuntu0.20.04.1';
$rows = [
    // domain, php, rex, db
    ['p01.example', '7.4.33', '5.20.1', '5.5.5-MariaDB'],
    ['p02.example', '8.3.31', '5.20.5', '10.4.34-MariaDB-log'],
    ['p03.example', '8.2.34', '5.19.0', $maria],
    ['p04.example', '8.3.35', '5.21.0', $maria],
    ['p05.example', '8.3.35', '5.21.4', $maria],
    ['p06.example', '8.4.26', '5.21.4', '10.6.23-MariaDB'],
    ['p07.example', '8.4.26', '5.21.4', '10.6.23-MariaDB'],
    ['p08.example', '8.4.26', '5.21.4', $my57],
    ['p09.example', '8.4.12', '5.21.4', $my57],
    ['p10.example', '8.4.26', '5.21.4', $my57],
    ['p12.example', '8.4.26', '5.21.4', $my57],
    ['p13.example', '8.4.26', '5.21.4', $my57],
    ['p14.example', '8.4.26', '5.21.4', $my57],
    ['p11.example', '8.4.26', '5.21.4', $my57],
    ['p15.example', '8.4.12', '5.21.4', $my57],
    ['p16.example', '8.4.26', '5.21.4', $my57],
    ['p17.example', '8.4.26', '5.21.4', $my57],
    ['p18.example', '8.4.26', '5.21.4', '8.0.36'],
    ['gesund-1.de', '8.4.26', '5.21.4', $maria],
    ['gesund-2.de', '8.4.26', '5.21.4', '8.4.3'],
];
$fleet = $C::applyPatchLag(array_map(static fn (array $r) => $project($r[0], $r[1], $r[2], $r[3]), $rows), 3);
$with = static fn (string $key): array => array_values(array_map(static fn (array $p) => $p['domain'], array_filter($fleet, static fn (array $p) => in_array($key, $B::belowMin($p), true))));
$sorted = static function (array $a): array { sort($a); return $a; };
$dbIn = static fn (string $engine): array => array_values(array_map(static fn (array $p) => $p['domain'], array_filter($fleet, static fn (array $p) => in_array('db', $B::belowMin($p), true) && $engine === $p['db_engine'])));

$check('Akzeptanz below_min rex', $sorted(['p01.example', 'p02.example', 'p03.example', 'p04.example']) === $sorted($with('rex')));
$check('Akzeptanz below_min php', $sorted(['p01.example', 'p03.example']) === $sorted($with('php')));
$check('Akzeptanz below_min db MariaDB', $sorted(['p02.example', 'p06.example', 'p07.example']) === $sorted($dbIn('MariaDB')));
$check('Akzeptanz below_min db MySQL', $sorted(['p08.example', 'p09.example', 'p10.example', 'p12.example', 'p13.example', 'p14.example', 'p11.example', 'p15.example', 'p16.example', 'p17.example', 'p18.example']) === $sorted($dbIn('MySQL')));
$check('Akzeptanz behind php', $sorted(['p09.example', 'p15.example', 'p02.example']) === $sorted($behind($fleet)));
$byDomain = array_column($fleet, null, 'domain');
$check('Akzeptanz behind-Werte', '8.4.12' === $byDomain['p09.example']['behind']['actual'] && '8.4.26' === $byDomain['p09.example']['behind']['max'] && 14 === $byDomain['p09.example']['behind']['lag'] && '8.3.31' === $byDomain['p02.example']['behind']['actual'] && '8.3.35' === $byDomain['p02.example']['behind']['max']);
$ff = $byDomain['p01.example'];
$check('Akzeptanz p01: DB unbekannt, kein DB-Befund', null === $ff['db_version'] && !in_array('db', $B::belowMin($ff), true));
$check('Akzeptanz 8.3.35-Projekte nicht wegen PHP', !in_array('php', $B::belowMin($byDomain['p04.example']), true) && null === $byDomain['p04.example']['behind'] && [] === $byDomain['p05.example']['violations'] && null === $byDomain['p05.example']['behind']);
$check('Akzeptanz gesunde Projekte ohne Befund', !$B::hasFinding($byDomain['gesund-1.de']) && !$B::hasFinding($byDomain['gesund-2.de']));

// ---------------------------------------------------------------- Mailformat
$machine = $B::buildMachine($fleet, $now);
$body = $machine['body'];
$lines = explode("\n", $body);
$check('Kopf: Lauf-Zeile mit Aeltester Abruf', "Lauf: 2026-10-08 06:30 | Abgefragt: 20 | OK: 20 | Fehler: 0 | Mit Befund: {$machine['findings']} | Ältester Abruf: 2026-10-08 00:00" === $lines[0]);
$check('Kopf: SYSLOG_IGNORIERT', 'SYSLOG_IGNORIERT: 0' === $lines[1]);
$check('Inventar-Kopfzeile', str_contains($body, "\n\n# domain | rex | php | db | addon_updates | syslog | below_min | behind | status | last_ok\n"));
$inv = array_filter($lines, static fn (string $l) => '' !== $l && !str_starts_with($l, '#') && substr_count($l, ' | ') >= 9);
$check('Inventarzeilen haben 10 Felder', 20 === count($inv) && [] === array_filter($inv, static fn (string $l) => 10 !== count(explode(' | ', $l))));
$check('Inventar: below_min und behind', in_array('p02.example | 5.20.5 | 8.3.31 | 10.4.34-MariaDB | addon_updates= | syslog=0 | below_min=db,rex | behind=php:8.3.31<8.3.35 | status=ok | last_ok=2026-10-08 00:00', $lines, true)
    && in_array('p09.example | 5.21.4 | 8.4.12 | 5.7.44-MySQL | addon_updates= | syslog=0 | below_min=db | behind=php:8.4.12<8.4.26 | status=ok | last_ok=2026-10-08 00:00', $lines, true)
    && in_array('p01.example | 5.20.1 | 7.4.33 | unbekannt | addon_updates= | syslog=0 | below_min=php,rex | behind= | status=ok | last_ok=2026-10-08 00:00', $lines, true)
    && in_array('gesund-1.de | 5.21.4 | 8.4.26 | 10.11.14-MariaDB | addon_updates= | syslog=0 | below_min= | behind= | status=ok | last_ok=2026-10-08 00:00', $lines, true));
// keine Detail-Bloecke: nur Kopf (2 Zeilen), Leerzeile, Inventar (Ueberschrift + Projekte); spart Tokens
$check('Data-Mail ohne Detail-Texte', !str_contains($body, '==') && !str_contains($body, 'Syslog 24h') && !str_contains($body, 'Mindestversion:') && !str_contains($body, 'Rückstand:') && !str_contains($body, 'FEHLER:') && !str_contains($body, 'VERALTET:') && !str_contains($body, 'DB-Version unbekannt'));
$check('Data-Mail: Kopf (2) + Leerzeile + Ueberschrift + 20 Projekte = 24 Zeilen', 24 === substr_count($body, "\n"));

// SYSLOG_IGNORIERT summiert unterdrueckte Eintraege
$sys = [
    ['timestamp' => '08.10.2026 00:00:01', 'syslog_type' => 'Deprecated', 'syslog_message' => 'x', 'syslog_file' => 'a.php', 'syslog_line' => '1'],
    ['timestamp' => '08.10.2026 00:00:02', 'syslog_type' => 'Notice', 'syslog_message' => 'y', 'syslog_file' => '', 'syslog_line' => ''],
    ['timestamp' => '08.10.2026 00:00:03', 'syslog_type' => 'Warning', 'syslog_message' => 'Undefined variable $params', 'syslog_file' => 'b.php', 'syslog_line' => '2'],
    ['timestamp' => '01.10.2026 00:00:03', 'syslog_type' => 'Deprecated', 'syslog_message' => 'zu alt', 'syslog_file' => '', 'syslog_line' => ''],
];
$s1 = $C::analyse(['domain' => 's.de', 'cms' => '5', 'status' => '1', 'createdate' => '2026-10-08 00:00:12', 'raw' => $raw('8.4.26', '5.21.4', $maria, $sys)], $now, 30, $ignore, ['undefined variable $params'], $global);
$check('ignored zaehlt Typ- und Meldungsfilter, nicht Altes', ['Deprecated' => 1, 'Notice' => 1, 'Warning' => 1] === $s1['ignored'] && [] === $s1['syslog']);
$m = $B::buildMachine([$s1], $now);
$check('SYSLOG_IGNORIERT: 3', str_contains($m['body'], "\nSYSLOG_IGNORIERT: 3\n"));
$noLog = $C::analyse(['domain' => 'n.de', 'cms' => '5', 'status' => '0', 'createdate' => null, 'raw' => null], $now, 30, $ignore, [], $global);
$stale = $C::analyse(['domain' => 'st.de', 'cms' => '5', 'status' => '1', 'createdate' => '2026-10-06 00:00:12', 'raw' => $raw('8.4.26', '5.21.4', $maria)], $now, 30, $ignore, [], $global);
$err = $C::analyse(['domain' => 'er.de', 'cms' => '5', 'status' => '0', 'createdate' => '2026-10-07 00:00:12', 'raw' => $raw('8.4.26', '5.21.4', $maria)], $now, 30, $ignore, [], $global);
$never = $C::analyse(['domain' => 'nv.de', 'cms' => '5', 'status' => '-1', 'createdate' => null, 'raw' => null], $now, 30, $ignore, [], $global);
$mm = $B::buildMachine([$stale, $err, $never], $now)['body'];
$check('status stale mit last_ok', str_contains($mm, "st.de | 5.21.4 | 8.4.26 | 10.11.14-MariaDB | addon_updates= | syslog=0 | below_min= | behind= | status=stale | last_ok=2026-10-06 00:00\n"));
$check('status error mit last_ok', str_contains($mm, "er.de | - | - | - | addon_updates=- | syslog=- | below_min=- | behind=- | status=error | last_ok=2026-10-07 00:00\n"));
$check('status error ohne Log: last_ok -', str_contains($mm, "nv.de | - | - | - | addon_updates=- | syslog=- | below_min=- | behind=- | status=error | last_ok=-\n"));
$check('Befund-Zaehler zaehlt stale und error weiter', 3 === $B::buildMachine([$stale, $err, $never], $now)['findings']);
$zero = $C::analyse(['domain' => 'z.de', 'cms' => '5', 'status' => '1', 'createdate' => '0000-00-00 00:00:00', 'raw' => $raw('8.4.26', '5.21.4', $maria)], $now, 30, $ignore, [], $global);
$check('Nullzeit ist kein Abrufzeitpunkt (error, last_ok -)', 'error' === $zero['state'] && null === $zero['last_success']);
$zeroErr = $C::analyse(['domain' => 'z2.de', 'cms' => '5', 'status' => '0', 'createdate' => '0000-00-00 00:00:00', 'raw' => $raw('8.4.26', '5.21.4', $maria)], $now, 30, $ignore, [], $global);
$zm = $B::buildMachine([$zeroErr, $project('ok.de', '8.4.26', '5.21.4', $maria)], $now)['body'];
$check('Aeltester Abruf ignoriert Nullzeit', str_contains($zm, '| Ältester Abruf: 2026-10-08 00:00') && str_contains($zm, 'z2.de | - | - | - | addon_updates=- | syslog=- | below_min=- | behind=- | status=error | last_ok=-'));
$check('Aeltester Abruf - bei keinem Log', str_contains($B::buildMachine([$noLog], $now)['body'], '| Ältester Abruf: -'));

// Bericht (Text; HTML braucht REDAXO)
$report = $B::buildReport($fleet, $now, false);
$check('Bericht: Zusammenfassung nennt Mindestversion und Rueckstand getrennt', str_contains($report['text'], 'unter Mindestversion: 17, im Rückstand: 3') && 17 === $report['findings']);
$check('Bericht: Marker', str_contains($report['text'], '7.4.33!') && str_contains($report['text'], '8.3.31~') && str_contains($report['text'], 'MySQL 5.7.44!') && str_contains($report['text'], '! = unter Mindestversion, ~ = Patch-Rückstand'));
$check('Bericht: DB unbekannt', str_contains($report['text'], 'DB unbekannt'));
$check('Bericht: Rueckstandszeile', str_contains($report['text'], 'Rückstand: PHP 8.4.12 < 8.4.26 (14 Patches), Flottenmaximum der PHP-Linie 8.4'));
$check('Bericht: Betreff', 1 === preg_match('/^\[Projekt Manager - Report\] \d{4}-\d{2}-\d{2} \| \d+ mit Befund \| 0 Fehler \| 0 Addon-Updates$/', $report['subject']));
$check('Data-Betreff', 1 === preg_match('/^\[Projekt Manager - Data\] \d{4}-\d{2}-\d{2} \| 20 Projekte \| \d+ mit Befund \| 0 Fehler$/', $machine['subject']));

echo $failed, ' von ', $total, " Pruefungen fehlgeschlagen\n";
exit($failed > 0 ? 1 : 0);
