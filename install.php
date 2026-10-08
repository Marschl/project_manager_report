<?php

// Cronjob-Zeile einmalig anlegen (Neuinstallation legt keine zweite an).
// Inaktiv, bis in der Cronjob-Oberfläche Empfänger eingetragen sind.
$type = 'rex_cronjob_project_manager_report';
$table = rex::getTable('cronjob');

$exists = rex_sql::factory()->getArray('SELECT id FROM ' . $table . ' WHERE type = ?', [$type]);

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
