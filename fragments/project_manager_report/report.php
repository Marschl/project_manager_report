<?php

/**
 * HTML-Teil der Bericht-Mail. Alle Werte stammen aus fremden Installationen und werden escaped.
 * Nur Tabellen und Inline-Styles (Mailclients), keine externen Ressourcen.
 *
 * @var rex_fragment $this
 * @var array<string, mixed> $data siehe rex_project_manager_report_builder::buildReport()
 */

$data = $this->getVar('data');
$s = $data['summary'];

$e = static fn (mixed $value): string => rex_escape((string) $value);

$colors = ['FEHLER' => '#b91c1c', 'VERALTET' => '#b45309', 'BEFUND' => '#a16207', 'OK' => '#15803d'];
$badge = static function (string $status) use ($colors): string {
    return '<span style="display:inline-block;padding:2px 8px;border-radius:3px;background:' . $colors[$status] . ';color:#ffffff;font-size:12px;font-weight:bold;">' . rex_escape($status) . '</span>';
};

$font = 'font-family:Arial,Helvetica,sans-serif;';
$th = 'style="' . $font . 'font-size:12px;text-align:left;padding:6px 8px;background:#e4e4e7;color:#27272a;border-bottom:1px solid #d4d4d8;"';
$td = static fn (string $extra = ''): string => 'style="' . $font . 'font-size:13px;padding:6px 8px;border-bottom:1px solid #e4e4e7;color:#18181b;' . $extra . '"';
$h2 = 'style="' . $font . 'font-size:15px;font-weight:bold;color:#18181b;margin:24px 0 8px 0;"';
$muted = 'style="' . $font . 'font-size:13px;color:#52525b;margin:0 0 8px 0;"';
$mono = 'font-family:Consolas,Menlo,monospace;font-size:12px;';

$title = 'Projekt Manager – Statusbericht ' . $data['now'];
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($title) ?></title>
</head>
<body style="margin:0;padding:0;background:#f4f4f5;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;">
<tr><td align="center" style="padding:16px;">
<table role="presentation" width="720" cellpadding="0" cellspacing="0" style="width:100%;max-width:720px;background:#ffffff;border:1px solid #d4d4d8;">
<tr><td style="padding:20px 24px;">

<p style="<?= $font ?>font-size:20px;font-weight:bold;color:#18181b;margin:0 0 4px 0;">Projekt Manager – Statusbericht</p>
<p <?= $muted ?>>Stand: <?= $e($data['now']) ?></p>

<p <?= $h2 ?>>Zusammenfassung</p>
<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;">
<tr>
<?php foreach ([
    ['Projekte', $s['projects'], '#18181b'],
    ['OK', $s['ok'], '#15803d'],
    ['Fehler', $s['errors'], $s['errors'] ? '#b91c1c' : '#18181b'],
    ['Mit Befund', $s['findings'], $s['findings'] ? '#a16207' : '#18181b'],
    ['Unter Mindestversion', $s['below_min'], $s['below_min'] ? '#b91c1c' : '#18181b'],
    ['Im Rückstand', $s['behind'], $s['behind'] ? '#b45309' : '#18181b'],
    ['Addon-Updates', $s['updates'], '#18181b'],
] as [$label, $value, $color]): ?>
<td align="center" style="<?= $font ?>padding:8px;background:#fafafa;border:1px solid #e4e4e7;">
<span style="font-size:24px;font-weight:bold;color:<?= $color ?>;"><?= $e($value) ?></span><br>
<span style="font-size:12px;color:#52525b;"><?= $e($label) ?></span>
</td>
<?php endforeach ?>
</tr>
</table>
<p <?= $muted ?>>Addon-Updates sind kein Befund. <?= $e($s['stale']) ?> Projekt(e) mit veraltetem Abruf.</p>

<p <?= $h2 ?>>Handlungsbedarf</p>
<?php if ([] === $data['attention']): ?>
<p <?= $muted ?>>Keine Befunde.</p>
<?php endif ?>
<?php foreach ($data['attention'] as $project):
    $status = $data['status'][$project['domain']]; ?>
<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;margin:0 0 12px 0;border:1px solid #e4e4e7;border-left:4px solid <?= $colors[$status] ?>;">
<tr><td <?= $td('background:#fafafa;') ?>><strong><?= $e($project['domain']) ?></strong> &nbsp;<?= $badge($status) ?></td></tr>
<?php if ('error' === $project['state']): ?>
<tr><td <?= $td() ?>><?= $project['last_success'] ? 'Abruf fehlgeschlagen, letzter Erfolg ' . $e(rex_project_manager_report_builder::minute($project['last_success'])) : 'Abruf fehlgeschlagen, bisher kein erfolgreicher Abruf' ?></td></tr>
<?php else: ?>
<?php if ('stale' === $project['state']): ?>
<tr><td <?= $td() ?>>Letzter erfolgreicher Abruf: <?= $e(rex_project_manager_report_builder::minute($project['last_success'])) ?></td></tr>
<?php endif ?>
<tr><td <?= $td('color:#52525b;') ?>><?= $e(rex_project_manager_report_builder::versions($project)) ?></td></tr>
<?php foreach (rex_project_manager_report_builder::violations($project) as $violation): ?>
<tr><td <?= $td() ?>><strong style="color:#b91c1c;">Mindestversion:</strong> <?= $e($violation['label']) ?> liegt unter <?= $e($violation['min']) ?></td></tr>
<?php endforeach ?>
<?php if (null !== $project['behind']): ?>
<tr><td <?= $td() ?>><strong style="color:#b45309;">Rückstand:</strong> <?= $e(rex_project_manager_report_builder::behindLine($project)) ?>, Flottenmaximum der PHP-Linie <?= $e($project['behind']['line']) ?></td></tr>
<?php endif ?>
<?php foreach ($project['syslog'] as $group): ?>
<tr><td <?= $td() ?>>
<strong><?= $e($group['type']) ?></strong> &nbsp;<?= $e($group['message']) ?>
<?php if ('' !== $group['file']): ?><br><span style="<?= $mono ?>color:#52525b;"><?= $e(trim(rex_project_manager_report_builder::where($group), ' ()')) ?></span><?php endif ?>
<span style="color:#52525b;"> &nbsp;× <?= $e($group['count']) ?> (24 h)</span>
</td></tr>
<?php endforeach ?>
<?php endif ?>
</table>
<?php endforeach ?>

<p <?= $h2 ?>>Addon-Updates (kein Befund)</p>
<?php if ([] === $data['updates']): ?>
<p <?= $muted ?>>Keine Updates gemeldet.</p>
<?php else: ?>
<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border:1px solid #e4e4e7;">
<tr><th <?= $th ?>>Projekt</th><th <?= $th ?>>Addon</th><th <?= $th ?>>Installiert</th><th <?= $th ?>>Verfügbar</th></tr>
<?php foreach ($data['updates'] as $project): foreach ($project['updates'] as $u): ?>
<tr>
<td <?= $td() ?>><?= $e($project['domain']) ?></td>
<td <?= $td() ?>><?= $e($u['name']) ?></td>
<td <?= $td($mono) ?>><?= $e($u['current']) ?></td>
<td <?= $td($mono) ?>><?= $e($u['latest']) ?></td>
</tr>
<?php endforeach; endforeach ?>
</table>
<?php endif ?>

<p <?= $h2 ?>>Syslog ohne Befund (letzte 24 Stunden)</p>
<?php if ([] === $data['notes']): ?>
<p <?= $muted ?>>Keine Einträge.</p>
<?php else: ?>
<p <?= $muted ?>>Durch Typ oder Meldungsmuster ignoriert.</p>
<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border:1px solid #e4e4e7;">
<?php foreach ($data['notes'] as $project): ?>
<tr>
<td <?= $td() ?>><?= $e($project['domain']) ?></td>
<td <?= $td('color:#52525b;') ?>><?php
    echo implode(', ', array_map(static fn (string $type, int $count) => rex_escape($type) . ' × ' . $count, array_keys($project['ignored']), $project['ignored']));
?></td>
</tr>
<?php endforeach ?>
</table>
<?php endif ?>

<p <?= $h2 ?>>Alle Projekte</p>
<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border:1px solid #e4e4e7;">
<tr>
<?php foreach (['Domain', 'REDAXO', 'PHP', 'Datenbank', 'Updates', 'Syslog', 'Letzter Abruf', 'Status'] as $label): ?>
<th <?= $th ?>><?= $e($label) ?></th>
<?php endforeach ?>
</tr>
<?php foreach ($data['projects'] as $project):
    $failed = 'error' === $project['state'];
    $below = rex_project_manager_report_builder::belowMin($project);
    $low = 'color:#b91c1c;font-weight:bold;';
    $lag = 'color:#b45309;font-weight:bold;'; ?>
<tr>
<td <?= $td() ?>><?= $e($project['domain']) ?></td>
<td <?= $td(in_array('rex', $below, true) ? $low : '') ?>><?= $e($project['rex'] ?? '-') ?></td>
<td <?= $td(in_array('php', $below, true) ? $low : (null !== $project['behind'] ? $lag : '')) ?>><?= $e($project['php'] ?? '-') ?><?= null !== $project['behind'] ? ' ~' : '' ?></td>
<td <?= $td(in_array('db', $below, true) ? $low : '') ?>><?= $e($project['db_label']) ?></td>
<td <?= $td() ?>><?= $failed ? '-' : $e(count($project['updates'])) ?></td>
<td <?= $td() ?>><?= $failed ? '-' : $e(array_sum(array_column($project['syslog'], 'count'))) ?></td>
<td <?= $td() ?>><?= $e($project['last_success'] ? rex_project_manager_report_builder::minute($project['last_success']) : '-') ?></td>
<td <?= $td() ?>><?= $badge($data['status'][$project['domain']]) ?></td>
</tr>
<?php endforeach ?>
</table>
<?php if ($s['below_min'] > 0 || $s['behind'] > 0): ?>
<p <?= $muted ?>><span style="color:#b91c1c;font-weight:bold;">Rot</span> = unter Mindestversion, <span style="color:#b45309;font-weight:bold;">Orange mit ~</span> = Patch-Rückstand in der PHP-Linie.</p>
<?php endif ?>

<p <?= $muted ?>>&nbsp;</p>
<p style="<?= $font ?>font-size:12px;color:#71717a;margin:0;">Erzeugt vom Addon project_manager_report aus den Daten des Project Managers.</p>

</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
