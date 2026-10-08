<?php

use Psr\Log\LogLevel;

/**
 * Cronjob-Typ "Projekt Manager: Status-Mail". Die Zeile in rex_cronjob legt install.php an (Umgebung "script").
 */
class rex_cronjob_project_manager_report extends rex_cronjob
{
    public function execute()
    {
        // alle Einstellungen (Empfänger, Schwellen, Filter) kommen aus Projekt Manager Report → Einstellungen
        $settings = rex_project_manager_report_collector::settings();
        $compactTo = $settings['recipients'];
        $reportTo = $settings['recipients_report'];
        if ([] === $compactTo && [] === $reportTo) {
            $this->setMessage('Keine Empfänger eingetragen (Projekt Manager Report, Einstellungen)');

            return false;
        }

        try {
            $projects = rex_project_manager_report_collector::collect(null, null, null, $settings);
            $now = new DateTimeImmutable();
        } catch (Throwable $e) {
            $this->logFailure($e);
            $this->setMessage($e->getMessage());

            return false;
        }

        $messages = [];
        $failed = false;

        // beide Mails versuchen, auch wenn die erste scheitert
        foreach ([
            'kompakt' => [$compactTo, static function () use ($projects, $now): array {
                $mail = rex_project_manager_report_builder::buildMachine($projects, $now);

                return [$mail['subject'], $mail['body'], null];
            }],
            'Bericht' => [$reportTo, static function () use ($projects, $now): array {
                $mail = rex_project_manager_report_builder::buildReport($projects, $now);

                return [$mail['subject'], $mail['text'], $mail['html']];
            }],
        ] as $label => [$recipients, $build]) {
            if ([] === $recipients) {
                continue;
            }
            try {
                [$subject, $text, $html] = $build();
                $messages[] = $label . ': gesendet an ' . $this->send($recipients, $subject, $text, $html);
            } catch (Throwable $e) {
                $failed = true;
                $this->logFailure($e);
                $messages[] = $label . ': ' . $e->getMessage();
            }
        }

        $summary = rex_project_manager_report_builder::summary($projects);
        $messages[] = sprintf('%d Projekte, %d mit Befund, %d Fehler', $summary['projects'], $summary['findings'], $summary['errors']);
        $this->setMessage(implode('; ', $messages));

        return !$failed;
    }

    /**
     * @param list<string> $recipients
     * @param string|null $html HTML-Fassung; dann ist $text die Text-Alternative
     * @return string Anzahl der Empfänger als Text
     */
    private function send(array $recipients, string $subject, string $text, ?string $html): string
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

        return (string) (count($recipients) - count($invalid));
    }

    private function logFailure(Throwable $e): void
    {
        rex_logger::factory()->log(LogLevel::ERROR, 'Projekt-Manager-Report nicht gesendet: ' . $e->getMessage(), [], $e->getFile(), $e->getLine());
    }

    public function getTypeName()
    {
        return 'Projekt Manager: Status-Mail';
    }

    public function getEnvironments()
    {
        return ['backend', 'script'];
    }

    /** Keine typspezifischen Parameter: alles steht unter Projekt Manager Report, Einstellungen. */
    public function getParamFields()
    {
        return [];
    }
}
