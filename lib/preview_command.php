<?php

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Gibt Betreff und Text der Mails aus (inklusive Delta gegen den zuletzt gemeldeten Stand), ohne etwas zu senden
 * oder zu schreiben.
 */
class rex_command_project_manager_report_preview extends rex_console_command
{
    protected function configure(): void
    {
        $this
            ->setDescription('Shows subject and body of the project manager status mails (including the delta) without sending or storing anything')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'machine (compact mail), report-text or report-html', 'machine')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = (string) $input->getOption('format');
        if (!in_array($format, ['machine', 'report-text', 'report-html'], true)) {
            $this->getStyle($input, $output)->error('Unknown format "' . $format . '", use machine, report-text or report-html.');

            return 1;
        }
        if (!rex_project_manager_report_store::exists()) {
            $this->getStyle($input, $output)->error('The state tables are missing, please reinstall the addon.');

            return 1;
        }

        $mails = rex_project_manager_report_service::prepare()['mails'];
        $mail = 'machine' === $format ? $mails['data'] : $mails['report'];
        $body = 'report-html' === $format ? (string) $mail['html'] : $mail['text'];

        $output->writeln('Subject: ' . $mail['subject']);
        $output->writeln('');
        $output->write($body, false, OutputInterface::OUTPUT_RAW);

        return 0;
    }
}
