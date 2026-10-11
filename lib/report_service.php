<?php

use Psr\Log\LogLevel;

/**
 * Ein Lauf des Reports (Cronjob und Konsolenbefehl "send"):
 *
 *  1. Daten sammeln, Zustand je Projekt fortschreiben und gesehene Syslog-Einträge ergänzen (bei jedem Lauf,
 *     unabhängig vom Mailerfolg),
 *  2. je Mailtyp das Delta gegen den zuletzt gemeldeten Stand berechnen, die Mail bauen und senden,
 *  3. nur nach erfolgreichem Versand den gemeldeten Stand dieses Mailtyps durch den aktuellen ersetzen.
 *
 * prepare() liest nur und schreibt nichts (Vorschau, Testversand).
 */
class rex_project_manager_report_service
{
    /**
     * Mails und Zustandsänderungen berechnen, ohne zu schreiben.
     *
     * @param array{now?: DateTimeImmutable, to?: list<string>, dry_run?: bool} $options
     * @return array{now: DateTimeImmutable, settings: array<string, mixed>, projects: list<array<string, mixed>>, states: array<string, array{first_failed_at: ?string, last_success_at: ?string}>, seen_upserts: list<array<string, mixed>>, mails: array<string, array<string, mixed>>}
     */
    public static function prepare(array $options = []): array
    {
        $collector = 'rex_project_manager_report_collector';
        $delta = 'rex_project_manager_report_delta';
        $store = 'rex_project_manager_report_store';

        $now = $options['now'] ?? new DateTimeImmutable();
        $settings = $collector::settings();
        $projects = $collector::collect(null, null, null, $settings, $now);

        // Zustand: Fehlerserie, letzter Erfolg, Pause
        $stored = $store::loadState();
        $states = [];
        foreach ($projects as &$project) {
            $domain = $project['domain'];
            $row = $stored[$domain] ?? null;
            $states[$domain] = $delta::nextState($project, $row, $now);
            $project['paused'] = (bool) ($row['monitoring_pausiert'] ?? false);
            $project['pause_reason'] = (string) ($row['pause_grund'] ?? '');
            if ('error' === $project['state']) {
                $project['error_text'] = $delta::errorText($states[$domain], $now);
            }
        }
        unset($project);

        // Syslog: gesehene Einträge um die aktuellen ergänzen (in Speicher; geschrieben wird erst in run())
        $merged = $delta::mergeSeen($store::loadSeen(), $projects, $now);
        $fingerprint = $delta::rulesFingerprint($settings);

        $mails = [];
        foreach ($store::MAIL_TYPES as $type) {
            $baseline = $store::loadReported($type);
            $reportedAt = [] === $baseline ? null : (string) max(array_column($baseline, 'reported_at'));
            $storedRules = $store::rules($type);

            $diff = $delta::compute($projects, $baseline, [
                'settings' => $settings,
                'rules_changed' => [] !== $baseline && null !== $storedRules && $storedRules !== $fingerprint,
                'syslog_new' => $delta::newSyslog($merged['seen'], $reportedAt, $settings['syslog_ignore'], $settings['ignore_messages']),
            ]);

            $built = 'data' === $type
                ? rex_project_manager_report_builder::buildMachine($projects, $now, $diff)
                : rex_project_manager_report_builder::buildReport($projects, $now, true, $diff);

            $mails[$type] = [
                'recipients' => $options['to'] ?? $settings['recipients' . ('data' === $type ? '' : '_report')],
                'subject' => $built['subject'],
                'text' => 'data' === $type ? $built['body'] : $built['text'],
                'html' => 'data' === $type ? null : $built['html'],
                'delta' => $diff,
                'snapshot' => $delta::snapshot($type, $projects, $now),
                'fingerprint' => $fingerprint,
                'projects' => $built['projects'],
                'findings' => $built['findings'],
                'errors' => $built['errors'],
            ];
        }

        return ['now' => $now, 'settings' => $settings, 'projects' => $projects, 'states' => $states, 'seen_upserts' => $merged['upserts'], 'mails' => $mails];
    }

    /**
     * Lauf mit Versand.
     *
     * @param array{
     *     now?: DateTimeImmutable,
     *     to?: list<string>,
     *     dry_run?: bool,
     *     advance?: bool,
     *     mailer?: callable(list<string>, string, string, ?string, bool): int
     * } $options
     *   dry_run: nur an die Testadresse, nichts wird geschrieben; advance=false: gemeldeter Stand bleibt;
     *   to: ersetzt die Empfänger (rückt die Baseline nicht vor); mailer: Ersatz für den Versand (Tests)
     * @return array{failed: bool, messages: list<string>, projects: int, findings: int, errors: int}
     */
    public static function run(array $options = []): array
    {
        $store = 'rex_project_manager_report_store';
        $settings = rex_project_manager_report_collector::settings();

        $dryRun = (bool) ($options['dry_run'] ?? $settings['dry_run']);
        $advance = (bool) ($options['advance'] ?? true) && !$dryRun && !isset($options['to']);
        $mailer = $options['mailer'] ?? [self::class, 'sendMail'];

        if ($dryRun) {
            $test = $options['to'] ?? ('' === $settings['dry_run_address'] ? [] : [$settings['dry_run_address']]);
            if ([] === $test) {
                return ['failed' => true, 'messages' => ['Testlauf: keine Testadresse eingetragen (Projekt Manager Report, Einstellungen)'], 'projects' => 0, 'findings' => 0, 'errors' => 0];
            }
            $options['to'] = $test;
        }

        $prepared = self::prepare($options);
        $mails = $prepared['mails'];

        if (!$dryRun && [] === $mails['data']['recipients'] && [] === $mails['report']['recipients']) {
            return ['failed' => true, 'messages' => ['Keine Empfänger eingetragen (Projekt Manager Report, Einstellungen)'], 'projects' => 0, 'findings' => 0, 'errors' => 0];
        }

        $messages = [];
        $failed = false;

        // Zustand und gesehene Syslog-Einträge immer fortschreiben (außer im Testlauf), auch wenn der Versand scheitert.
        // Scheitert das Speichern, werden die Mails trotzdem versendet; der Lauf gilt dann als fehlgeschlagen.
        if (!$dryRun) {
            try {
                foreach ($prepared['states'] as $domain => $next) {
                    $store::saveState($domain, $next);
                }
                $store::upsertSeen($prepared['seen_upserts']);
                $store::purgeSeen($prepared['now']);
            } catch (Throwable $e) {
                $failed = true;
                self::logFailure($e);
                $messages[] = 'Zustand nicht gespeichert: ' . $e->getMessage();
            }
        }
        foreach ([['data', 'kompakt'], ['report', 'Bericht']] as [$type, $label]) {
            $mail = $mails[$type];
            if ([] === $mail['recipients']) {
                continue;
            }
            try {
                $sent = $mailer($mail['recipients'], $mail['subject'], $mail['text'], $mail['html'], $dryRun);
            } catch (Throwable $e) {
                $failed = true;
                self::logFailure($e);
                $messages[] = $label . ': ' . $e->getMessage();
                continue;
            }

            $note = $dryRun ? ' (Testlauf)' : ($advance ? '' : ' (Stand nicht vorgerückt)');
            // erst nach erfolgreichem Versand den gemeldeten Stand dieses Mailtyps ersetzen; ein Fehler dabei
            // ändert nichts daran, dass die Mail gesendet ist
            if ($advance) {
                try {
                    $store::replaceReported($type, $mail['snapshot']);
                    $store::setRules($type, $mail['fingerprint']);
                } catch (Throwable $e) {
                    $failed = true;
                    self::logFailure($e);
                    $note = ', Stand nicht gespeichert: ' . $e->getMessage();
                }
            }
            $messages[] = $label . ': gesendet an ' . $sent . $note;
        }

        $any = $mails['data'];
        $messages[] = sprintf('%d Projekte, %d mit Befund, %d Fehler', $any['projects'], $any['findings'], $any['errors']);

        return ['failed' => $failed, 'messages' => $messages, 'projects' => $any['projects'], 'findings' => $any['findings'], 'errors' => $any['errors']];
    }

    /**
     * Versand mit rex_mailer. Ungültige Adressen werden übersprungen (Warnung im Syslog), keine gültige = Fehler.
     *
     * @param list<string> $recipients
     * @param string|null $html HTML-Fassung; dann ist $text die Text-Alternative
     * @return int Zahl der Empfänger
     */
    public static function sendMail(array $recipients, string $subject, string $text, ?string $html, bool $dryRun = false): int
    {
        $mail = new rex_mailer();
        $mail->WordWrap = 0; // der Text ist zeilenweise auswertbar, keine automatischen Umbrüche
        $mail->Subject = $subject;
        if (null === $html) {
            $mail->isHTML(false);
            $mail->Body = $text;
        } else {
            $mail->isHTML(true);
            $mail->Body = $html;
            $mail->AltBody = $text;
        }
        if ($dryRun) {
            $mail->addCustomHeader('X-PMR-Dry-Run', '1');
        }

        $invalid = [];
        foreach ($recipients as $recipient) {
            // selbst prüfen: im Umleitungsmodus (Detour) prüft addAddress() nicht
            if (false === filter_var($recipient, FILTER_VALIDATE_EMAIL) || !$mail->addAddress($recipient)) {
                $invalid[] = $recipient;
            }
        }
        if (count($invalid) === count($recipients)) {
            throw new rex_exception('Keine gültige Empfängeradresse: ' . implode(', ', $invalid));
        }
        if ([] !== $invalid) {
            rex_logger::factory()->log(LogLevel::WARNING, 'Projekt-Manager-Report: ungültige Empfängeradresse ignoriert: ' . implode(', ', $invalid), [], __FILE__, __LINE__);
        }

        if (!$mail->send()) {
            throw new rex_exception('Mailversand fehlgeschlagen: ' . $mail->ErrorInfo);
        }

        return count($recipients) - count($invalid);
    }

    public static function logFailure(Throwable $e): void
    {
        rex_logger::factory()->log(LogLevel::ERROR, 'Projekt-Manager-Report nicht gesendet: ' . $e->getMessage(), [], $e->getFile(), $e->getLine());
    }
}
