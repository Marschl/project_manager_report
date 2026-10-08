<?php

/** @var rex_addon $this */

$csrf = rex_csrf_token::factory('project_manager_report_settings');
$collector = 'rex_project_manager_report_collector';
$defaults = $collector::SETTING_DEFAULTS;
$versionKeys = ['min_rex', 'min_php', 'min_mariadb', 'min_mysql'];
$addressKeys = ['recipients', 'recipients_report'];

// aktuelle Werte: nie gespeichert = Startwert, leer gespeichert = leer
$values = [];
foreach ($defaults as $key => $default) {
    $values[$key] = $this->hasConfig($key) ? trim((string) $this->getConfig($key)) : $default;
}

if (rex_post('save', 'bool')) {
    $input = rex_post('config', 'array', []);
    $errors = [];

    if (!$csrf->isValid()) {
        $errors[] = rex_i18n::msg('csrf_token_invalid');
    } elseif (!rex::requireUser()->hasPerm('project_manager_report[]')) {
        $errors[] = $this->i18n('no_permission');
    } else {
        $new = [];

        foreach ($addressKeys as $key) {
            $new[$key] = trim((string) ($input[$key] ?? ''));
            $invalid = $collector::invalidAddresses($collector::parseList($new[$key]));
            if ([] !== $invalid) {
                $errors[] = $this->i18n('invalid_address', $this->i18n($key), implode(', ', $invalid));
            }
        }

        // Testversand: nur an die Testadresse, nichts wird gespeichert; ohne gültige Adresse nicht aktivierbar
        $new['dry_run_address'] = trim((string) ($input['dry_run_address'] ?? ''));
        $new['dry_run'] = '1' === (string) ($input['dry_run'] ?? '') ? '1' : '0';
        $invalidTest = $collector::invalidAddresses($collector::parseList($new['dry_run_address']));
        if ([] !== $invalidTest || count($collector::parseList($new['dry_run_address'])) > 1) {
            $errors[] = $this->i18n('invalid_address', $this->i18n('dry_run_address'), implode(', ', $invalidTest ?: [$new['dry_run_address']]));
        } elseif ('1' === $new['dry_run'] && '' === $new['dry_run_address']) {
            $errors[] = $this->i18n('dry_run_needs_address');
        }

        $new['stale_hours'] = trim((string) ($input['stale_hours'] ?? ''));
        if ('' !== $new['stale_hours'] && (1 !== preg_match('/^\d+$/', $new['stale_hours']) || (int) $new['stale_hours'] < 1)) {
            $errors[] = $this->i18n('invalid_hours', $this->i18n('stale_hours'));
        }
        $new['syslog_ignore'] = trim((string) ($input['syslog_ignore'] ?? ''));
        $new['syslog_ignore_messages'] = trim(str_replace("\r\n", "\n", (string) ($input['syslog_ignore_messages'] ?? '')));

        foreach ($versionKeys as $key) {
            $new[$key] = trim((string) ($input[$key] ?? ''));
            if ('' !== $new[$key] && !rex_project_manager_report_version::isValid($new[$key])) {
                $errors[] = $this->i18n('invalid_version', $this->i18n($key));
            }
        }
        $new['max_patch_lag'] = trim((string) ($input['max_patch_lag'] ?? ''));
        if ('' !== $new['max_patch_lag'] && 1 !== preg_match('/^\d+$/', $new['max_patch_lag'])) {
            $errors[] = $this->i18n('invalid_lag', $this->i18n('max_patch_lag'));
        }
        $new['ignore_prerelease'] = '1' === (string) ($input['ignore_prerelease'] ?? '') ? '1' : '0';

        $values = $new; // Eingaben im Formular behalten

        if ([] === $errors) {
            foreach ($new as $key => $value) {
                $this->setConfig($key, $value);
            }
            echo rex_view::success($this->i18n('saved'));
        }
    }

    if ([] !== $errors) {
        echo rex_view::error(implode('<br>', array_map('rex_escape', $errors)));
    }
}

$text = static function (string $key, string $value, string $label, string $note, bool $multiline = false, int $rows = 3): array {
    $field = $multiline
        ? '<textarea class="form-control" id="pmr-' . $key . '" name="config[' . $key . ']" rows="' . $rows . '">' . rex_escape($value) . '</textarea>'
        : '<input class="form-control" type="text" id="pmr-' . $key . '" name="config[' . $key . ']" value="' . rex_escape($value) . '" autocomplete="off">';

    return [
        'label' => '<label for="pmr-' . $key . '">' . rex_escape($label) . '</label>',
        'field' => $field,
        'note' => rex_escape($note),
    ];
};
$group = static function (string $title, string $intro, array $elements): string {
    $fragment = new rex_fragment();
    $fragment->setVar('elements', $elements, false);

    return '<h4>' . rex_escape($title) . '</h4><p>' . rex_escape($intro) . '</p>' . $fragment->parse('core/form/container.php');
};

// Versand
$content = $group($this->i18n('group_mail'), $this->i18n('group_mail_intro'), [
    $text('recipients', $values['recipients'], $this->i18n('recipients'), $this->i18n('recipients_note'), true),
    $text('recipients_report', $values['recipients_report'], $this->i18n('recipients_report'), $this->i18n('recipients_report_note'), true),
    $text('dry_run_address', $values['dry_run_address'], $this->i18n('dry_run_address'), $this->i18n('dry_run_address_note')),
]);

$dryRun = new rex_fragment();
$dryRun->setVar('elements', [[
    'label' => '<label for="pmr-dry_run">' . rex_escape($this->i18n('dry_run')) . '</label>',
    'field' => '<input type="checkbox" id="pmr-dry_run" name="config[dry_run]" value="1"' . ('0' !== $values['dry_run'] && '' !== $values['dry_run'] ? ' checked' : '') . '>',
    'note' => rex_escape($this->i18n('dry_run_note')),
]], false);
$content .= $dryRun->parse('core/form/checkbox.php');

// Auswertung
$content .= $group($this->i18n('group_evaluation'), $this->i18n('group_evaluation_intro'), [
    $text('stale_hours', $values['stale_hours'], $this->i18n('stale_hours'), $this->i18n('stale_hours_note')),
    $text('syslog_ignore', $values['syslog_ignore'], $this->i18n('syslog_ignore'), $this->i18n('syslog_ignore_note')),
    $text('syslog_ignore_messages', $values['syslog_ignore_messages'], $this->i18n('syslog_ignore_messages'), $this->i18n('syslog_ignore_messages_note'), true),
]);

// Mindestversionen und Rückstand
$elements = [];
foreach ($versionKeys as $key) {
    $elements[] = $text($key, $values[$key], $this->i18n($key), $this->i18n($key . '_note'));
}
$elements[] = $text('max_patch_lag', $values['max_patch_lag'], $this->i18n('max_patch_lag'), $this->i18n('max_patch_lag_note'));
$content .= $group($this->i18n('group_versions'), $this->i18n('settings_intro'), $elements);

$checkbox = new rex_fragment();
$checkbox->setVar('elements', [[
    'label' => '<label for="pmr-ignore_prerelease">' . rex_escape($this->i18n('ignore_prerelease')) . '</label>',
    'field' => '<input type="checkbox" id="pmr-ignore_prerelease" name="config[ignore_prerelease]" value="1"' . ('0' !== $values['ignore_prerelease'] && '' !== $values['ignore_prerelease'] ? ' checked' : '') . '>',
    'note' => rex_escape($this->i18n('ignore_prerelease_note')),
]], false);
$content .= $checkbox->parse('core/form/checkbox.php');

$submit = new rex_fragment();
$submit->setVar('elements', [['field' => '<button class="btn btn-save rex-form-aligned" type="submit" name="save" value="1">' . rex_escape($this->i18n('save')) . '</button>']], false);
$buttons = $submit->parse('core/form/submit.php');

$section = new rex_fragment();
$section->setVar('class', 'edit', false);
$section->setVar('title', $this->i18n('settings_title'), false);
$section->setVar('body', $content, false);
$section->setVar('buttons', $buttons, false);

echo '<form action="' . rex_url::currentBackendPage() . '" method="post">' . $csrf->getHiddenField() . $section->parse('core/page/section.php') . '</form>';
