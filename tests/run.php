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
require_once $lib . 'report_delta.php';
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
$check('Data-Mail ohne Detail-Texte', !str_contains($body, '==') && !str_contains($body, 'Syslog 24h') && !str_contains($body, 'Mindestversion:') && !str_contains($body, 'Rückstand:') && !str_contains($body, 'VERALTET:') && !str_contains($body, 'DB-Version unbekannt'));
$check('Data-Mail: Kopf (2), Aggregatzeile, Inventar (Ueberschrift + 20 Projekte), zwei Leerzeilen = 26 Zeilen', 26 === substr_count($body, "\n") && 'ADDON-UPDATES AGGREGIERT: keine' === $lines[3]);

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


// ---------------------------------------------------------------- Delta (Baseline -> heute)
$D = 'rex_project_manager_report_delta';
$then = new DateTimeImmutable('2026-10-07 06:30:00');
$day2 = new DateTimeImmutable('2026-10-08 06:30:00');
$cfg = ['min_rex' => '5.21.2', 'min_php' => '8.3', 'min_mariadb' => '10.11', 'min_mysql' => '8.4', 'max_patch_lag' => 3, 'ignore_prerelease' => true, 'skip_addon' => [], 'skip_addon_version' => [], 'overrides' => []];
$addonRows = static function (array $addons): array {
    $rows = [];
    foreach ($addons as $name => $v) {
        $rows[$name] = ['name' => $name, 'install' => true, 'status' => true, 'version_current' => $v[0], 'version_latest' => $v[1] ?? $v[0]];
    }

    return $rows;
};
/** Projekt aus Rohdaten; $o: status, cms, addons [name => [aktuell, neueste?]], sys, createdate */
$mk = static function (string $domain, string $php, string $rex, string $db, array $o = []) use ($C, $now, $ignore, $cfg, $addonRows): array {
    $status = $o['status'] ?? '1';
    $raw = '1' === $status ? json_encode(['rex_version' => $rex, 'php_version' => $php, 'mysql_version' => $db, 'rex_addons' => $addonRows($o['addons'] ?? []), 'syslog' => $o['sys'] ?? []]) : null;

    return $C::analyse(['domain' => $domain, 'cms' => $o['cms'] ?? '5', 'status' => $status, 'createdate' => array_key_exists('createdate', $o) ? $o['createdate'] : '2026-10-08 00:00:12', 'raw' => $raw], $now, 30, $ignore, [], $cfg);
};
/** gespeicherte Zeilen wie sie loadReported() liefert (Payload als JSON gespeichert und wieder gelesen) */
$stored = static function (array $projects, DateTimeImmutable $at) use ($D): array {
    return array_map(static function (array $row): array {
        return ['domain' => $row['domain'], 'status' => $row['status'], 'payload' => '' === $row['payload'] ? null : json_decode($row['payload'], true), 'reported_at' => $row['reported_at']];
    }, $D::snapshot('data', $projects, $at));
};
$ev = static function (array $delta, string $type): array {
    foreach ($delta['events'] as $event) {
        if ($event['type'] === $type) {
            return array_column($event['items'], 'text');
        }
    }

    return [];
};
$ok = '10.11.14-MariaDB';
$base = static fn (array $ps) => $stored($ps, $then);
$ctx = ['settings' => $cfg];

// 1 Basislauf: keine Vergleichsdaten
$d = $D::compute([$mk('a.de', '8.4.26', '5.21.4', $ok)], [], $ctx);
$check('Delta 1: Basislauf', 'base' === $d['mode'] && ['ÄNDERUNGEN: Basislauf, keine Vergleichsdaten'] === $D::lines($d));
// 2 gleicher Stand: kein Delta (zweiter Lauf am selben Tag)
$ps = [$mk('a.de', '8.4.26', '5.21.4', $ok), $mk('b.de', '8.4.26', '5.21.4', $ok, ['status' => '0', 'createdate' => null])];
$d = $D::compute($ps, $base($ps), $ctx);
$check('Delta 2: gleicher Stand = keine Aenderungen', 'delta' === $d['mode'] && [] === $d['events'] && ['ÄNDERUNGEN: keine'] === $D::lines($d));
// 3 Ausfall und Wiederkehr
$d = $D::compute([$mk('a.de', '8.4.26', '5.21.4', $ok, ['status' => '0']), $mk('b.de', '8.4.26', '5.21.4', $ok)], $base([$mk('a.de', '8.4.26', '5.21.4', $ok), $mk('b.de', '8.4.26', '5.21.4', $ok, ['status' => '0'])]), $ctx);
$check('Delta 3: STATUS ausgefallen und wieder erreichbar', ['a.de NEU AUSGEFALLEN', 'b.de WIEDER ERREICHBAR'] === $ev($d, 'STATUS') && 'STATUS: a.de NEU AUSGEFALLEN, b.de WIEDER ERREICHBAR' === $D::lines($d)[1] && 'ÄNDERUNGEN seit 2026-10-07 06:30' === $D::lines($d)[0]);
$check('Delta 3: ausgefallenes Projekt erzeugt keine weiteren Ereignisse', [] === $ev($d, 'KERN') && [] === $ev($d, 'BEFUND NEU'));
// 4 Kern
$d = $D::compute([$mk('a.de', '8.4.26', '5.21.4', '10.11.18-MariaDB')], $base([$mk('a.de', '8.4.24', '5.21.2', $ok)]), $ctx);
$check('Delta 4: KERN rex, php, db', ['a.de rex 5.21.2 -> 5.21.4', 'a.de php 8.4.24 -> 8.4.26', 'a.de db 10.11.14-MariaDB -> 10.11.18-MariaDB'] === $ev($d, 'KERN') && 'KERN: a.de rex 5.21.2 -> 5.21.4, a.de php 8.4.24 -> 8.4.26, a.de db 10.11.14-MariaDB -> 10.11.18-MariaDB' === $D::lines($d)[1]);
// 5 Addon angewendet / neu / entfernt
$d = $D::compute([$mk('a.de', '8.4.26', '5.21.4', $ok, ['addons' => ['mform' => ['10.0.2'], 'cke5' => ['7.8.0'], 'yform' => ['5.0.2']]])], $base([$mk('a.de', '8.4.26', '5.21.4', $ok, ['addons' => ['mform' => ['9.4.1'], 'old' => ['1.0'], 'yform' => ['5.0.2']]])]), $ctx);
$check('Delta 5: ADDON ANGEWENDET (Version, neu, entfernt)', ['a.de cke5 neu installiert 7.8.0', 'a.de mform 9.4.1 -> 10.0.2', 'a.de old entfernt'] === $ev($d, 'ADDON ANGEWENDET'));
// 6 Update neu: aggregiert, Domains erst unter 5 Projekten
$now35 = []; $then35 = [];
for ($i = 1; $i <= 35; ++$i) {
    $n = sprintf('p%02d.example', $i);
    $then35[] = $mk($n, '8.4.26', '5.21.4', $ok, ['addons' => ['cke5' => ['7.7.0'], 'mform' => ['9.4.1']]]);
    $now35[] = $mk($n, '8.4.26', '5.21.4', $ok, ['addons' => ['cke5' => ['7.7.0', '7.8.0'], 'mform' => ['9.4.1', $i <= 3 ? '10.0.2' : '9.4.1']]]);
}
$d = $D::compute($now35, $base($then35), $ctx);
$upd = $ev($d, 'UPDATE NEU');
$check('Delta 6: UPDATE NEU aggregiert (35 Projekte ohne Domains, 3 mit Domains)', ['cke5 7.8.0 bei 35 Projekten', 'mform 10.0.2 bei 3 Projekten (p01.example, p02.example, p03.example)'] === $upd);
$check('Delta 6: UPDATE NEU Zeile mit Trenner', 'UPDATE NEU: cke5 7.8.0 bei 35 Projekten | mform 10.0.2 bei 3 Projekten (p01.example, p02.example, p03.example)' === $D::lines($d)[1]);
$d1 = $D::compute([$mk('z.de', '8.4.26', '5.21.4', $ok, ['addons' => ['mform' => ['9.4.1', '10.0.2']]])], $base([$mk('z.de', '8.4.26', '5.21.4', $ok, ['addons' => ['mform' => ['9.4.1']]])]), $ctx);
$check('Delta 6: ein Projekt "bei 1 Projekt"', ['mform 10.0.2 bei 1 Projekt (z.de)'] === $ev($d1, 'UPDATE NEU'));
// 7 Update unveraendert = kein Ereignis, geaendertes Ziel = Ereignis, Ausschluss und Vorabversion greifen
$was = [$mk('z.de', '8.4.26', '5.21.4', $ok, ['addons' => ['mform' => ['9.4.1', '10.0.2']]])];
$check('Delta 7: gleiches Update bleibt ohne Ereignis', [] === $ev($D::compute($was, $base($was), $ctx), 'UPDATE NEU'));
$newer = [$mk('z.de', '8.4.26', '5.21.4', $ok, ['addons' => ['mform' => ['9.4.1', '10.1.0']]])];
$check('Delta 7: neues Ziel meldet erneut', ['mform 10.1.0 bei 1 Projekt (z.de)'] === $ev($D::compute($newer, $base($was), $ctx), 'UPDATE NEU'));
$beta = [$mk('z.de', '8.4.26', '5.21.4', $ok, ['addons' => ['mform' => ['9.4.1', '10.2.0-beta1']]])];
$check('Delta 7: Vorabversion wird ignoriert', [] === $ev($D::compute($beta, $base($was), $ctx), 'UPDATE NEU'));
$skip = $cfg; $skip['skip_addon'] = ['mform'];
$check('Delta 7: ausgeschlossenes Addon wird ignoriert', [] === $ev($D::compute($newer, $base($was), ['settings' => $skip]), 'UPDATE NEU'));
// 8 Befund neu / behoben
$d = $D::compute([$mk('a.de', '8.2.34', '5.21.4', $ok), $mk('b.de', '8.4.26', '5.21.4', $ok)], $base([$mk('a.de', '8.4.26', '5.21.4', $ok), $mk('b.de', '8.1.0', '5.21.4', $ok)]), $ctx);
$check('Delta 8: BEFUND NEU und BEHOBEN', ['a.de below_min php'] === $ev($d, 'BEFUND NEU') && ['b.de below_min php'] === $ev($d, 'BEFUND BEHOBEN'));
$check('Delta 8: ohne Regelaenderung keine Kennzeichnung', !str_contains(implode("\n", $D::lines($d)), 'Regeländerung'));
// 9 Rueckstand (Flotte) neu
$fleetNow = $C::applyPatchLag([$mk('a.de', '8.4.12', '5.21.4', $ok), $mk('b.de', '8.4.26', '5.21.4', $ok)], 3);
$fleetThen = $C::applyPatchLag([$mk('a.de', '8.4.25', '5.21.4', $ok), $mk('b.de', '8.4.26', '5.21.4', $ok)], 3);
$d = $D::compute($fleetNow, $base($fleetThen), $ctx);
$check('Delta 9: behind neu', ['a.de behind php 8.4.12<8.4.26'] === $ev($d, 'BEFUND NEU') && in_array('BEFUND NEU: a.de behind php 8.4.12<8.4.26', $D::lines($d), true));
// 10 Regelaenderung: kein Scheindelta, echtes Delta wird gekennzeichnet
$stricter = $cfg; $stricter['min_php'] = '8.4';
$same = [$mk('a.de', '8.3.5', '5.21.4', $ok)];
$d = $D::compute($same, $base($same), ['settings' => $stricter, 'rules_changed' => true]);
$check('Delta 10: Regelaenderung allein erzeugt kein Scheindelta', [] === $d['events'] && 'ÄNDERUNGEN: keine' === $D::lines($d)[0]);
$d = $D::compute([$mk('a.de', '8.4.2', '5.21.4', $ok)], $base($same), ['settings' => $stricter, 'rules_changed' => true]);
$check('Delta 10: echtes Delta mit (Regeländerung) gekennzeichnet', ['a.de below_min php'] === $ev($d, 'BEFUND BEHOBEN') && in_array('BEFUND BEHOBEN: a.de below_min php (Regeländerung)', $D::lines($d), true));
$check('Delta 10: Fingerabdruck aendert sich bei Regeln, nicht bei Syslog-Filtern', $D::rulesFingerprint($cfg) !== $D::rulesFingerprint($stricter) && $D::rulesFingerprint($cfg) === $D::rulesFingerprint(array_merge($cfg, ['syslog_ignore' => ['x'], 'stale_hours' => 99])) && $D::rulesFingerprint($cfg) !== $D::rulesFingerprint(array_merge($cfg, ['overrides' => ['a.de' => ['min_php' => '0.0']]])));
// 11 Syslog neu: Merge ueber zwei Tage, Ausnahmeliste, nur seit Baseline
$g = static fn (string $m, string $f = 'a.php', string $l = '72', int $n = 1): array => ['type' => 'Warning', 'message' => $m, 'file' => $f, 'line' => $l, 'count' => $n];
$withSys = static fn (array $groups): array => array_merge($mk('a.de', '8.4.26', '5.21.4', $ok), ['syslog' => $groups]);
$m1 = $D::mergeSeen([], [$withSys([$g('alt')])], $then);
$m2 = $D::mergeSeen($m1['seen'], [$withSys([$g('alt', 'a.php', '72', 5), $g('neu'), $g('Undefined variable $params', 'x.php', '1')])], $day2);
$new = $D::newSyslog($m2['seen'], '2026-10-07 06:30:00', ['Info'], ['undefined variable $params']);
$check('Delta 11: nur neue Hashes seit der Baseline, nach Ausnahmeliste', ['neu'] === array_column($new, 'msg'));
$check('Delta 11: bekannter Eintrag behaelt first_seen, aktualisiert count und last_seen', '2026-10-07 06:30:00' === $m2['seen']['a.de|' . $D::hash($g('alt'))]['first_seen'] && 5 === $m2['seen']['a.de|' . $D::hash($g('alt'))]['count'] && '2026-10-08 06:30:00' === $m2['seen']['a.de|' . $D::hash($g('alt'))]['last_seen']);
$check('Delta 11: ohne Baseline keine neuen Syslog-Eintraege', [] === $D::newSyslog($m2['seen'], null, [], []));
$d = $D::compute([$withSys([$g('neu')])], $base([$mk('a.de', '8.4.26', '5.21.4', $ok)]), ['settings' => $cfg, 'syslog_new' => $new]);
$check('Delta 11: SYSLOG NEU im Ereignis', ['a.de Warning "neu" (a.php:72) x1'] === $ev($d, 'SYSLOG NEU') && 'SYSLOG NEU: a.de Warning "neu" (a.php:72) x1' === $D::lines($d)[1]);
$check('Delta 11: Hash aus Typ|Meldung|Datei|Zeile', sha1('Warning|neu|a.php|72') === $D::hash($g('neu')));
// 12 Projekte neu und entfernt, neue Projekte ohne weitere Ereignisse
$d = $D::compute([$mk('a.de', '8.4.26', '5.21.4', $ok), $mk('neu.de', '7.4.33', '5.14.3', $ok)], $base([$mk('a.de', '8.4.26', '5.21.4', $ok), $mk('alt.de', '8.4.26', '5.21.4', $ok)]), $ctx);
$check('Delta 12: PROJEKTE + neu | - alt, neues Projekt ohne Befundzeile', 'PROJEKTE: + neu.de | - alt.de' === $D::lines($d)[1] && [] === $ev($d, 'BEFUND NEU'));
// 13 Kappung auf 10 Eintraege
$many = []; $manyThen = [];
for ($i = 1; $i <= 12; ++$i) {
    $manyThen[] = $mk(sprintf('k%02d.de', $i), '8.4.26', '5.21.2', $ok);
    $many[] = $mk(sprintf('k%02d.de', $i), '8.4.26', '5.21.4', $ok);
}
$line = $D::lines($D::compute($many, $base($manyThen), $ctx))[1];
$check('Delta 13: mehr als 10 Eintraege => +N weitere', str_ends_with($line, ', +2 weitere') && 10 === substr_count($line, ' rex 5.21.2 -> 5.21.4'));
// 14 Fehlerdauer
$st = $D::nextState($mk('e.de', '8.4.26', '5.21.4', $ok, ['status' => '0', 'createdate' => '2026-10-01 00:00:12']), null, $day2);
$check('Fehlerdauer: neue Serie beginnt am letzten Erfolg', '2026-10-01 00:00:12' === $st['first_failed_at'] && 'seit 7 Tagen (letzter Erfolg 2026-10-01)' === $D::errorText($st, $day2));
$st = $D::nextState($mk('e.de', '8.4.26', '5.21.4', $ok, ['status' => '0', 'createdate' => '2026-10-01 00:00:12']), ['first_failed_at' => '2024-06-20 00:00:00', 'last_success_at' => '2024-06-20 00:00:00'], $day2);
$check('Fehlerdauer: laufende Serie behaelt Beginn', '2024-06-20 00:00:00' === $st['first_failed_at']);
$never = $D::nextState($mk('n.de', '', '', '', ['status' => '0', 'createdate' => null]), null, $day2);
$check('Fehlerdauer: nie erfolgreich', null === $never['last_success_at'] && 'seit Beginn des Monitorings (nie erfolgreich)' === $D::errorText($never, $day2) && '2026-10-08 06:30:00' === $never['first_failed_at']);
$check('Fehlerdauer: Erfolg beendet die Serie', null === $D::nextState($mk('a.de', '8.4.26', '5.21.4', $ok), ['first_failed_at' => '2026-10-01 00:00:00', 'last_success_at' => '2026-09-30 00:00:00'], $day2)['first_failed_at']);
$check('Fehlerdauer: 842 Tage', 'seit 842 Tagen (letzter Erfolg 2024-06-20)' === $D::errorText(['first_failed_at' => '2024-06-20 00:00:00', 'last_success_at' => '2024-06-20 00:00:00'], new DateTimeImmutable('2026-10-10 00:00:00')));

// 15 Data-Mail mit Delta, Fehlerdauer, Pause und Aggregat
$pe = $mk('err.de', '8.4.26', '5.21.4', $ok, ['status' => '0', 'createdate' => '2026-10-01 00:00:12']);
$pe['error_text'] = 'seit 7 Tagen (letzter Erfolg 2026-10-01)';
$pp = array_merge($mk('pause.de', '7.4.33', '5.14.3', $ok, ['status' => '0', 'createdate' => null]), ['paused' => true, 'pause_reason' => 'Relaunch']);
$pu = $mk('upd.de', '8.4.26', '5.21.4', $ok, ['addons' => ['mform' => ['9.4.1', '10.0.2'], 'cke5' => ['7.7.0', '7.8.0']]]);
$pu2 = $mk('upd2.de', '8.4.26', '5.21.4', $ok, ['addons' => ['mform' => ['9.4.1', '10.0.2']]]);
$delta = $D::compute([$pe, $pp, $pu, $pu2], $base([$mk('err.de', '8.4.26', '5.21.4', $ok), $mk('pause.de', '8.4.26', '5.21.4', $ok), $mk('upd.de', '8.4.26', '5.21.4', $ok), $mk('upd2.de', '8.4.26', '5.21.4', $ok)]), $ctx);
$mail = $B::buildMachine([$pe, $pp, $pu, $pu2], $day2, $delta);
$ml = explode("\n", $mail['body']);
$check('Data-Mail: Kopf unveraendert, Pausiert nur bei N > 0', str_starts_with($ml[0], 'Lauf: 2026-10-08 06:30 | Abgefragt: 4 | OK: 2 | Fehler: 1 | Mit Befund: 1 | Ältester Abruf: ') && str_ends_with($ml[0], ' | Pausiert: 1') && 'SYSLOG_IGNORIERT: 0' === $ml[1]);
$check('Data-Mail: Reihenfolge der Abschnitte', 'ÄNDERUNGEN seit 2026-10-07 06:30' === $ml[3] && 'STATUS: err.de NEU AUSGEFALLEN' === $ml[4]);
$check('Data-Mail: FEHLER-, PAUSIERT- und Aggregatzeile', in_array('FEHLER: err.de seit 7 Tagen (letzter Erfolg 2026-10-01)', $ml, true) && in_array('PAUSIERT: pause.de (Relaunch)', $ml, true) && in_array('ADDON-UPDATES AGGREGIERT: mform 10.0.2 x2 | cke5 7.8.0 x1', $ml, true));
$check('Data-Mail: pausiertes Projekt ohne Ereignis, ohne FEHLER-Zeile, status=paused', !str_contains(implode("\n", $D::lines($delta)), 'pause.de') && !str_contains($mail['body'], 'FEHLER: pause.de') && str_contains($mail['body'], 'pause.de | - | - | - | addon_updates=- | syslog=- | below_min=- | behind=- | status=paused | last_ok=-'));
$check('Data-Mail: Betreff zaehlt pausiertes Projekt nicht als Fehler', str_contains($mail['subject'], '| 1 mit Befund | 1 Fehler'));
$check('Data-Mail: Inventar bleibt am Ende, Ueberschrift unveraendert', str_contains($mail['body'], "\n\n# domain | rex | php | db | addon_updates | syslog | below_min | behind | status | last_ok\n") && str_ends_with(rtrim($mail['body'], "\n"), 'status=ok | last_ok=2026-10-08 00:00'));
$agg = [];
for ($i = 1; $i <= 17; ++$i) {
    $agg[] = $mk(sprintf('q%02d.de', $i), '8.4.26', '5.21.4', $ok, ['addons' => [sprintf('addon%02d', $i) => ['1.0', '2.0']]]);
}
$check('Aggregatzeile: hoechstens 15 Eintraege + weitere', str_contains($B::buildMachine($agg, $day2)['body'], ' | +2 weitere') && 15 === substr_count($B::buildMachine($agg, $day2)['body'], ' x1'));
// Bericht mit Delta (Text)
$rep = $B::buildReport([$pe, $pp, $pu, $pu2], $day2, false, $delta);
$check('Bericht-Text: Aenderungen, Fehlerdauer, Pause', str_contains($rep['text'], "ÄNDERUNGEN SEIT 2026-10-07 06:30\n  STATUS: err.de NEU AUSGEFALLEN") && str_contains($rep['text'], 'FEHLER seit 7 Tagen (letzter Erfolg 2026-10-01)') && str_contains($rep['text'], "PAUSIERT (kein Befund)\npause.de: Relaunch") && str_contains($rep['text'], '1 pausiert'));
$repBase = $B::buildReport([$pu], $day2, false, $D::compute([$pu], [], $ctx));
$check('Bericht-Text: Basislauf', str_contains($repBase['text'], "ÄNDERUNGEN\nBasislauf, keine Vergleichsdaten."));
$check('Delta-Zeilen fuer Report: Ereignis, Projekt, Aenderung', [['STATUS', 'err.de', 'NEU AUSGEFALLEN']] === array_slice($D::rows($delta), 0, 1));

// ---------------------------------------------------------------- Fremde Angaben: Zeilen-Injection, Spaltenbreiten, Hash, Engine, Empfaenger
$check('clean: Whitespace, Zeilenumbrueche und Steuerzeichen werden zu einem Leerzeichen', 'a b c' === $C::clean("  a\n\r\tb\x00\x1Fc  ", 50));
$check('clean: Laenge begrenzt (mit Auslassungszeichen)', 10 === mb_strlen($C::clean(str_repeat('ä', 30), 10)) && str_ends_with($C::clean(str_repeat('ä', 30), 10), '…') && 'kurz' === $C::clean('kurz', 10));
$check('clean: ungueltiges UTF-8 bricht nicht ab', 'ab' === $C::clean("a\xFFb", 10) || '' !== $C::clean("a\xFFb", 10));

$evil = "x\nFEHLER: a.de seit 0 Tagen";
$rawEvil = (string) json_encode([
    'rex_version' => '5.21.4', 'php_version' => '8.4.26', 'mysql_version' => '10.11.14-MariaDB',
    'rex_addons' => ['evil' => ['name' => $evil, 'install' => 1, 'status' => 1, 'version_current' => "1.0\n", 'version_latest' => "2.0\nPAUSIERT: b.de (x)"]],
    'syslog' => [['timestamp' => '08.10.2026 00:00:03', 'syslog_type' => "Warning\nFEHLER: c.de", 'syslog_message' => 'm', 'syslog_file' => "f.php\nFEHLER: d.de", 'syslog_line' => str_repeat('9', 50)]],
]);
$pe2 = $C::analyse(['domain' => 'a.de', 'cms' => '5', 'status' => '1', 'createdate' => '2026-10-08 00:00:12', 'raw' => $rawEvil], $now, 30, $ignore, [], $global);
$machine = $B::buildMachine([$pe2], $now)['body'];
$hasForged = false;
foreach (explode("\n", $machine) as $l) {
    if (str_starts_with($l, 'FEHLER:') || str_starts_with($l, 'PAUSIERT:')) {
        $hasForged = true;
    }
}
$check('Data-Mail: Zeilenumbrueche in Addon- und Syslog-Angaben erzeugen keine Zeilen mit festem Praefix', !$hasForged);
$ds = $D::lines($D::compute([$pe2], $base([$mk('a.de', '8.4.26', '5.21.4', $ok, ['addons' => [$evil => ['0.9']]])]), $ctx));
$hasForged = false;
foreach ($ds as $l) {
    if (str_starts_with($l, 'FEHLER:') || str_starts_with($l, 'PAUSIERT:')) {
        $hasForged = true;
    }
}
$check('Delta-Zeilen: keine eingeschleusten Praefixzeilen', !$hasForged);
$grp = $pe2['syslog'][0] ?? ['type' => '', 'file' => '', 'line' => ''];
$check('Syslog-Gruppe: Typ/Datei/Zeile passen in die Spalten (100/500/20)', mb_strlen($grp['type']) <= 100 && mb_strlen($grp['file']) <= 500 && mb_strlen($grp['line']) <= 20 && !str_contains($grp['type'] . $grp['file'], "\n"));
$long = $C::analyse(['domain' => 'l.de', 'cms' => '5', 'status' => '1', 'createdate' => '2026-10-08 00:00:12', 'raw' => $raw('8.4.26', '5.21.4', $maria, [['timestamp' => '08.10.2026 00:00:03', 'syslog_type' => str_repeat('T', 300), 'syslog_message' => 'm', 'syslog_file' => str_repeat('f', 900), 'syslog_line' => str_repeat('1', 99)]])], $now, 30, $ignore, [], $global);
$check('Syslog: sehr lange Angaben werden gekuerzt', 100 === mb_strlen($long['syslog'][0]['type']) && 500 === mb_strlen($long['syslog'][0]['file']) && 20 === mb_strlen($long['syslog'][0]['line']));

$gw = $g('gleich');
$ge = ['type' => 'Error'] + $gw;
$check('Hash: Typ gehoert dazu', $D::hash($gw) !== $D::hash($ge));
$mm = $D::mergeSeen([], [$withSys([$gw, $ge])], $then);
$check('mergeSeen: gleiche Meldung mit Warning und Error bleibt als zwei Eintraege', 2 === count($mm['seen']));

$db1 = $V::database('5.5.5-10.5.23');
$check('database: Praefix 5.5.5- ohne Wort MariaDB ist MariaDB', 'MariaDB' === $db1['engine'] && '10.5.23' === $db1['version'] && '10.5' === $db1['line']);
$db2 = $V::database('5.5.5-MariaDB');
$check('database: 5.5.5-MariaDB bleibt unbekannt', 'MariaDB' === $db2['engine'] && null === $db2['version']);
$check('database: MySQL unveraendert', 'MySQL' === $V::database('8.0.36')['engine'] && 'MariaDB' === $V::database('10.11.14-MariaDB-1:10.11.14')['engine']);

$check('parseList: doppelte Adressen (Schreibweise egal) nur einmal, erstes Vorkommen bleibt', ['a@x.de', 'b@x.de'] === $C::parseList('a@x.de, A@X.de; b@x.de'));

echo $failed, ' von ', $total, " Pruefungen fehlgeschlagen\n";
exit($failed > 0 ? 1 : 0);
