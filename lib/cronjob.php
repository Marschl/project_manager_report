<?php

use Psr\Log\LogLevel;

/**
 * Cronjob-Typ "Projekt Manager: Status-Mail". Die Zeile in rex_cronjob legt install.php an (Umgebung "script").
 */
class rex_cronjob_project_manager_report extends rex_cronjob
{
    public function execute()
    {
        $compactTo = rex_project_manager_report_collector::parseList((string) $this->getParam('recipients', ''));
        $reportTo = rex_project_manager_report_collector::parseList((string) $this->getParam('recipients_report', ''));
        if ([] === $compactTo && [] === $reportTo) {
            $this->setMessage('Keine Empfänger eingetragen');

            return false;
        }

        try {
            $ignore = $this->getParam('syslog_ignore');
            $projects = rex_project_manager_report_collector::collect(
                max(1, (int) $this->getParam('stale_hours', rex_project_manager_report_collector::DEFAULT_STALE_HOURS)),
                null === $ignore ? rex_project_manager_report_collector::DEFAULT_SYSLOG_IGNORE : rex_project_manager_report_collector::parseList((string) $ignore),
                rex_project_manager_report_collector::parseLines((string) $this->getParam('syslog_ignore_messages', '')),
            );
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

    public function getParamFields()
    {
        return [
            [
                'label' => 'Empfänger maschinenlesbar (kompakt)',
                'name' => 'recipients',
                'type' => 'textarea',
                'attributes' => ['rows' => 3],
                'notice' => 'Knappe, strukturierte Plain-Text-Mail zur automatischen Auswertung. E-Mail-Adressen, getrennt durch Komma oder Zeilenumbruch; leer = diese Mail entfällt.',
            ],
            [
                'label' => 'Empfänger Bericht (HTML)',
                'name' => 'recipients_report',
                'type' => 'textarea',
                'attributes' => ['rows' => 3],
                'notice' => 'Ausführlicher, lesbarer Bericht (HTML mit Text-Alternative). E-Mail-Adressen, getrennt durch Komma oder Zeilenumbruch; leer = diese Mail entfällt.',
            ],
            [
                'label' => 'Veraltet nach (Stunden)',
                'name' => 'stale_hours',
                'type' => 'text',
                'default' => (string) rex_project_manager_report_collector::DEFAULT_STALE_HOURS,
                'notice' => 'Liegt der letzte erfolgreiche Abruf länger zurück, gilt das Projekt als VERALTET (Befund).',
            ],
            [
                'label' => 'Syslog-Typen ignorieren',
                'name' => 'syslog_ignore',
                'type' => 'text',
                'default' => implode(',', rex_project_manager_report_collector::DEFAULT_SYSLOG_IGNORE),
                'notice' => 'Diese Typen zählen nicht als Befund (Schreibweise egal). Vorgabe: Info,logevent,Deprecated,Notice',
            ],
            [
                'label' => 'Syslog-Meldungen ignorieren',
                'name' => 'syslog_ignore_messages',
                'type' => 'textarea',
                'attributes' => ['rows' => 3],
                'notice' => 'Teiltexte, einer pro Zeile, Schreibweise egal. Geprüft gegen „Typ Meldung Datei:Zeile“, z. B. Undefined variable $params',
            ],
        ];
    }
}
