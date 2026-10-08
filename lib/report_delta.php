<?php

/**
 * Delta gegenüber dem zuletzt gemeldeten Stand eines Mailtyps.
 *
 * Gespeichert werden nur Rohdaten (Versionen, Status, Zeiten). Befunde (below_min, behind) werden für die
 * Baseline und für heute mit der AKTUELLEN Konfiguration aus den Rohdaten berechnet, so entstehen keine
 * Scheindeltas, wenn Mindestversionen geändert werden. Reine Funktionen, keine Datenbank, kein REDAXO-Aufruf
 * (außer den reinen Klassen des Addons).
 */
class rex_project_manager_report_delta
{
    /** Mehr Einträge je Ereignistyp werden zu "+N weitere" */
    public const MAX_ITEMS = 10;
    /** Unter dieser Zahl betroffener Projekte nennt "UPDATE NEU" die Domains */
    public const UPDATE_DOMAINS_BELOW = 5;
    /** Ereignistypen in Ausgabereihenfolge */
    public const TYPES = ['STATUS', 'KERN', 'ADDON ANGEWENDET', 'UPDATE NEU', 'BEFUND NEU', 'BEFUND BEHOBEN', 'SYSLOG NEU', 'PROJEKTE'];

    /**
     * @param list<array<string, mixed>> $current aktuelle Projekte (Collector; optional 'paused')
     * @param list<array{domain: string, status: string, payload: ?array<string, mixed>, reported_at: string}> $baseline Zeilen von reported für den Mailtyp
     * @param array{settings: array<string, mixed>, rules_changed?: bool, syslog_new?: list<array<string, mixed>>} $ctx
     * @return array{mode: string, since: ?string, rules_changed: bool, events: list<array{type: string, items: list<array{text: string, domain: string, change: string}>}>}
     */
    public static function compute(array $current, array $baseline, array $ctx): array
    {
        $settings = $ctx['settings'];
        $rulesChanged = (bool) ($ctx['rules_changed'] ?? false);

        if ([] === $baseline) {
            return ['mode' => 'base', 'since' => null, 'rules_changed' => $rulesChanged, 'events' => []];
        }

        $collector = 'rex_project_manager_report_collector';
        $since = substr((string) max(array_column($baseline, 'reported_at')), 0, 10);

        // heute und Baseline mit derselben, aktuellen Konfiguration bewerten
        $cur = [];
        foreach ($collector::evaluate($current, $settings) as $project) {
            $cur[$project['domain']] = $project;
        }
        ksort($cur, SORT_STRING | SORT_FLAG_CASE);

        // Baseline als Projekte aus Rohdaten, Befunde mit der aktuellen Konfiguration neu berechnen
        $rows = [];
        $basePro = [];
        foreach ($baseline as $row) {
            $rows[$row['domain']] = $row;
            $project = $collector::fromPayload($row['domain'], 'ok' === $row['status'] ? (array) $row['payload'] : []);
            $project['state'] = 'ok' === $row['status'] ? 'ok' : 'error';
            $basePro[] = $project;
        }
        $basePro = array_column($collector::evaluate($basePro, $settings), null, 'domain');

        $events = array_fill_keys(self::TYPES, []);
        $updates = [];

        foreach ($cur as $domain => $p) {
            if (!isset($rows[$domain]) || !empty($p['paused'])) {
                continue; // neue Projekte stehen nur unter PROJEKTE, pausierte erzeugen keine Ereignisse
            }
            $b = $basePro[$domain];
            $curOk = 'error' !== $p['state'];
            $baseOk = 'error' !== $b['state'];

            if ($baseOk && !$curOk) {
                $events['STATUS'][] = self::item($domain, 'NEU AUSGEFALLEN');
            } elseif (!$baseOk && $curOk) {
                $events['STATUS'][] = self::item($domain, 'WIEDER ERREICHBAR');
            }
            if (!$baseOk || !$curOk) {
                continue;
            }

            foreach (['rex' => $p['rex'] <=> $b['rex'], 'php' => $p['php'] <=> $b['php']] as $key => $diff) {
                if (0 !== $diff) {
                    $events['KERN'][] = self::item($domain, sprintf('%s %s -> %s', $key, $b[$key] ?? '-', $p[$key] ?? '-'));
                }
            }
            if (self::db($b) !== self::db($p)) {
                $events['KERN'][] = self::item($domain, sprintf('db %s -> %s', self::db($b), self::db($p)));
            }

            foreach (self::addonChanges($b['addons'], $p['addons']) as $change) {
                $events['ADDON ANGEWENDET'][] = self::item($domain, $change);
            }

            $curUpdates = array_column($collector::filterUpdates($p['updates_all'], $p['addons'], $settings), 'latest', 'name');
            $baseUpdates = array_column($collector::filterUpdates($b['updates_all'], $b['addons'], $settings), 'latest', 'name');
            foreach ($curUpdates as $name => $latest) {
                if (($baseUpdates[$name] ?? null) !== $latest) {
                    $updates[$name . "\0" . $latest][] = $domain;
                }
            }

            $curFindings = self::findings($p);
            $baseFindings = self::findings($b);
            foreach (array_diff_key($curFindings, $baseFindings) as $text) {
                $events['BEFUND NEU'][] = self::item($domain, $text);
            }
            foreach (array_diff_key($baseFindings, $curFindings) as $text) {
                $events['BEFUND BEHOBEN'][] = self::item($domain, $text);
            }
        }

        // Update-Ereignisse aggregiert je Addon und Zielversion
        ksort($updates, SORT_STRING | SORT_FLAG_CASE);
        foreach ($updates as $key => $domains) {
            [$name, $latest] = explode("\0", $key);
            $count = count($domains);
            $text = sprintf('%s %s bei %d %s', $name, $latest, $count, 1 === $count ? 'Projekt' : 'Projekten');
            if ($count < self::UPDATE_DOMAINS_BELOW) {
                $text .= ' (' . implode(', ', $domains) . ')';
            }
            $events['UPDATE NEU'][] = ['text' => $text, 'domain' => $count . ' ' . (1 === $count ? 'Projekt' : 'Projekte') . ($count < self::UPDATE_DOMAINS_BELOW ? ': ' . implode(', ', $domains) : ''), 'change' => $name . ' ' . $latest];
        }

        foreach ($ctx['syslog_new'] ?? [] as $row) {
            $domain = (string) $row['domain'];
            if (!isset($rows[$domain], $cur[$domain]) || !empty($cur[$domain]['paused']) || 'error' === $cur[$domain]['state']) {
                continue;
            }
            $where = '' === (string) $row['file'] ? '' : sprintf(' (%s%s)', $row['file'], '' === (string) $row['line'] ? '' : ':' . $row['line']);
            $events['SYSLOG NEU'][] = self::item($domain, sprintf('%s "%s"%s x%d', $row['type'], $row['msg'], $where, $row['count']));
        }

        foreach (array_diff_key($cur, $rows) as $domain => $p) {
            $events['PROJEKTE'][] = ['text' => $domain, 'domain' => $domain, 'change' => '+ neu im Monitoring'];
        }
        $removed = array_diff_key($rows, $cur);
        ksort($removed, SORT_STRING | SORT_FLAG_CASE);
        foreach ($removed as $domain => $row) {
            $events['PROJEKTE'][] = ['text' => '-' . $domain, 'domain' => $domain, 'change' => '- entfernt'];
        }

        $list = [];
        foreach (self::TYPES as $type) {
            if ([] !== $events[$type]) {
                $list[] = ['type' => $type, 'items' => $events[$type]];
            }
        }

        return ['mode' => 'delta', 'since' => $since, 'rules_changed' => $rulesChanged, 'events' => $list];
    }

    /**
     * Zeilen der Data-Mail: Überschrift und je Ereignistyp höchstens eine Zeile.
     *
     * @param array{mode: string, since: ?string, rules_changed: bool, events: list<array{type: string, items: list<array{text: string, domain: string, change: string}>}>} $delta
     * @return list<string>
     */
    public static function lines(array $delta): array
    {
        if ('base' === $delta['mode']) {
            return ['ÄNDERUNGEN: Basislauf, keine Vergleichsdaten'];
        }
        if ([] === $delta['events']) {
            return ['ÄNDERUNGEN: keine'];
        }

        $lines = ['ÄNDERUNGEN seit ' . $delta['since']];
        foreach ($delta['events'] as $event) {
            $items = $event['items'];
            $texts = array_map(static fn (array $item) => $item['text'], $items);

            if ('PROJEKTE' === $event['type']) {
                $added = array_values(array_filter($texts, static fn (string $t) => !str_starts_with($t, '-')));
                $gone = array_values(array_map(static fn (string $t) => substr($t, 1), array_filter($texts, static fn (string $t) => str_starts_with($t, '-'))));
                $parts = [];
                if ([] !== $added) {
                    $parts[] = '+ ' . self::cap($added, ', ');
                }
                if ([] !== $gone) {
                    $parts[] = '- ' . self::cap($gone, ', ');
                }
                $lines[] = 'PROJEKTE: ' . implode(' | ', $parts);
                continue;
            }

            $glue = 'UPDATE NEU' === $event['type'] ? ' | ' : ', ';
            $lines[] = $event['type'] . ': ' . self::cap($texts, $glue)
                . (in_array($event['type'], ['BEFUND NEU', 'BEFUND BEHOBEN'], true) && $delta['rules_changed'] ? ' (Regeländerung)' : '');
        }

        return $lines;
    }

    /**
     * Zeilen für den Bericht: Ereignis, Projekt, Änderung.
     *
     * @param array{mode: string, since: ?string, rules_changed: bool, events: list<array{type: string, items: list<array{text: string, domain: string, change: string}>}>} $delta
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function rows(array $delta): array
    {
        $rows = [];
        foreach ($delta['events'] as $event) {
            foreach ($event['items'] as $item) {
                $change = $item['change'];
                if ($delta['rules_changed'] && in_array($event['type'], ['BEFUND NEU', 'BEFUND BEHOBEN'], true)) {
                    $change .= ' (Regeländerung)';
                }
                $rows[] = [$event['type'], $item['domain'], $change];
            }
        }

        return $rows;
    }

    /**
     * Folgezustand je Projekt (bei jedem Lauf): Beginn der Fehlerserie und letzter Erfolg.
     * Beginnt eine Fehlerserie, gilt der letzte bekannte Erfolg als Beginn (sonst der Beginn des Monitorings).
     *
     * @param array<string, mixed> $project
     * @param array{first_failed_at: ?string, last_success_at: ?string}|null $state
     * @return array{first_failed_at: ?string, last_success_at: ?string}
     */
    public static function nextState(array $project, ?array $state, DateTimeImmutable $now): array
    {
        $lastSuccess = $project['last_success'] ?: ($state['last_success_at'] ?? null);

        if ('error' !== $project['state']) {
            return ['first_failed_at' => null, 'last_success_at' => $lastSuccess];
        }

        return [
            'first_failed_at' => $state['first_failed_at'] ?? ($lastSuccess ?: $now->format('Y-m-d H:i:s')),
            'last_success_at' => $lastSuccess,
        ];
    }

    /**
     * "seit 842 Tagen (letzter Erfolg 2024-06-20)" bzw. "seit Beginn des Monitorings (nie erfolgreich)".
     *
     * @param array{first_failed_at: ?string, last_success_at: ?string} $state
     */
    public static function errorText(array $state, DateTimeImmutable $now): string
    {
        if (!$state['last_success_at']) {
            return 'seit Beginn des Monitorings (nie erfolgreich)';
        }

        $first = new DateTimeImmutable($state['first_failed_at'] ?: $state['last_success_at']);
        $days = max(0, (int) floor(($now->getTimestamp() - $first->getTimestamp()) / 86400));

        return sprintf('seit %d %s (letzter Erfolg %s)', $days, 1 === $days ? 'Tag' : 'Tagen', substr($state['last_success_at'], 0, 10));
    }

    /**
     * Gesehene Syslog-Einträge um die aktuellen Gruppen ergänzen (neu: first_seen = jetzt; bekannt: last_seen/count).
     * Nur Befund-Gruppen (die Ausnahmeliste hat der Collector bereits angewendet).
     *
     * @param array<string, array<string, mixed>> $seen Schlüssel "project_key|hash"
     * @param list<array<string, mixed>> $projects
     * @return array{seen: array<string, array<string, mixed>>, upserts: list<array<string, mixed>>}
     */
    public static function mergeSeen(array $seen, array $projects, DateTimeImmutable $now): array
    {
        $at = $now->format('Y-m-d H:i:s');
        $upserts = [];

        foreach ($projects as $project) {
            if ('error' === $project['state']) {
                continue;
            }
            foreach ($project['syslog'] as $group) {
                $hash = self::hash($group);
                $key = $project['domain'] . '|' . $hash;
                $row = $seen[$key] ?? [
                    'project_key' => $project['domain'],
                    'domain' => $project['domain'],
                    'hash' => $hash,
                    'first_seen' => $at,
                ];
                $row['type'] = $group['type'];
                $row['msg'] = $group['message'];
                $row['file'] = $group['file'];
                $row['line'] = $group['line'];
                $row['last_seen'] = $at;
                $row['count'] = $group['count'];
                $seen[$key] = $row;
                $upserts[] = $row;
            }
        }

        return ['seen' => $seen, 'upserts' => $upserts];
    }

    /**
     * Seit der Baseline erstmals gesehene Syslog-Einträge, nach der aktuellen Ausnahmeliste (Typ und Meldung).
     *
     * @param array<string, array<string, mixed>> $seen
     * @param list<string> $ignoreTypes
     * @param list<string> $ignoreMessages
     * @return list<array<string, mixed>>
     */
    public static function newSyslog(array $seen, ?string $reportedAt, array $ignoreTypes, array $ignoreMessages): array
    {
        if (null === $reportedAt) {
            return [];
        }

        $ignoreTypes = array_map('strtolower', $ignoreTypes);
        $new = [];
        foreach ($seen as $row) {
            if ((string) $row['first_seen'] <= $reportedAt || in_array(strtolower((string) $row['type']), $ignoreTypes, true)) {
                continue;
            }
            $haystack = $row['type'] . ' ' . $row['msg'] . ('' === (string) $row['file'] ? '' : ' ' . $row['file'] . ('' === (string) $row['line'] ? '' : ':' . $row['line']));
            if (rex_project_manager_report_collector::matchesAny($haystack, $ignoreMessages)) {
                continue;
            }
            $new[] = $row;
        }
        usort($new, static fn (array $a, array $b) => strcasecmp($a['domain'], $b['domain']) ?: strcmp((string) $a['hash'], (string) $b['hash']));

        return $new;
    }

    /** sha1 aus "Meldung|Datei|Zeile" @param array{message: string, file: string, line: string} $group */
    public static function hash(array $group): string
    {
        return sha1($group['message'] . '|' . $group['file'] . '|' . $group['line']);
    }

    /**
     * Fingerabdruck der Regeln, die Befunde bestimmen (Mindestversionen, Rückstand, Overrides).
     *
     * @param array<string, mixed> $settings
     */
    public static function rulesFingerprint(array $settings): string
    {
        $overrides = $settings['overrides'] ?? [];
        ksort($overrides);

        return sha1((string) json_encode([
            $settings['min_rex'] ?? null,
            $settings['min_php'] ?? null,
            $settings['min_mariadb'] ?? null,
            $settings['min_mysql'] ?? null,
            $settings['max_patch_lag'] ?? null,
            $overrides,
        ]));
    }

    /**
     * Zeilen für die Tabelle reported: aktueller Stand eines Mailtyps.
     *
     * @param list<array<string, mixed>> $projects
     * @return list<array{mail_type: string, project_key: string, domain: string, status: string, payload: string, reported_at: string}>
     */
    public static function snapshot(string $mailType, array $projects, DateTimeImmutable $at): array
    {
        $rows = [];
        foreach ($projects as $project) {
            $ok = 'error' !== $project['state'];
            $rows[] = [
                'mail_type' => $mailType,
                'project_key' => $project['domain'],
                'domain' => $project['domain'],
                'status' => $ok ? 'ok' : 'error',
                'payload' => $ok ? (string) json_encode(rex_project_manager_report_collector::payload($project), JSON_UNESCAPED_UNICODE) : '',
                'reported_at' => $at->format('Y-m-d H:i:s'),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, string> $before Addon => Version
     * @param array<string, string> $after
     * @return list<string>
     */
    private static function addonChanges(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $name => $version) {
            if (!isset($before[$name])) {
                $changes[] = sprintf('%s neu installiert %s', $name, $version);
            } elseif ($before[$name] !== $version) {
                $changes[] = sprintf('%s %s -> %s', $name, $before[$name], $version);
            }
        }
        foreach (array_diff_key($before, $after) as $name => $version) {
            $changes[] = $name . ' entfernt';
        }

        return $changes;
    }

    /**
     * Befunde aus Mindestversion und Rückstand eines bewerteten Projekts.
     *
     * @param array<string, mixed> $project
     * @return array<string, string> Schlüssel => Text
     */
    private static function findings(array $project): array
    {
        $findings = [];
        foreach ($project['violations'] as $violation) {
            $findings['below_min ' . $violation['key']] = 'below_min ' . $violation['key'];
        }
        if (null !== $project['behind']) {
            $findings['behind'] = sprintf('behind php %s<%s', $project['behind']['actual'], $project['behind']['max']);
        }

        return $findings;
    }

    /** "10.11.14-MariaDB" bzw. "unbekannt" @param array<string, mixed> $project */
    private static function db(array $project): string
    {
        return null === ($project['db_version'] ?? null) ? 'unbekannt' : $project['db_version'] . '-' . $project['db_engine'];
    }

    /** @return array{text: string, domain: string, change: string} */
    private static function item(string $domain, string $change): array
    {
        return ['text' => $domain . ' ' . $change, 'domain' => $domain, 'change' => $change];
    }

    /** @param list<string> $texts */
    private static function cap(array $texts, string $glue): string
    {
        if (count($texts) <= self::MAX_ITEMS) {
            return implode($glue, $texts);
        }

        return implode($glue, array_slice($texts, 0, self::MAX_ITEMS)) . $glue . '+' . (count($texts) - self::MAX_ITEMS) . ' weitere';
    }
}
