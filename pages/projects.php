<?php

/** @var rex_addon $this */

$table = rex::getTable('project_manager_report_override');
$domainTable = rex::getTable('project_manager_domain');
$fields = ['min_rex', 'min_php', 'min_mariadb', 'min_mysql'];

if (!rex_sql_table::get($table)->exists() || !rex_project_manager_report_store::exists()) {
    echo rex_view::warning($this->i18n('need_reinstall'));

    return;
}

$csrf = rex_csrf_token::factory('project_manager_report_projects');
$func = rex_request('func', 'string');
$domain = rex_request('domain', 'string');

// Projekt muss im Project Manager existieren
$project = '' === $domain ? [] : rex_sql::factory()->getArray('SELECT domain, cms FROM ' . $domainTable . ' WHERE domain = ?', [$domain]);
if ('' !== $func && [] === $project) {
    echo rex_view::error(rex_escape($this->i18n('unknown_domain', $domain)));
    $func = '';
}

$errors = [];
$input = [];
if ('save' === $func) {
    if (!$csrf->isValid()) {
        $errors[] = rex_i18n::msg('csrf_token_invalid');
    } elseif (!rex::requireUser()->hasPerm('project_manager_report[]')) {
        $errors[] = $this->i18n('no_permission');
    } else {
        foreach ($fields as $field) {
            $input[$field] = trim(rex_post($field, 'string', ''));
            if ('' !== $input[$field] && !rex_project_manager_report_version::isValid($input[$field])) {
                $errors[] = $this->i18n('invalid_version', $this->i18n($field));
            }
        }
        $input['paused'] = '1' === rex_post('paused', 'string', '') ? '1' : '0';
        $input['pause_grund'] = rex_project_manager_report_collector::clean(rex_post('pause_grund', 'string', ''), 255);
        if ([] === $errors) {
            // Pause (Monitoring): gehört zum Zustand des Projekts, nicht zu den Mindestversionen
            rex_project_manager_report_store::setPause($domain, '1' === $input['paused'], $input['pause_grund']);
            if ([] === array_filter(array_intersect_key($input, array_flip($fields)), static fn (string $value) => '' !== $value)) {
                rex_sql::factory()->setQuery('DELETE FROM ' . $table . ' WHERE domain = ?', [$domain]);
                echo rex_view::success($this->i18n('override_removed'));
            } else {
                $sql = rex_sql::factory()->setTable($table)->setValue('domain', $domain);
                foreach ($fields as $field) {
                    $sql->setValue($field, '' === $input[$field] ? null : $input[$field]);
                }
                $sql->insertOrUpdate();
                echo rex_view::success($this->i18n('override_saved'));
            }
            $func = '';
        }
    }
    if ([] !== $errors) {
        echo rex_view::error(implode('<br>', array_map('rex_escape', $errors)));
    }
}

if ('save' === $func || 'edit' === $func) {
    $current = rex_sql::factory()->getArray('SELECT * FROM ' . $table . ' WHERE domain = ?', [$domain])[0] ?? [];
    $pause = rex_project_manager_report_store::loadState()[$domain] ?? ['monitoring_pausiert' => false, 'pause_grund' => ''];
    $global = rex_project_manager_report_collector::settings();

    $elements = [];
    foreach ($fields as $field) {
        $value = [] === $input ? (string) ($current[$field] ?? '') : $input[$field];
        $elements[] = [
            'label' => '<label for="pmr-' . $field . '">' . rex_escape($this->i18n($field)) . '</label>',
            'field' => '<input class="form-control" type="text" id="pmr-' . $field . '" name="' . $field . '" value="' . rex_escape($value) . '" placeholder="' . rex_escape($this->i18n('global') . ': ' . ($global[$field] ?? '–')) . '" autocomplete="off">',
        ];
    }

    $fragment = new rex_fragment();
    $fragment->setVar('elements', $elements, false);
    $content = '<p>' . rex_escape($this->i18n('projects_intro')) . '</p>' . $fragment->parse('core/form/container.php');

    $isPaused = [] === $input ? $pause['monitoring_pausiert'] : '1' === $input['paused'];
    $reason = [] === $input ? $pause['pause_grund'] : $input['pause_grund'];
    $pauseFragment = new rex_fragment();
    $pauseFragment->setVar('elements', [[
        'label' => '<label for="pmr-paused">' . rex_escape($this->i18n('paused')) . '</label>',
        'field' => '<input type="checkbox" id="pmr-paused" name="paused" value="1"' . ($isPaused ? ' checked' : '') . '>',
        'note' => rex_escape($this->i18n('paused_note')),
    ]], false);
    $content .= $pauseFragment->parse('core/form/checkbox.php');
    $reasonFragment = new rex_fragment();
    $reasonFragment->setVar('elements', [[
        'label' => '<label for="pmr-pause_grund">' . rex_escape($this->i18n('pause_reason')) . '</label>',
        'field' => '<input class="form-control" type="text" id="pmr-pause_grund" name="pause_grund" value="' . rex_escape($reason) . '" maxlength="255" autocomplete="off">',
    ]], false);
    $content .= $reasonFragment->parse('core/form/container.php');

    $submit = new rex_fragment();
    $submit->setVar('elements', [
        ['field' => '<button class="btn btn-save rex-form-aligned" type="submit">' . rex_escape($this->i18n('save')) . '</button>'],
        ['field' => '<a class="btn btn-abort" href="' . rex_url::currentBackendPage() . '">' . rex_escape($this->i18n('cancel')) . '</a>'],
    ], false);

    $section = new rex_fragment();
    $section->setVar('class', 'edit', false);
    $section->setVar('title', $this->i18n('override_for', $domain), false);
    $section->setVar('body', $content, false);
    $section->setVar('buttons', $submit->parse('core/form/submit.php'), false);

    echo '<form action="' . rex_url::currentBackendPage() . '" method="post">'
        . $csrf->getHiddenField()
        . '<input type="hidden" name="func" value="save"><input type="hidden" name="domain" value="' . rex_escape($domain) . '">'
        . $section->parse('core/page/section.php') . '</form>';

    return;
}

$list = rex_list::factory(
    'SELECT d.domain, d.cms, o.min_rex, o.min_php, o.min_mariadb, o.min_mysql, s.monitoring_pausiert, s.pause_grund
     FROM ' . $domainTable . ' d
     LEFT JOIN ' . $table . ' o ON o.domain = d.domain
     LEFT JOIN ' . rex::getTable('project_manager_report_state') . ' s ON s.project_key = d.domain
     ORDER BY d.domain',
    100,
    'project_manager_report_projects',
);
$list->setNoRowsMessage($this->i18n('no_projects'));
$list->setColumnLabel('domain', $this->i18n('domain'));
$list->setColumnLabel('cms', $this->i18n('cms'));
$list->setColumnParams('domain', ['func' => 'edit', 'domain' => '###domain###']);
foreach ($fields as $field) {
    $list->setColumnLabel($field, $this->i18n($field));
    $list->setColumnFormat($field, 'custom', static fn (array $params) => '' === (string) $params['value'] ? '<span class="text-muted">–</span>' : '<strong>' . rex_escape($params['value']) . '</strong>');
}

$list->setColumnLabel('monitoring_pausiert', $this->i18n('paused'));
$list->setColumnFormat('monitoring_pausiert', 'custom', static fn (array $params) => (int) $params['value'] > 0
    ? '<span class="label label-default">' . rex_escape($params['list']->getValue('pause_grund') ?: '–') . '</span>'
    : '<span class="text-muted">–</span>');
$list->removeColumn('pause_grund');

// gleiche Optik wie die Einstellungen: Panel-Klasse "edit" (grüner Kopf), Hinweis im Panel-Body, Liste als Inhalt
$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', $this->i18n('projects_title'), false);
$fragment->setVar('body', '<p>' . rex_escape($this->i18n('projects_intro')) . '</p>', false);
$fragment->setVar('content', $list->get(), false);
echo $fragment->parse('core/page/section.php');
