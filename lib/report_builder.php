<?php

/**
 * Baut aus den Projekt-Datensätzen des Collectors zwei Mails:
 *
 *  - buildMachine(): knapp, streng strukturiert, Plain Text (für Auswertung durch ein Programm oder einen KI-Agenten):
 *    nur Kopf und Inventar, keine Detail-Blöcke (Tokenkosten); Details stehen im Bericht
 *  - buildReport():  ausführlich, lesbar, HTML mit Text-Alternative (für Menschen)
 *
 * Befund = FEHLER, VERALTET, unterschrittene Mindestversion (REDAXO, PHP, Datenbank), Patch-Rückstand
 * in der PHP-Linie oder mindestens ein nicht ignorierter Syslog-Eintrag.
 * Addon-Updates sind kein Befund; sie stehen im Inventar bzw. im Bericht.
 *
 * Reine Aufbereitung: kein SQL, kein Versand.
 */
class rex_project_manager_report_builder
{
    /** Spaltenüberschrift des Inventars; neue Spalten werden rechts angefügt (hier und in inventory()) */
    public const INVENTORY_HEADER = '# domain | rex | php | db | addon_updates | syslog | below_min | behind | status | last_ok';

    /**
     * Zahlen für Betreff und Kopfzeile beider Mails.
     *
     * @param list<array<string, mixed>> $projects
     * @return array{projects: int, ok: int, errors: int, stale: int, findings: int, below_min: int, behind: int, update_projects: int, updates: int}
     */
    public static function summary(array $projects): array
    {
        $errors = 0;
        $stale = 0;
        $findings = 0;
        $belowMin = 0;
        $behind = 0;
        $updateProjects = 0;
        $updates = 0;

        foreach ($projects as $project) {
            if ('error' === $project['state']) {
                ++$errors;
            }
            if ('stale' === $project['state']) {
                ++$stale;
            }
            if (self::hasFinding($project)) {
                ++$findings;
            }
            if ([] !== $project['violations']) {
                ++$belowMin;
            }
            if (null !== $project['behind']) {
                ++$behind;
            }
            if ([] !== $project['updates']) {
                ++$updateProjects;
                $updates += count($project['updates']);
            }
        }

        return [
            'projects' => count($projects),
            'ok' => count($projects) - $errors,
            'errors' => $errors,
            'stale' => $stale,
            'findings' => $findings,
            'below_min' => $belowMin,
            'behind' => $behind,
            'update_projects' => $updateProjects,
            'updates' => $updates,
        ];
    }

    /** @param array<string, mixed> $project */
    public static function hasFinding(array $project): bool
    {
        return 'ok' !== $project['state'] || [] !== $project['violations'] || null !== $project['behind'] || [] !== $project['syslog'];
    }

    /**
     * Knappe, maschinenlesbare Mail.
     *
     * @param list<array<string, mixed>> $projects Datensätze von rex_project_manager_report_collector
     * @return array{subject: string, body: string, projects: int, findings: int, errors: int}
     */
    public static function buildMachine(array $projects, DateTimeImmutable $now): array
    {
        $projects = self::sorted($projects);
        $summary = self::summary($projects);

        $parts = [
            sprintf(
                "Lauf: %s | Abgefragt: %d | OK: %d | Fehler: %d | Mit Befund: %d | Ältester Abruf: %s\nSYSLOG_IGNORIERT: %d",
                $now->format('Y-m-d H:i'),
                $summary['projects'],
                $summary['ok'],
                $summary['errors'],
                $summary['findings'],
                self::oldestFetch($projects),
                array_sum(array_map(static fn (array $p) => array_sum($p['ignored']), $projects)),
            ),
        ];
        if ([] !== $projects) {
            $parts[] = implode("\n", array_merge([self::INVENTORY_HEADER], array_map(self::inventory(...), $projects)));
        }

        return [
            'subject' => sprintf('[Projekt Manager - Data] %s | %d Projekte | %d mit Befund | %d Fehler', $now->format('Y-m-d'), $summary['projects'], $summary['findings'], $summary['errors']),
            'body' => implode("\n\n", $parts) . "\n",
            'projects' => $summary['projects'],
            'findings' => $summary['findings'],
            'errors' => $summary['errors'],
        ];
    }

    /**
     * Ausführlicher Bericht für Menschen (Text und HTML).
     *
     * @param list<array<string, mixed>> $projects
     * @return array{subject: string, text: string, html: string, data: array<string, mixed>, projects: int, findings: int, errors: int}
     */
    public static function buildReport(array $projects, DateTimeImmutable $now, bool $withHtml = true): array
    {
        $projects = self::sorted($projects);
        $summary = self::summary($projects);

        $attention = array_values(array_filter($projects, self::hasFinding(...)));
        // FEHLER und VERALTET vor Projekten mit Syslog-Befund (stabile Reihenfolge, Eingabe ist alphabetisch)
        usort($attention, static fn (array $a, array $b) => ('ok' === $a['state']) <=> ('ok' === $b['state']));

        $data = [
            'now' => $now->format('Y-m-d H:i'),
            'date' => $now->format('Y-m-d'),
            'summary' => $summary,
            'attention' => $attention,
            'updates' => array_values(array_filter($projects, static fn (array $p) => [] !== $p['updates'])),
            'notes' => array_values(array_filter($projects, static fn (array $p) => [] !== $p['ignored'])),
            'projects' => $projects,
            'status' => array_combine(array_column($projects, 'domain'), array_map(self::status(...), $projects)),
        ];

        return [
            'subject' => sprintf('[Projekt Manager - Report] %s | %d mit Befund | %d Fehler | %d Addon-Updates', $data['date'], $summary['findings'], $summary['errors'], $summary['updates']),
            'text' => self::reportText($data),
            'html' => $withHtml ? self::reportHtml($data) : '',
            'data' => $data,
            'projects' => $summary['projects'],
            'findings' => $summary['findings'],
            'errors' => $summary['errors'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $projects
     * @return list<array<string, mixed>>
     */
    private static function sorted(array $projects): array
    {
        usort($projects, static fn (array $a, array $b) => strcasecmp($a['domain'], $b['domain']));

        return $projects;
    }

    /**
     * Unterschrittene Mindestversionen in fester Reihenfolge: PHP, REDAXO, Datenbank.
     *
     * @param array<string, mixed> $project
     * @return list<array{key: string, label: string, min: string}>
     */
    public static function violations(array $project): array
    {
        $order = ['php' => 0, 'rex' => 1, 'db' => 2];
        $violations = $project['violations'];
        usort($violations, static fn (array $a, array $b) => $order[$a['key']] <=> $order[$b['key']]);

        return $violations;
    }

    /** @param array{label: string} $violation */
    public static function violationLabel(array $violation): string
    {
        return $violation['label'];
    }

    /** "PHP 8.4.12 < 8.4.26 (14 Patches)" @param array<string, mixed> $project */
    public static function behindLine(array $project): string
    {
        $b = $project['behind'];

        return sprintf('PHP %s < %s (%d Patches)', $b['actual'], $b['max'], $b['lag']);
    }

    /**
     * Älteste Abrufzeit über alle Projekte mit Log ("-" wenn keines).
     *
     * @param list<array<string, mixed>> $projects
     */
    private static function oldestFetch(array $projects): string
    {
        $times = array_filter(array_column($projects, 'last_success'));

        return [] === $times ? '-' : self::minute(min($times));
    }

    /**
     * Schlüssel der unterschrittenen Mindestversionen (alphabetisch: db, php, rex).
     *
     * @param array<string, mixed> $project
     * @return list<string>
     */
    public static function belowMin(array $project): array
    {
        $keys = array_column($project['violations'], 'key');
        sort($keys);

        return $keys;
    }

    /** @param array<string, mixed> $project */
    private static function errorLine(array $project): string
    {
        return $project['last_success']
            ? 'FEHLER: Abruf fehlgeschlagen, letzter Erfolg ' . self::minute($project['last_success'])
            : 'FEHLER: Abruf fehlgeschlagen, bisher kein erfolgreicher Abruf';
    }

    /** @param array<string, mixed> $project */
    private static function staleLine(array $project): string
    {
        return 'VERALTET: letzter Erfolg ' . self::minute($project['last_success']);
    }

    /** @param array{type: string, message: string, file: string, line: string, count: int} $group */
    public static function syslogGroup(array $group): string
    {
        return sprintf('%s "%s"%s x%d', $group['type'], $group['message'], self::where($group), $group['count']);
    }

    /** @param array{file: string, line: string} $group */
    public static function where(array $group): string
    {
        return '' === $group['file'] ? '' : sprintf(' (%s%s)', $group['file'], '' === $group['line'] ? '' : ':' . $group['line']);
    }

    /**
     * Inventarzeile (Teil 3): feste Spalten, "-" wenn keine Daten vorliegen.
     * addon_updates nennt die Addons mit Update (kommagetrennt, leer = keine), below_min die unterschrittenen Mindestversionen (db, php, rex), behind den PHP-Rückstand (php:ist<max),
     * status ok|stale|error, last_ok den letzten erfolgreichen Abruf.
     *
     * @param array<string, mixed> $project
     */
    private static function inventory(array $project): string
    {
        if ('error' === $project['state']) {
            return $project['domain'] . ' | - | - | - | addon_updates=- | syslog=- | below_min=- | behind=- | status=error | last_ok=' . self::lastOk($project);
        }

        // Namen der Addons mit Update, kommagetrennt (leer = keine); Trennzeichen des Zeilenformats aus Namen entfernen
        $updates = implode(',', array_map(
            static fn (array $update) => (string) preg_replace('/[\s,|=]+/', '_', $update['name']),
            $project['updates'],
        ));

        return sprintf(
            '%s | %s | %s | %s | addon_updates=%s | syslog=%d | below_min=%s | behind=%s | status=%s | last_ok=%s',
            $project['domain'],
            $project['rex'] ?? '-',
            $project['php'] ?? '-',
            $project['db'],
            $updates,
            array_sum(array_column($project['syslog'], 'count')),
            implode(',', self::belowMin($project)),
            null === $project['behind'] ? '' : sprintf('php:%s<%s', $project['behind']['actual'], $project['behind']['max']),
            $project['state'],
            self::lastOk($project),
        );
    }

    /** @param array<string, mixed> $project */
    private static function lastOk(array $project): string
    {
        return $project['last_success'] ? self::minute($project['last_success']) : '-';
    }

    /**
     * OK | BEFUND | VERALTET | FEHLER (für den Bericht)
     *
     * @param array<string, mixed> $project
     */
    private static function status(array $project): string
    {
        return match (true) {
            'error' === $project['state'] => 'FEHLER',
            'stale' === $project['state'] => 'VERALTET',
            [] !== $project['violations'] || null !== $project['behind'] || [] !== $project['syslog'] => 'BEFUND',
            default => 'OK',
        };
    }

    /** @param array<string, mixed> $data */
    private static function reportText(array $data): string
    {
        $s = $data['summary'];
        $out = [];

        $out[] = 'PROJEKT MANAGER – STATUSBERICHT';
        $out[] = 'Stand: ' . $data['now'];
        $out[] = '';
        $out[] = 'ZUSAMMENFASSUNG';
        $out[] = sprintf(
            '%d Projekte abgefragt: %d OK, %d Fehler, %d mit Befund. Veraltet: %d, unter Mindestversion: %d, im Rückstand: %d. %d Addon-Updates in %d Projekten (kein Befund).',
            $s['projects'],
            $s['ok'],
            $s['errors'],
            $s['findings'],
            $s['stale'],
            $s['below_min'],
            $s['behind'],
            $s['updates'],
            $s['update_projects'],
        );
        $out[] = '';

        $out[] = 'HANDLUNGSBEDARF';
        if ([] === $data['attention']) {
            $out[] = 'Keine Befunde.';
        }
        foreach ($data['attention'] as $project) {
            $out[] = '';
            $out[] = $project['domain'];
            if ('error' === $project['state']) {
                $out[] = '  ' . self::errorLine($project);

                continue;
            }
            if ('stale' === $project['state']) {
                $out[] = '  ' . self::staleLine($project);
            }
            $out[] = '  ' . self::versions($project);
            foreach (self::violations($project) as $violation) {
                $out[] = '  Mindestversion: ' . $violation['label'] . ' liegt unter ' . $violation['min'];
            }
            if (null !== $project['behind']) {
                $out[] = '  Rückstand: ' . self::behindLine($project) . ', Flottenmaximum der PHP-Linie ' . $project['behind']['line'];
            }
            foreach ($project['syslog'] as $group) {
                $out[] = '  Syslog 24h: ' . self::syslogGroup($group);
            }
        }
        $out[] = '';

        $out[] = 'ADDON-UPDATES (kein Befund)';
        if ([] === $data['updates']) {
            $out[] = 'Keine Updates gemeldet.';
        }
        foreach ($data['updates'] as $project) {
            $out[] = $project['domain'];
            foreach ($project['updates'] as $u) {
                $out[] = sprintf('  %s %s -> %s', $u['name'], $u['current'], $u['latest']);
            }
        }
        $out[] = '';

        $out[] = 'SYSLOG OHNE BEFUND (letzte 24 Stunden, ignorierte Typen und Meldungen)';
        if ([] === $data['notes']) {
            $out[] = 'Keine Einträge.';
        }
        foreach ($data['notes'] as $project) {
            $out[] = $project['domain'] . ': ' . implode(', ', array_map(
                static fn (string $type, int $count) => $type . ' x' . $count,
                array_keys($project['ignored']),
                $project['ignored'],
            ));
        }
        $out[] = '';

        $out[] = 'ALLE PROJEKTE';
        $rows = [['Domain', 'REDAXO', 'PHP', 'Datenbank', 'Updates', 'Syslog', 'Letzter Abruf', 'Status']];
        foreach ($data['projects'] as $project) {
            $below = self::belowMin($project);
            $rows[] = [
                $project['domain'],
                ($project['rex'] ?? '-') . (in_array('rex', $below, true) ? '!' : ''),
                ($project['php'] ?? '-') . (in_array('php', $below, true) ? '!' : '') . (null !== $project['behind'] ? '~' : ''),
                $project['db_label'] . (in_array('db', $below, true) ? '!' : ''),
                'error' === $project['state'] ? '-' : (string) count($project['updates']),
                'error' === $project['state'] ? '-' : (string) array_sum(array_column($project['syslog'], 'count')),
                $project['last_success'] ? self::minute($project['last_success']) : '-',
                $data['status'][$project['domain']],
            ];
        }
        $out[] = self::table($rows);
        if ($s['below_min'] > 0 || $s['behind'] > 0) {
            $out[] = '! = unter Mindestversion, ~ = Patch-Rückstand';
        }

        return implode("\n", $out) . "\n";
    }

    /** @param array<string, mixed> $data */
    private static function reportHtml(array $data): string
    {
        $fragment = new rex_fragment();
        $fragment->setVar('data', $data, false);

        return $fragment->parse('project_manager_report/report.php');
    }

    /** @param array<string, mixed> $project */
    public static function versions(array $project): string
    {
        return sprintf('REDAXO %s | PHP %s | %s', $project['rex'] ?? '-', $project['php'] ?? '-', $project['db_label'] ?? '-');
    }

    /**
     * Ausgerichtete Textspalten.
     *
     * @param list<list<string>> $rows erste Zeile = Kopf
     */
    private static function table(array $rows): string
    {
        $widths = [];
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, mb_strlen($cell));
            }
        }

        $lines = [];
        foreach ($rows as $n => $row) {
            $cells = [];
            foreach ($row as $i => $cell) {
                $cells[] = $cell . str_repeat(' ', $widths[$i] - mb_strlen($cell));
            }
            $lines[] = rtrim(implode('  ', $cells));
            if (0 === $n) {
                $lines[] = str_repeat('-', array_sum($widths) + 2 * (count($widths) - 1));
            }
        }

        return implode("\n", $lines);
    }

    /** "2026-10-08 00:00:12" -> "2026-10-08 00:00" */
    public static function minute(string $datetime): string
    {
        return substr($datetime, 0, 16);
    }
}
