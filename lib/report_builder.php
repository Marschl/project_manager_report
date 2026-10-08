<?php

/**
 * Baut aus den Projekt-Datensätzen des Collectors zwei Mails:
 *
 *  - buildMachine(): knapp, streng strukturiert, Plain Text (für Auswertung durch ein Programm oder einen KI-Agenten)
 *  - buildReport():  ausführlich, lesbar, HTML mit Text-Alternative (für Menschen)
 *
 * Befund = FEHLER, VERALTET, unterschrittene Mindestversion (PHP/REDAXO, Einstellungen des Project Managers)
 * oder mindestens ein nicht ignorierter Syslog-Eintrag.
 * Addon-Updates sind kein Befund; sie stehen im Inventar bzw. im Bericht.
 *
 * Reine Aufbereitung: kein SQL, kein Versand.
 */
class rex_project_manager_report_builder
{
    /** Spaltenüberschrift des Inventars; neue Spalten werden rechts angefügt (hier und in inventory()) */
    public const INVENTORY_HEADER = '# domain | rex | php | db | addon_updates | syslog | below_min';

    /**
     * Zahlen für Betreff und Kopfzeile beider Mails.
     *
     * @param list<array<string, mixed>> $projects
     * @return array{projects: int, ok: int, errors: int, stale: int, findings: int, below_min: int, update_projects: int, updates: int}
     */
    public static function summary(array $projects): array
    {
        $errors = 0;
        $stale = 0;
        $findings = 0;
        $belowMin = 0;
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
            'update_projects' => $updateProjects,
            'updates' => $updates,
        ];
    }

    /** @param array<string, mixed> $project */
    public static function hasFinding(array $project): bool
    {
        return 'ok' !== $project['state'] || [] !== $project['violations'] || [] !== $project['syslog'];
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
        $blocks = ['priority' => [], 'normal' => []];

        foreach ($projects as $project) {
            $lines = self::findingLines($project);
            if ([] !== $lines) {
                $blocks['ok' === $project['state'] ? 'normal' : 'priority'][] = implode("\n", array_merge(['== ' . $project['domain'] . ' =='], $lines));
            }
        }

        $parts = [
            sprintf(
                'Lauf: %s | Abgefragt: %d | OK: %d | Fehler: %d | Mit Befund: %d',
                $now->format('Y-m-d H:i'),
                $summary['projects'],
                $summary['ok'],
                $summary['errors'],
                $summary['findings'],
            ),
        ];
        // Projekte mit FEHLER oder VERALTET zuerst, jeweils alphabetisch (die Eingabe ist bereits sortiert)
        foreach (array_merge($blocks['priority'], $blocks['normal']) as $block) {
            $parts[] = $block;
        }
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
     * Zeilen des Projektblocks in der knappen Mail (Teil 2): nur Befunde, keine Versionen, keine Updates.
     *
     * @param array<string, mixed> $project
     * @return list<string>
     */
    private static function findingLines(array $project): array
    {
        if ('error' === $project['state']) {
            return [self::errorLine($project)];
        }

        $lines = [];
        if ('stale' === $project['state']) {
            $lines[] = self::staleLine($project);
        }
        foreach ($project['violations'] as $violation) {
            $lines[] = 'Mindestversion: ' . self::violationLabel($violation) . ' < ' . $violation['min'];
        }
        foreach ($project['syslog'] as $group) {
            $lines[] = 'Syslog 24h: ' . self::syslogGroup($group);
        }

        return $lines;
    }

    /** @param array{key: string, actual: string} $violation */
    public static function violationLabel(array $violation): string
    {
        return ('php' === $violation['key'] ? 'PHP ' : 'REDAXO ') . $violation['actual'];
    }

    /**
     * Schlüssel der unterschrittenen Mindestversionen (php, rex).
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
     * addon_updates nennt die Addons mit Update (kommagetrennt, leer = keine), below_min die unterschrittenen Mindestversionen (php, rex).
     *
     * @param array<string, mixed> $project
     */
    private static function inventory(array $project): string
    {
        if ('error' === $project['state']) {
            return $project['domain'] . ' | - | - | - | addon_updates=- | syslog=- | below_min=-';
        }

        // Namen der Addons mit Update, kommagetrennt (leer = keine); Trennzeichen des Zeilenformats aus Namen entfernen
        $updates = implode(',', array_map(
            static fn (array $update) => (string) preg_replace('/[\s,|=]+/', '_', $update['name']),
            $project['updates'],
        ));

        return sprintf(
            '%s | %s | %s | %s | addon_updates=%s | syslog=%d | below_min=%s',
            $project['domain'],
            $project['rex'] ?? '-',
            $project['php'] ?? '-',
            $project['db'] ?? '-',
            $updates,
            array_sum(array_column($project['syslog'], 'count')),
            implode(',', self::belowMin($project)),
        );
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
            [] !== $project['violations'] || [] !== $project['syslog'] => 'BEFUND',
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
            '%d Projekte abgefragt: %d OK, %d Fehler, %d mit Befund (davon %d veraltet, %d unter Mindestversion). %d Addon-Updates in %d Projekten (kein Befund).',
            $s['projects'],
            $s['ok'],
            $s['errors'],
            $s['findings'],
            $s['stale'],
            $s['below_min'],
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
            foreach ($project['violations'] as $violation) {
                $out[] = '  Mindestversion: ' . self::violationLabel($violation) . ' liegt unter ' . $violation['min'];
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
                ($project['php'] ?? '-') . (in_array('php', $below, true) ? '!' : ''),
                $project['db_label'] ?? '-',
                'error' === $project['state'] ? '-' : (string) count($project['updates']),
                'error' === $project['state'] ? '-' : (string) array_sum(array_column($project['syslog'], 'count')),
                $project['last_success'] ? self::minute($project['last_success']) : '-',
                $data['status'][$project['domain']],
            ];
        }
        $out[] = self::table($rows);
        if ($s['below_min'] > 0) {
            $out[] = '! = unter Mindestversion';
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
