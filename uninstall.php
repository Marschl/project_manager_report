<?php

rex_sql::factory()->setQuery('DELETE FROM ' . rex::getTable('cronjob') . ' WHERE type = ?', ['rex_cronjob_project_manager_report']);
