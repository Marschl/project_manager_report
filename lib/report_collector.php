<?php

/**
 * Liest die gesammelten Daten des Project Managers (nur Datenbank, keine externen Aufrufe)
 * und bereitet je Projekt einen Datensatz für den Report auf.
 *
 * Projekt-Datensatz:
 *   domain       string
 *   state        'ok' | 'stale' | 'error'
 *   last_success string|null   Zeitpunkt des letzten erfolgreichen Abrufs (logs.createdate)
 *   cms          string        '4' | '5' (Spalte cms des Project Managers)
 *   rex          string|null   REDAXO-Version
 *   php          string|null   PHP-Version ohne Zusätze, z. B. "8.4.24"
 *   php_line     string|null   PHP-Linie, z. B. "8.4"
 *   php_patch    int|null      Patchstufe
 *   db_engine    'MariaDB'|'MySQL'|null
 *   db_version   string|null   Version ohne Zusätze, null = unbekannt
 *   db           string        für das Inventar: "10.11.14-MariaDB", "unbekannt" oder "-" (keine Daten)
 *   db_label     string        für Text: "MariaDB 10.11.14", "DB unbekannt" oder "-"
 *   violations   list<array{key: 'db'|'php'|'rex', label: string, min: string}>   unterschrittene Mindestversionen
 *   behind       array{line: string, actual: string, max: string, lag: int}|null  Patch-Rückstand in der PHP-Linie
 *   addons       array<string, string>   installierte Addons mit Version (Rohdaten für das Delta)
 *   updates_all  array<string, string>   Addon => Zielversion, aktive Addons mit neuerer Version, ungefiltert (Rohdaten)
 *   updates      list<array{name: string, current: string, latest: string}>   updates_all nach Ausschlüssen und Vorabversions-Regel
 *   syslog       list<array{type: string, message: string, file: string, line: string, count: int}>   Befund-Einträge der 24 h
 *   ignored      array<string, int>   durch Typ oder Meldungsmuster ignorierte Einträge der 24 h, je Typ (kein Befund)
 */
class rex_project_manager_report_collector
{
    public const DEFAULT_STALE_HOURS = 30;
    public const DEFAULT_SYSLOG_IGNORE = ['Info', 'logevent', 'Deprecated', 'Notice'];

    /** Startwerte der Einstellungen (gelten, solange ein Schlüssel nie gespeichert wurde) */
    public const SETTING_DEFAULTS = [
        'min_rex' => '5.21.2',
        'min_php' => '8.3',
        'min_mariadb' => '10.11',
        'min_mysql' => '8.4',
        'max_patch_lag' => '3',
        'ignore_prerelease' => '1',
        'recipients' => '',
        'recipients_report' => '',
        'stale_hours' => '30',
        'syslog_ignore' => 'Info,logevent,Deprecated,Notice',
        'syslog_ignore_messages' => '',
        'dry_run' => '0',
        'dry_run_address' => '',
    ];

    private const SYSLOG_WINDOW_SECONDS = 86400;
    private const MESSAGE_MAX_LENGTH = 200;
    /** Spaltenbreiten von syslog_seen (type, file, line) und Grenzen für Addon-Angaben */
    private const TYPE_MAX_LENGTH = 100;
    private const FILE_MAX_LENGTH = 500;
    private const LINE_MAX_LENGTH = 20;
    private const ADDON_NAME_MAX_LENGTH = 100;
    private const ADDON_VERSION_MAX_LENGTH = 50;
    private const PRERELEASE = '/alpha|beta|rc|dev/i';

    /**
     * Alle Angaben fehlen = Werte aus den Einstellungen des Addons.
     *
     * @param int|null $staleHours Stunden bis VERALTET
     * @param list<string>|null $syslogIgnore Syslog-Typen, die nicht als Befund zählen (ohne Beachtung der Schreibweise)
     * @param list<string>|null $ignoreMessages Teiltexte; Einträge, in deren "Typ Meldung Datei:Zeile" einer vorkommt, zählen nicht
     * @param array<string, mixed>|null $settings siehe settings() (Standard: settings())
     * @return list<array<string, mixed>>
     */
    public static function collect(?int $staleHours = null, ?array $syslogIgnore = null, ?array $ignoreMessages = null, ?array $settings = null, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $settings ??= self::settings();
        $staleHours ??= $settings['stale_hours'] ?? self::DEFAULT_STALE_HOURS;
        $syslogIgnore ??= $settings['syslog_ignore'] ?? self::DEFAULT_SYSLOG_IGNORE;
        $ignoreMessages ??= $settings['ignore_messages'] ?? [];

        // je Projekt der neueste Log-Eintrag (der Abruf-Cronjob hält nur einen, die Server-API kann mehrere anlegen)
        $rows = rex_sql::factory()->getArray(
            'SELECT d.domain, d.cms, d.status, l.raw, l.createdate
             FROM ' . rex::getTable('project_manager_domain') . ' d
             LEFT JOIN ' . rex::getTable('project_manager_logs') . ' l
                ON l.id = (SELECT MAX(l2.id) FROM ' . rex::getTable('project_manager_logs') . ' l2 WHERE l2.domain_id = d.id)
             ORDER BY d.domain',
        );

        $projects = [];
        foreach ($rows as $row) {
            $projects[] = self::analyse($row, $now, $staleHours, $syslogIgnore, $ignoreMessages, $settings);
        }

        return self::applyPatchLag($projects, $settings['max_patch_lag'] ?? null);
    }

    /**
     * Einstellungen des Addons (Seite "Einstellungen"), Overrides je Projekt und die Ausschlüsse des Project Managers.
     * Nie gespeichert = Startwert; leer gespeichert oder ungültig = Regel aus (null).
     *
     * @return array{min_rex: ?string, min_php: ?string, min_mariadb: ?string, min_mysql: ?string, max_patch_lag: ?int, ignore_prerelease: bool, recipients: list<string>, recipients_report: list<string>, dry_run: bool, dry_run_address: string, stale_hours: int, syslog_ignore: list<string>, ignore_messages: list<string>, skip_addon: list<string>, skip_addon_version: list<string>, overrides: array<string, array<string, ?string>>}
     */
    public static function settings(): array
    {
        $addon = rex_addon::get('project_manager_report');
        $get = static fn (string $key): string => $addon->hasConfig($key) ? trim((string) $addon->getConfig($key)) : self::SETTING_DEFAULTS[$key];

        $lag = $get('max_patch_lag');
        $pm = static fn (string $key): string => trim((string) rex_config::get('project_manager/server', $key, ''));
        $csv = static fn (string $value): array => array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $item) => '' !== $item));

        return [
            'min_rex' => rex_project_manager_report_version::setting($get('min_rex')),
            'min_php' => rex_project_manager_report_version::setting($get('min_php')),
            'min_mariadb' => rex_project_manager_report_version::setting($get('min_mariadb')),
            'min_mysql' => rex_project_manager_report_version::setting($get('min_mysql')),
            'max_patch_lag' => 1 === preg_match('/^\d+$/', $lag) ? (int) $lag : null,
            'ignore_prerelease' => '' !== $get('ignore_prerelease') && '0' !== $get('ignore_prerelease'),
            'recipients' => self::parseList($get('recipients')),
            'recipients_report' => self::parseList($get('recipients_report')),
            'dry_run' => '' !== $get('dry_run') && '0' !== $get('dry_run'),
            'dry_run_address' => [] === self::invalidAddresses(self::parseList($get('dry_run_address'))) ? (self::parseList($get('dry_run_address'))[0] ?? '') : '',
            'stale_hours' => 1 === preg_match('/^\d+$/', $get('stale_hours')) && (int) $get('stale_hours') >= 1 ? (int) $get('stale_hours') : self::DEFAULT_STALE_HOURS,
            'syslog_ignore' => self::parseList($get('syslog_ignore')),
            'ignore_messages' => self::parseLines($get('syslog_ignore_messages')),
            'skip_addon' => $csv($pm('skip_addon')),
            'skip_addon_version' => $csv($pm('skip_addon_version')),
            'overrides' => self::overrides(),
        ];
    }

    /**
     * Overrides je Domain (leer/ungültig = null = globaler Wert).
     *
     * @return array<string, array<string, ?string>>
     */
    public static function overrides(): array
    {
        $table = rex::getTable('project_manager_report_override');
        if (!rex_sql_table::get($table)->exists()) {
            return [];
        }

        $overrides = [];
        foreach (rex_sql::factory()->getArray('SELECT domain, min_rex, min_php, min_mariadb, min_mysql FROM ' . $table) as $row) {
            $overrides[(string) $row['domain']] = [
                'min_rex' => rex_project_manager_report_version::setting($row['min_rex']),
                'min_php' => rex_project_manager_report_version::setting($row['min_php']),
                'min_mariadb' => rex_project_manager_report_version::setting($row['min_mariadb']),
                'min_mysql' => rex_project_manager_report_version::setting($row['min_mysql']),
            ];
        }

        return $overrides;
    }

    /**
     * Fremde Angaben für zeilenorientierte Ausgaben und feste Spaltenbreiten aufbereiten:
     * Whitespace und Steuerzeichen (auch Zeilenumbrüche) werden zu einem Leerzeichen, Länge begrenzt.
     */
    public static function clean(string $value, int $max): string
    {
        $value = trim((string) preg_replace('/[\s\x00-\x1F\x7F]+/u', ' ', mb_scrub($value)));

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max - 1) . '…' : $value;
    }

    /**
     * Liste aus Komma, Semikolon, Leerzeichen oder Zeilenumbrüchen getrennt ("a@x.de, b@y.de" → 2 Einträge).
     *
     * @return list<string>
     */
    public static function parseList(string $value): array
    {
        $unique = [];
        foreach (preg_split('/[\s,;]+/', $value) ?: [] as $item) {
            if ('' !== $item) {
                $unique[mb_strtolower($item)] ??= $item; // E-Mail-Adressen: Schreibweise egal, erstes Vorkommen bleibt
            }
        }

        return array_values($unique);
    }

    /**
     * E-Mail-Adressen, die keine gültige Adresse sind.
     *
     * @param list<string> $addresses
     * @return list<string>
     */
    public static function invalidAddresses(array $addresses): array
    {
        return array_values(array_filter($addresses, static fn (string $address) => false === filter_var($address, FILTER_VALIDATE_EMAIL)));
    }

    /**
     * Eine Angabe pro Zeile; Leerzeichen innerhalb der Zeile bleiben erhalten (für Meldungstexte).
     *
     * @return list<string>
     */
    public static function parseLines(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $value) ?: []), static fn (string $line) => '' !== $line));
    }

    /**
     * @param array{domain: string, status: mixed, raw: ?string, createdate: ?string} $row
     * @param list<string> $syslogIgnore
     * @param list<string> $ignoreMessages
     * @param array<string, mixed> $settings siehe settings(); leer = keine Mindestversionen, keine Ausschlüsse
     * @return array<string, mixed>
     */
    public static function analyse(array $row, DateTimeImmutable $now, int $staleHours, array $syslogIgnore, array $ignoreMessages = [], array $settings = []): array
    {
        $project = [
            'domain' => (string) $row['domain'],
            'state' => 'error',
            // "0000-00-00 00:00:00" (Spaltenvorgabe des Project Managers) ist kein Zeitpunkt
            'last_success' => $row['createdate'] && !str_starts_with((string) $row['createdate'], '0000') ? $row['createdate'] : null,
            'cms' => (string) ($row['cms'] ?? ''),
            'rex' => null,
            'php' => null,
            'php_line' => null,
            'php_patch' => null,
            'db_engine' => null,
            'db_version' => null,
            'db' => '-',
            'db_label' => '-',
            'violations' => [],
            'behind' => null,
            'addons' => [],
            'updates_all' => [],
            'updates' => [],
            'syslog' => [],
            'ignored' => [],
        ];

        $data = null === $row['raw'] || '' === $row['raw'] ? null : json_decode($row['raw'], true);

        // Fehler: letzter Abruf nicht erfolgreich (status 0 oder -1), kein Log oder Log unlesbar
        if (1 !== (int) $row['status'] || !is_array($data) || !$project['last_success']) {
            return $project;
        }

        $logTime = new DateTimeImmutable($project['last_success']);
        $project['state'] = $logTime < $now->modify('-' . $staleHours . ' hours') ? 'stale' : 'ok';

        $project['rex'] = rex_project_manager_report_version::rex($data['rex_version'] ?? $data['cms_version'] ?? null);
        $php = rex_project_manager_report_version::php($data['php_version'] ?? null);
        $project['php'] = $php['full'] ?? null;
        $project['php_line'] = $php['line'] ?? null;
        $project['php_patch'] = $php['patch'] ?? null;

        $db = rex_project_manager_report_version::database($data['mysql_version'] ?? null);
        $project['db_engine'] = $db['engine'];
        $project['db_version'] = $db['version'];
        $project['db'] = null === $db['version'] ? 'unbekannt' : $db['version'] . '-' . $db['engine'];
        $project['db_label'] = null === $db['version'] ? 'DB unbekannt' : $db['engine'] . ' ' . $db['version'];

        $project['violations'] = self::violationsFor($project, $settings);
        $project['addons'] = self::installedAddons($data['rex_addons'] ?? []);
        $project['updates_all'] = self::allUpdates($data['rex_addons'] ?? []);
        $project['updates'] = self::filterUpdates($project['updates_all'], $project['addons'], $settings);
        ['groups' => $project['syslog'], 'ignored' => $project['ignored']] = self::syslog($data['syslog'] ?? [], $logTime, $syslogIgnore, $ignoreMessages);

        return $project;
    }

    /**
     * Patch-Rückstand: je PHP-Linie wird das Flottenmaximum der Patchstufe aus allen Projekten mit Daten
     * (nicht FEHLER) bestimmt. Liegt ein Projekt mehr als $maxLag Patches dahinter, ist es im Rückstand.
     * Linien mit nur einem Projekt haben keinen Rückstand; null = Regel aus.
     *
     * @param list<array<string, mixed>> $projects
     * @return list<array<string, mixed>>
     */
    public static function applyPatchLag(array $projects, ?int $maxLag): array
    {
        if (null === $maxLag) {
            return $projects;
        }

        $count = [];
        $max = [];
        foreach ($projects as $project) {
            if ('error' === $project['state'] || null === $project['php_line']) {
                continue;
            }
            $line = $project['php_line'];
            $count[$line] = ($count[$line] ?? 0) + 1;
            $max[$line] = max($max[$line] ?? 0, $project['php_patch']);
        }

        foreach ($projects as &$project) {
            if ('error' === $project['state'] || null === $project['php_line'] || $count[$project['php_line']] < 2) {
                continue;
            }
            $line = $project['php_line'];
            $lag = $max[$line] - $project['php_patch'];
            if ($lag > $maxLag) {
                $project['behind'] = ['line' => $line, 'actual' => $project['php'], 'max' => $line . '.' . $max[$line], 'lag' => $lag];
            }
        }
        unset($project);

        return $projects;
    }

    /**
     * Rohdaten eines Projekts für den Zustandsspeicher (reported.payload); Befunde werden nie gespeichert.
     *
     * @param array<string, mixed> $project Projekt mit Daten (nicht FEHLER)
     * @return array{cms: string, rex: ?string, php: ?string, db_engine: ?string, db: ?string, addons: array<string, string>, updates: array<string, string>}
     */
    public static function payload(array $project): array
    {
        return [
            'cms' => $project['cms'],
            'rex' => $project['rex'],
            'php' => $project['php'],
            'db_engine' => $project['db_engine'],
            'db' => $project['db_version'],
            'addons' => $project['addons'],
            'updates' => $project['updates_all'],
        ];
    }

    /**
     * Projekt-Datensatz aus gespeicherten Rohdaten (ohne Befunde); danach evaluate() aufrufen.
     *
     * @param array<string, mixed> $payload siehe payload()
     * @return array<string, mixed>
     */
    public static function fromPayload(string $domain, array $payload): array
    {
        $php = rex_project_manager_report_version::php($payload['php'] ?? null);

        return [
            'domain' => $domain,
            'state' => 'ok',
            'last_success' => null,
            'cms' => (string) ($payload['cms'] ?? ''),
            'rex' => $payload['rex'] ?? null,
            'php' => $php['full'] ?? null,
            'php_line' => $php['line'] ?? null,
            'php_patch' => $php['patch'] ?? null,
            'db_engine' => $payload['db_engine'] ?? null,
            'db_version' => $payload['db'] ?? null,
            'violations' => [],
            'behind' => null,
            'addons' => (array) ($payload['addons'] ?? []),
            'updates_all' => (array) ($payload['updates'] ?? []),
            'updates' => [],
            'syslog' => [],
            'ignored' => [],
        ];
    }

    /**
     * Mindestversionen und Patch-Rückstand für Projekte neu berechnen (z. B. die Baseline mit der aktuellen Konfiguration).
     *
     * @param list<array<string, mixed>> $projects
     * @param array<string, mixed> $settings
     * @return list<array<string, mixed>>
     */
    public static function evaluate(array $projects, array $settings): array
    {
        foreach ($projects as &$project) {
            $project['violations'] = 'error' === $project['state'] ? [] : self::violationsFor($project, $settings);
            $project['behind'] = null;
        }
        unset($project);

        return self::applyPatchLag($projects, $settings['max_patch_lag'] ?? null);
    }

    /**
     * Unterschrittene Mindestversionen: PHP und Datenbank nur auf major.minor, REDAXO voll.
     * Minimum je Projekt aus dem Override, sonst global; die Datenbank-Engine wählt min_mariadb oder min_mysql.
     * REDAXO-4-Projekte prüfen min_rex nicht. Unbekannte Datenbankversion ist kein Befund.
     *
     * @param array<string, mixed> $project
     * @param array<string, mixed> $settings
     * @return list<array{key: string, label: string, min: string}>
     */
    public static function violationsFor(array $project, array $settings): array
    {
        $override = $settings['overrides'][$project['domain']] ?? [];
        $min = static fn (string $key): ?string => $override[$key] ?? $settings[$key] ?? null;
        $version = 'rex_project_manager_report_version';
        $violations = [];

        $phpMin = $min('min_php');
        if (null !== $phpMin && null !== $project['php'] && $version::lineBelow($project['php'], $phpMin)) {
            $violations[] = ['key' => 'php', 'label' => 'PHP ' . $project['php'], 'min' => $phpMin];
        }

        $rexMin = $min('min_rex');
        if ('4' !== $project['cms'] && null !== $rexMin && null !== $project['rex'] && $version::below($project['rex'], $rexMin)) {
            $violations[] = ['key' => 'rex', 'label' => 'REDAXO ' . $project['rex'], 'min' => $rexMin];
        }

        $dbMin = $min('MariaDB' === $project['db_engine'] ? 'min_mariadb' : 'min_mysql');
        if (null !== $dbMin && null !== $project['db_version'] && $version::lineBelow($project['db_version'], $dbMin)) {
            $violations[] = ['key' => 'db', 'label' => $project['db_engine'] . ' ' . $project['db_version'], 'min' => $dbMin];
        }

        return $violations;
    }

    /**
     * Installierte Addons mit Version.
     *
     * @return array<string, string>
     */
    private static function installedAddons(mixed $addons): array
    {
        $installed = [];
        foreach (is_array($addons) ? $addons : [] as $key => $addon) {
            if (is_array($addon) && !empty($addon['install']) && '' !== (string) ($addon['version_current'] ?? '')) {
                $installed[self::clean((string) ($addon['name'] ?? $key), self::ADDON_NAME_MAX_LENGTH)] = self::clean((string) $addon['version_current'], self::ADDON_VERSION_MAX_LENGTH);
            }
        }
        ksort($installed, SORT_STRING | SORT_FLAG_CASE);

        return $installed;
    }

    /**
     * Aktive Addons, bei denen "version_latest" wirklich größer ist als "version_current" (ungefiltert).
     * "version_latest" ist beim Client die erste Installer-Datei und nicht zwingend die höchste Version.
     *
     * @return array<string, string> Addon => Zielversion
     */
    private static function allUpdates(mixed $addons): array
    {
        $updates = [];
        foreach (is_array($addons) ? $addons : [] as $key => $addon) {
            if (!is_array($addon) || empty($addon['install']) || empty($addon['status'])) {
                continue;
            }
            $current = self::clean((string) ($addon['version_current'] ?? ''), self::ADDON_VERSION_MAX_LENGTH);
            $latest = self::clean((string) ($addon['version_latest'] ?? ''), self::ADDON_VERSION_MAX_LENGTH);
            if ('' === $current || '' === $latest || '0' === $latest || version_compare($latest, $current, '<=')) {
                continue;
            }
            $updates[self::clean((string) ($addon['name'] ?? $key), self::ADDON_NAME_MAX_LENGTH)] = $latest;
        }
        ksort($updates, SORT_STRING | SORT_FLAG_CASE);

        return $updates;
    }

    /**
     * Ausschlüsse des Project Managers (Addon exakt, Version als Teiltext) und die Vorabversions-Regel anwenden.
     *
     * @param array<string, string> $updatesAll Addon => Zielversion
     * @param array<string, string> $addons Addon => installierte Version
     * @param array<string, mixed> $settings
     * @return list<array{name: string, current: string, latest: string}>
     */
    public static function filterUpdates(array $updatesAll, array $addons, array $settings): array
    {
        $skipAddons = $settings['skip_addon'] ?? [];
        $skipVersions = $settings['skip_addon_version'] ?? [];
        $ignorePrerelease = (bool) ($settings['ignore_prerelease'] ?? true);

        $updates = [];
        foreach ($updatesAll as $name => $latest) {
            $name = (string) $name;
            $current = $addons[$name] ?? '';
            if (in_array($name, $skipAddons, true) || self::matchesAny($latest, $skipVersions)) {
                continue;
            }
            if ($ignorePrerelease && 1 === preg_match(self::PRERELEASE, $latest) && 1 !== preg_match(self::PRERELEASE, $current)) {
                continue;
            }
            $updates[] = ['name' => $name, 'current' => $current, 'latest' => $latest];
        }

        usort($updates, static fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

        return $updates;
    }

    /**
     * Syslog der 24 Stunden vor dem Abruf, gruppiert nach Typ, Meldung, Datei und Zeile.
     *
     * @param list<string> $ignore Typen
     * @param list<string> $ignoreMessages Teiltexte
     * @return array{groups: list<array{type: string, message: string, file: string, line: string, count: int}>, ignored: array<string, int>}
     */
    private static function syslog(mixed $entries, DateTimeImmutable $logTime, array $ignore, array $ignoreMessages): array
    {
        if (!is_array($entries)) {
            return ['groups' => [], 'ignored' => []];
        }

        $ignore = array_map('strtolower', $ignore);
        $from = $logTime->modify('-' . self::SYSLOG_WINDOW_SECONDS . ' seconds');
        $groups = [];
        $ignored = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $type = self::clean((string) ($entry['syslog_type'] ?? ''), self::TYPE_MAX_LENGTH);
            // Zeitstempel unlesbar: lieber melden als verschweigen
            $time = DateTimeImmutable::createFromFormat('d.m.Y H:i:s', (string) ($entry['timestamp'] ?? ''));
            if ($time && $time < $from) {
                continue;
            }
            if (in_array(strtolower($type), $ignore, true)) {
                $ignored[$type] = ($ignored[$type] ?? 0) + 1;
                continue;
            }

            $message = self::clean((string) ($entry['syslog_message'] ?? ''), self::MESSAGE_MAX_LENGTH);
            $file = self::clean((string) ($entry['syslog_file'] ?? ''), self::FILE_MAX_LENGTH);
            $line = self::clean((string) ($entry['syslog_line'] ?? ''), self::LINE_MAX_LENGTH);

            if (self::matchesAny($type . ' ' . $message . ('' === $file ? '' : ' ' . $file . ('' === $line ? '' : ':' . $line)), $ignoreMessages)) {
                $ignored[$type] = ($ignored[$type] ?? 0) + 1;
                continue;
            }

            $key = $type . "\0" . $message . "\0" . $file . "\0" . $line;
            if (isset($groups[$key])) {
                ++$groups[$key]['count'];
            } else {
                $groups[$key] = ['type' => $type, 'message' => $message, 'file' => $file, 'line' => $line, 'count' => 1];
            }
        }

        ksort($ignored);

        return ['groups' => array_values($groups), 'ignored' => $ignored];
    }

    /** @param list<string> $needles */
    public static function matchesAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ('' !== $needle && false !== stripos($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
