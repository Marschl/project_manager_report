<?php

// Zustandsspeicher des Delta-Abgleichs (nur Rohdaten und Zeiten); idempotent
rex_sql_table::get(rex::getTable('project_manager_report_reported'))
    ->ensureColumn(new rex_sql_column('mail_type', 'varchar(10)'))
    ->ensureColumn(new rex_sql_column('project_key', 'varchar(255)'))
    ->ensureColumn(new rex_sql_column('domain', 'varchar(255)'))
    ->ensureColumn(new rex_sql_column('status', 'varchar(10)'))
    ->ensureColumn(new rex_sql_column('payload', 'longtext', true))
    ->ensureColumn(new rex_sql_column('reported_at', 'datetime'))
    ->setPrimaryKey(['mail_type', 'project_key'])
    ->ensure();

rex_sql_table::get(rex::getTable('project_manager_report_state'))
    ->ensureColumn(new rex_sql_column('project_key', 'varchar(255)'))
    ->ensureColumn(new rex_sql_column('first_failed_at', 'datetime', true))
    ->ensureColumn(new rex_sql_column('last_success_at', 'datetime', true))
    ->ensureColumn(new rex_sql_column('monitoring_pausiert', 'tinyint(1)', false, '0'))
    ->ensureColumn(new rex_sql_column('pause_grund', 'varchar(255)', true))
    ->setPrimaryKey('project_key')
    ->ensure();

rex_sql_table::get(rex::getTable('project_manager_report_syslog_seen'))
    ->ensureColumn(new rex_sql_column('project_key', 'varchar(255)'))
    ->ensureColumn(new rex_sql_column('hash', 'varchar(40)'))
    ->ensureColumn(new rex_sql_column('type', 'varchar(100)'))
    ->ensureColumn(new rex_sql_column('msg', 'text'))
    ->ensureColumn(new rex_sql_column('file', 'varchar(500)'))
    ->ensureColumn(new rex_sql_column('line', 'varchar(20)'))
    ->ensureColumn(new rex_sql_column('first_seen', 'datetime'))
    ->ensureColumn(new rex_sql_column('last_seen', 'datetime'))
    ->ensureColumn(new rex_sql_column('count', 'int(10) unsigned', false, '1'))
    ->setPrimaryKey(['project_key', 'hash'])
    ->ensureIndex(new rex_sql_index('last_seen', ['last_seen']))
    ->ensure();

// Hash enthält seit 1.4.4 auch den Typ (rex_project_manager_report_delta::hash()): bestehende Zeilen umrechnen,
// damit bekannte Einträge nicht erneut als "SYSLOG NEU" erscheinen; idempotent
$seenHash = "SHA1(CONCAT(`type`, '|', `msg`, '|', `file`, '|', `line`))";
rex_sql::factory()->setQuery('UPDATE ' . rex::getTable('project_manager_report_syslog_seen') . ' SET `hash` = ' . $seenHash . ' WHERE `hash` <> ' . $seenHash);

// Overrides der Mindestversionen je Projekt (leer = globaler Wert); idempotent
rex_sql_table::get(rex::getTable('project_manager_report_override'))
    ->ensureColumn(new rex_sql_column('domain', 'varchar(255)'))
    ->ensureColumn(new rex_sql_column('min_rex', 'varchar(20)', true))
    ->ensureColumn(new rex_sql_column('min_php', 'varchar(20)', true))
    ->ensureColumn(new rex_sql_column('min_mariadb', 'varchar(20)', true))
    ->ensureColumn(new rex_sql_column('min_mysql', 'varchar(20)', true))
    ->setPrimaryKey('domain')
    ->ensure();

// Cronjob-Zeile einmalig anlegen (Neuinstallation legt keine zweite an).
// Inaktiv, bis in der Cronjob-Oberfläche Empfänger eingetragen sind.
$type = 'rex_cronjob_project_manager_report';
$table = rex::getTable('cronjob');

$exists = rex_sql::factory()->getArray('SELECT id, parameters FROM ' . $table . ' WHERE type = ?', [$type]);

// Einmalige Übernahme der Cronjob-Parameter (bis 1.2) in die Addon-Einstellungen.
// Vorhandene Einstellungen werden nie überschrieben; danach hat der Cronjob keine eigenen Parameter mehr.
if ([] !== $exists) {
    $params = json_decode((string) $exists[0]['parameters'], true);
    $moved = false;
    foreach (['recipients', 'recipients_report', 'stale_hours', 'syslog_ignore', 'syslog_ignore_messages'] as $key) {
        if (is_array($params) && array_key_exists($type . '_' . $key, $params)) {
            if (!$this->hasConfig($key)) {
                $this->setConfig($key, trim((string) $params[$type . '_' . $key]));
            }
            $moved = true;
        }
    }
    if ($moved) {
        rex_sql::factory()->setQuery('UPDATE ' . $table . ' SET parameters = ? WHERE id = ?', ['{}', $exists[0]['id']]);
    }
}

if ([] === $exists) {
    $interval = ['minutes' => [30], 'hours' => [6], 'days' => 'all', 'weekdays' => 'all', 'months' => 'all'];
    $now = date('Y-m-d H:i:s');
    $user = rex::getUser() ? rex::getUser()->getLogin() : 'console';

    rex_sql::factory()
        ->setTable($table)
        ->setValue('name', 'Projekt Manager: Status-Mail')
        ->setValue('description', '')
        ->setValue('type', $type)
        ->setValue('parameters', '{}')
        ->setValue('interval', json_encode($interval))
        ->setValue('nexttime', date('Y-m-d H:i:s', rex_cronjob_manager_sql::calculateNextTime($interval)))
        ->setValue('environment', '|script|')
        ->setValue('execution_moment', 0)
        ->setValue('execution_start', '1970-01-01 01:00:00')
        ->setValue('status', 0)
        ->setValue('createdate', $now)
        ->setValue('createuser', $user)
        ->setValue('updatedate', $now)
        ->setValue('updateuser', $user)
        ->insert();

    $this->setProperty('successmsg', 'Cronjob „Projekt Manager: Status-Mail“ angelegt (inaktiv): Empfänger eintragen und aktivieren.');
}
