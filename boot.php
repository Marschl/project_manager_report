<?php

if (rex::isBackend() && rex::getUser()) {
    rex_perm::register('project_manager_report[]');
}

if (rex_addon::get('cronjob')->isAvailable()) {
    rex_cronjob_manager::registerType(rex_cronjob_project_manager_report::class);
}
