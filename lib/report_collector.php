<?php

/**
 * Liest die gesammelten Daten des Project Managers (nur Datenbank, keine externen Aufrufe)
 * und bereitet je Projekt einen Datensatz für den Report auf.
 *
 * Projekt-Datensatz:
 *   domain       string
 *   state        'ok' | 'stale' | 'error'
 *   last_success string|null   Zeitpunkt des letzten erfolgreichen Abrufs (logs.createdate)
 *   rex, php     string|null   Versionsnummern
 *   db           string|null   z. B. "10.11.14-MariaDB"
 *   db_label     string|null   z. B. "MariaDB 10.11.14"
 *   cms          string        '4' | '5' (Spalte cms des Project Managers)
 *   violations   list<array{key: 'php'|'rex', actual: string, min: string}>   unterschrittene Mindestversionen
 *   updates      list<array{name: string, current: string, latest: string}>
 *   syslog       list<array{type: string, message: string, file: string, line: string, count: int}>   Befund-Einträge der 24 h
 *   ignored      array<string, int>   durch Typ oder Meldungsmuster ignorierte Einträge der 24 h, je Typ (kein Befund)
 */
class rex_project_manager_report_collector
{
    public const DEFAULT_STALE_HOURS = 30;
    public const DEFAULT_SYSLOG_IGNORE = ['Info', 'logevent', 'Deprecated', 'Notice'];
    private const SYSLOG_WINDOW_SECONDS = 86400;
    private const MESSAGE_MAX_LENGTH = 200;
    private const PRERELEASE = '/alpha|beta|rc|dev/i';

    /**
     * @param list<string> $syslogIgnore Syslog-Typen, die nicht als Befund zählen (ohne Beachtung der Schreibweise)
     * @param list<string> $ignoreMessages Teiltexte; Einträge, in deren "Typ Meldung Datei:Zeile" einer vorkommt, zählen nicht
     * @param array<string, mixed>|null $settings Einstellungen des Project Managers (Standard: settings())
     * @return list<array<string, mixed>>
     */
    public static function collect(int $staleHours = self::DEFAULT_STALE_HOURS, array $syslogIgnore = self::DEFAULT_SYSLOG_IGNORE, array $ignoreMessages = [], ?array $settings = null, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $settings ??= self::settings();

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

        return $projects;
    }

    /**
     * Mindestanforderungen und Ausschlüsse aus den Einstellungen des Project Managers (Plugin server).
     * Leer oder ungültig = null bzw. leere Liste = keine Prüfung.
     *
     * @return array{php_min: ?string, cms_min: ?string, cms_4_min: ?string, skip_addon: list<string>, skip_addon_version: list<string>}
     */
    public static function settings(): array
    {
        $get = static fn (string $key): string => trim((string) rex_config::get('project_manager/server', $key, ''));
        $version = static fn (string $value): ?string => 1 === preg_match('/^\d+(?:\.\d+)*$/', $value) ? $value : null;
        $csv = static fn (string $value): array => array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $item) => '' !== $item));

        return [
            'php_min' => $version($get('php_min')),
            'cms_min' => $version($get('cms_min')),
            'cms_4_min' => $version($get('cms_4_min')),
            'skip_addon' => $csv($get('skip_addon')),
            'skip_addon_version' => $csv($get('skip_addon_version')),
        ];
    }

    /**
     * Liste aus Komma, Semikolon, Leerzeichen oder Zeilenumbrüchen getrennt ("a@x.de, b@y.de" -> 2 Einträge).
     *
     * @return list<string>
     */
    public static function parseList(string $value): array
    {
        return array_values(array_filter(preg_split('/[\s,;]+/', $value) ?: [], static fn (string $item) => '' !== $item));
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
            'last_success' => $row['createdate'] ?: null,
            'rex' => null,
            'php' => null,
            'db' => null,
            'db_label' => null,
            'cms' => (string) ($row['cms'] ?? ''),
            'violations' => [],
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

        $project['rex'] = self::version($data['rex_version'] ?? $data['cms_version'] ?? null);
        $project['php'] = self::version($data['php_version'] ?? null);
        [$project['db'], $project['db_label']] = self::database((string) ($data['mysql_version'] ?? ''));
        $project['violations'] = self::violations($project, $settings);
        $project['updates'] = self::updates($data['rex_addons'] ?? [], $settings['skip_addon'] ?? [], $settings['skip_addon_version'] ?? []);
        ['groups' => $project['syslog'], 'ignored' => $project['ignored']] = self::syslog($data['syslog'] ?? [], $logTime, $syslogIgnore, $ignoreMessages);

        return $project;
    }

    /** Führende Versionsnummer ohne Zusätze der Distribution ("8.4.24-nmm1" -> "8.4.24"). */
    private static function version(mixed $value): ?string
    {
        return is_string($value) && 1 === preg_match('/^\d+(?:\.\d+)*/', $value, $m) ? $m[0] : null;
    }

    /**
     * "10.11.14-MariaDB-0ubuntu0.24.04.1-log" -> ["10.11.14-MariaDB", "MariaDB 10.11.14"],
     * "8.0.35-0ubuntu0.22.04.1" -> ["8.0.35-MySQL", "MySQL 8.0.35"].
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function database(string $value): array
    {
        $version = self::version($value);
        if (null === $version) {
            return [null, null];
        }
        $product = false !== stripos($value, 'mariadb') ? 'MariaDB' : 'MySQL';

        return [$version . '-' . $product, $product . ' ' . $version];
    }

    /**
     * Unterschrittene Mindestversionen (PHP, REDAXO). REDAXO 4 gegen cms_4_min, sonst gegen cms_min.
     *
     * @param array<string, mixed> $project
     * @param array<string, mixed> $settings
     * @return list<array{key: string, actual: string, min: string}>
     */
    private static function violations(array $project, array $settings): array
    {
        $violations = [];
        $checks = [
            ['php', $project['php'], $settings['php_min'] ?? null],
            ['rex', $project['rex'], '4' === $project['cms'] ? ($settings['cms_4_min'] ?? null) : ($settings['cms_min'] ?? null)],
        ];
        foreach ($checks as [$key, $actual, $min]) {
            if (is_string($actual) && is_string($min) && version_compare($actual, $min, '<')) {
                $violations[] = ['key' => $key, 'actual' => $actual, 'min' => $min];
            }
        }

        return $violations;
    }

    /**
     * Nur installierte und aktive Addons. "version_latest" ist beim Client die erste Installer-Datei
     * und nicht zwingend die höchste Version; gemeldet wird deshalb nur, wenn sie wirklich größer ist.
     *
     * @param list<string> $skipAddons Addons, die der Project Manager von der Prüfung ausnimmt (exakter Name)
     * @param list<string> $skipVersions Teiltexte; enthält "version_latest" einen davon, zählt es nicht als Update
     * @return list<array{name: string, current: string, latest: string}>
     */
    private static function updates(mixed $addons, array $skipAddons = [], array $skipVersions = []): array
    {
        if (!is_array($addons)) {
            return [];
        }

        $updates = [];
        foreach ($addons as $key => $addon) {
            if (!is_array($addon) || empty($addon['install']) || empty($addon['status'])) {
                continue;
            }
            if (in_array((string) ($addon['name'] ?? $key), $skipAddons, true)) {
                continue;
            }
            $current = (string) ($addon['version_current'] ?? '');
            $latest = (string) ($addon['version_latest'] ?? '');
            if ('' === $current || '' === $latest || '0' === $latest || version_compare($latest, $current, '<=')) {
                continue;
            }
            if (self::matchesAny($latest, $skipVersions)) {
                continue;
            }
            // Vorabversionen (alpha, beta, rc, dev) nur melden, wenn das Projekt selbst eine einsetzt
            if (1 === preg_match(self::PRERELEASE, $latest) && 1 !== preg_match(self::PRERELEASE, $current)) {
                continue;
            }
            $updates[] = ['name' => (string) ($addon['name'] ?? $key), 'current' => $current, 'latest' => $latest];
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
            $type = trim((string) ($entry['syslog_type'] ?? ''));
            // Zeitstempel unlesbar: lieber melden als verschweigen
            $time = DateTimeImmutable::createFromFormat('d.m.Y H:i:s', (string) ($entry['timestamp'] ?? ''));
            if ($time && $time < $from) {
                continue;
            }
            if (in_array(strtolower($type), $ignore, true)) {
                $ignored[$type] = ($ignored[$type] ?? 0) + 1;
                continue;
            }

            $message = trim((string) preg_replace('/\s+/', ' ', (string) ($entry['syslog_message'] ?? '')));
            if (mb_strlen($message) > self::MESSAGE_MAX_LENGTH) {
                $message = mb_substr($message, 0, self::MESSAGE_MAX_LENGTH - 1) . '…';
            }
            $file = (string) ($entry['syslog_file'] ?? '');
            $line = (string) ($entry['syslog_line'] ?? '');

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
    private static function matchesAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ('' !== $needle && false !== stripos($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
