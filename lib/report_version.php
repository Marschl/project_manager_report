<?php

/**
 * Normalisierung und Vergleich von Versionsangaben (PHP, REDAXO, Datenbank).
 * Reine Funktionen ohne REDAXO-Aufrufe.
 */
class rex_project_manager_report_version
{
    /** Erlaubtes Format für Mindestversionen: x.y oder x.y.z */
    public const PATTERN = '/^\d+(\.\d+){1,2}$/';

    public static function isValid(string $version): bool
    {
        return 1 === preg_match(self::PATTERN, $version);
    }

    /**
     * Eingabe aus Einstellungen: leer oder ungültig = null (Regel aus).
     */
    public static function setting(mixed $value): ?string
    {
        $value = trim((string) $value);

        return self::isValid($value) ? $value : null;
    }

    /**
     * PHP: alles ab dem ersten "-" oder "+" abschneiden ("8.4.24-nmm1" → 8.4.24).
     *
     * @return array{full: string, line: string, patch: int}|null
     */
    public static function php(mixed $raw): ?array
    {
        if (!is_string($raw)) {
            return null;
        }
        $clean = preg_split('/[-+]/', trim($raw), 2)[0];
        if (1 !== preg_match('/^(\d+)\.(\d+)(?:\.(\d+))?/', $clean, $m)) {
            return null;
        }
        $patch = (int) ($m[3] ?? 0);

        return ['full' => $m[1] . '.' . $m[2] . '.' . $patch, 'line' => $m[1] . '.' . $m[2], 'patch' => $patch];
    }

    /** REDAXO: führende Versionsnummer ("5.21.4"). */
    public static function rex(mixed $raw): ?string
    {
        return is_string($raw) && 1 === preg_match('/^\d+(?:\.\d+)+/', trim($raw), $m) ? $m[0] : null;
    }

    /**
     * Datenbank: Engine am String erkennen (MariaDB, sonst MySQL), Version bereinigen.
     * "5.5.5-" ist das Replikations-Präfix von MariaDB: folgt danach eine Version, gilt diese,
     * sonst ist die Version unbekannt (null). Suffixe wie "-0ubuntu0.24.04.1-log" fallen weg.
     *
     * @return array{engine: string, version: ?string, line: ?string}
     */
    public static function database(mixed $raw): array
    {
        $raw = is_string($raw) ? trim($raw) : '';
        $engine = false !== stripos($raw, 'mariadb') ? 'MariaDB' : 'MySQL';

        $rest = 1 === preg_match('/^5\.5\.5-(.*)$/', $raw, $m) ? $m[1] : $raw;
        if (1 !== preg_match('/^(\d+)\.(\d+)(?:\.(\d+))?/', $rest, $v)) {
            return ['engine' => $engine, 'version' => null, 'line' => null];
        }

        return ['engine' => $engine, 'version' => $v[0], 'line' => $v[1] . '.' . $v[2]];
    }

    /** "8.3.2" → "8.3" */
    public static function line(string $version): string
    {
        $parts = explode('.', $version);

        return $parts[0] . '.' . ($parts[1] ?? '0');
    }

    /** Liegt die Linie (major.minor) von $actual unter der Linie von $min? */
    public static function lineBelow(string $actual, string $min): bool
    {
        return version_compare(self::line($actual), self::line($min), '<');
    }

    /** Liegt $actual (volle Version) unter $min? */
    public static function below(string $actual, string $min): bool
    {
        return version_compare($actual, $min, '<');
    }
}
