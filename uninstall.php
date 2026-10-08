<?php

rex_sql::factory()->setQuery('DELETE FROM ' . rex::getTable('cronjob') . ' WHERE type = ?', ['rex_cronjob_project_manager_report']);

$overrides = rex_sql_table::get(rex::getTable('project_manager_report_override'));
if ($overrides->exists()) {
    $overrides->drop();
}
