<?php

rex_sql::factory()->setQuery('DELETE FROM ' . rex::getTable('cronjob') . ' WHERE type = ?', ['rex_cronjob_project_manager_report']);

foreach (['override', 'reported', 'state', 'syslog_seen'] as $name) {
    $table = rex_sql_table::get(rex::getTable('project_manager_report_' . $name));
    if ($table->exists()) {
        $table->drop();
    }
}
