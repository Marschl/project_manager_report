<?php

/**
 * Cronjob-Typ "Projekt Manager: Status-Mail". Die Zeile in rex_cronjob legt install.php an (Umgebung "script").
 * Keine eigenen Parameter: Einstellungen unter Projekt Manager Report → Einstellungen, Ablauf in rex_project_manager_report_service.
 */
class rex_cronjob_project_manager_report extends rex_cronjob
{
    public function execute()
    {
        try {
            $result = rex_project_manager_report_service::run();
        } catch (Throwable $e) {
            rex_project_manager_report_service::logFailure($e);
            $this->setMessage($e->getMessage());

            return false;
        }

        $this->setMessage(implode('; ', $result['messages']));

        return !$result['failed'];
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
