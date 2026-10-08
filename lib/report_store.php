<?php

/**
 * Zustandsspeicher des Delta-Abgleichs (eigene Tabellen des Addons, nur Rohdaten und Zeiten).
 *
 *  - reported:    Stand der letzten erfolgreich versendeten Mail, getrennt je Mailtyp (data, report)
 *  - state:       fortlaufender Zustand je Projekt (Fehlerserie, letzter Erfolg, Pause)
 *  - syslog_seen: gesehene Syslog-Einträge (Hash aus Meldung, Datei, Zeile)
 */
class rex_project_manager_report_store
{
    public const MAIL_TYPES = ['data', 'report'];
    public const SEEN_RETENTION_DAYS = 90;

    private static function table(string $name): string
    {
        return rex::getTable('project_manager_report_' . $name);
    }

    public static function exists(): bool
    {
        foreach (['reported', 'state', 'syslog_seen'] as $name) {
            if (!rex_sql_table::get(self::table($name))->exists()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<array{domain: string, status: string, payload: ?array<string, mixed>, reported_at: string}>
     */
    public static function loadReported(string $mailType): array
    {
        $rows = [];
        foreach (rex_sql::factory()->getArray('SELECT project_key, domain, status, payload, reported_at FROM ' . self::table('reported') . ' WHERE mail_type = ? ORDER BY domain', [$mailType]) as $row) {
            $payload = '' === (string) $row['payload'] ? null : json_decode((string) $row['payload'], true);
            $rows[] = [
                'domain' => (string) $row['domain'],
                'status' => (string) $row['status'],
                'payload' => is_array($payload) ? $payload : null,
                'reported_at' => (string) $row['reported_at'],
            ];
        }

        return $rows;
    }

    /**
     * Stand eines Mailtyps vollständig ersetzen (eine Transaktion).
     *
     * @param list<array<string, string>> $rows siehe rex_project_manager_report_delta::snapshot()
     */
    public static function replaceReported(string $mailType, array $rows): void
    {
        rex_sql::factory()->transactional(static function () use ($mailType, $rows): void {
            rex_sql::factory()->setQuery('DELETE FROM ' . self::table('reported') . ' WHERE mail_type = ?', [$mailType]);
            foreach ($rows as $row) {
                rex_sql::factory()->setTable(self::table('reported'))->setValues($row)->insert();
            }
        });
    }

    /** Fingerabdruck der Regeln beim letzten erfolgreichen Versand eines Mailtyps. */
    public static function rules(string $mailType): ?string
    {
        $value = rex_addon::get('project_manager_report')->getConfig('reported_rules_' . $mailType);

        return is_string($value) && '' !== $value ? $value : null;
    }

    public static function setRules(string $mailType, string $fingerprint): void
    {
        rex_addon::get('project_manager_report')->setConfig('reported_rules_' . $mailType, $fingerprint);
    }

    /**
     * @return array<string, array{first_failed_at: ?string, last_success_at: ?string, monitoring_pausiert: bool, pause_grund: string}>
     */
    public static function loadState(): array
    {
        $state = [];
        foreach (rex_sql::factory()->getArray('SELECT project_key, first_failed_at, last_success_at, monitoring_pausiert, pause_grund FROM ' . self::table('state')) as $row) {
            $state[(string) $row['project_key']] = [
                'first_failed_at' => $row['first_failed_at'] ?: null,
                'last_success_at' => $row['last_success_at'] ?: null,
                'monitoring_pausiert' => (bool) $row['monitoring_pausiert'],
                'pause_grund' => (string) $row['pause_grund'],
            ];
        }

        return $state;
    }

    /** Fehlerserie und letzter Erfolg schreiben; die Pause bleibt unberührt. @param array{first_failed_at: ?string, last_success_at: ?string} $next */
    public static function saveState(string $domain, array $next): void
    {
        rex_sql::factory()->setTable(self::table('state'))
            ->setValue('project_key', $domain)
            ->setValue('first_failed_at', $next['first_failed_at'])
            ->setValue('last_success_at', $next['last_success_at'])
            ->insertOrUpdate();
    }

    public static function setPause(string $domain, bool $paused, string $reason): void
    {
        rex_sql::factory()->setTable(self::table('state'))
            ->setValue('project_key', $domain)
            ->setValue('monitoring_pausiert', $paused ? 1 : 0)
            ->setValue('pause_grund', '' === $reason ? null : $reason)
            ->insertOrUpdate();
    }

    /**
     * @return array<string, array<string, mixed>> Schlüssel "project_key|hash"
     */
    public static function loadSeen(): array
    {
        $seen = [];
        foreach (rex_sql::factory()->getArray('SELECT project_key, hash, type, msg, file, line, first_seen, last_seen, `count` FROM ' . self::table('syslog_seen')) as $row) {
            $row['domain'] = $row['project_key'];
            $row['count'] = (int) $row['count'];
            $seen[$row['project_key'] . '|' . $row['hash']] = $row;
        }

        return $seen;
    }

    /** @param list<array<string, mixed>> $rows siehe rex_project_manager_report_delta::mergeSeen() */
    public static function upsertSeen(array $rows): void
    {
        foreach ($rows as $row) {
            rex_sql::factory()->setTable(self::table('syslog_seen'))->setValues([
                'project_key' => $row['project_key'],
                'hash' => $row['hash'],
                'type' => $row['type'],
                'msg' => $row['msg'],
                'file' => $row['file'],
                'line' => $row['line'],
                'first_seen' => $row['first_seen'],
                'last_seen' => $row['last_seen'],
                'count' => $row['count'],
            ])->insertOrUpdate();
        }
    }

    /** Einträge, die länger als 90 Tage nicht mehr gesehen wurden, löschen. */
    public static function purgeSeen(DateTimeImmutable $now): int
    {
        $sql = rex_sql::factory();
        $sql->setQuery('DELETE FROM ' . self::table('syslog_seen') . ' WHERE last_seen < ?', [$now->modify('-' . self::SEEN_RETENTION_DAYS . ' days')->format('Y-m-d H:i:s')]);

        return $sql->getRows();
    }
}
