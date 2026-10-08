<?php

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Gibt Betreff und Text der Status-Mail aus, ohne etwas zu versenden.
 */
class rex_command_project_manager_report_preview extends rex_console_command
{
    protected function configure(): void
    {
        $this
            ->setDescription('Shows subject and body of the project manager status mail without sending it')
            ->addOption('stale-hours', null, InputOption::VALUE_REQUIRED, 'Hours after which the last successful fetch counts as outdated', (string) rex_project_manager_report_collector::DEFAULT_STALE_HOURS)
            ->addOption('syslog-ignore', null, InputOption::VALUE_REQUIRED, 'Comma separated syslog types that are not a finding', implode(',', rex_project_manager_report_collector::DEFAULT_SYSLOG_IGNORE))
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'machine (compact mail), report-text or report-html', 'machine')
            ->addOption('ignore-message', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Substring of "type message file:line" that is not a finding (repeatable)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = (string) $input->getOption('format');
        if (!in_array($format, ['machine', 'report-text', 'report-html'], true)) {
            $this->getStyle($input, $output)->error('Unknown format "' . $format . '", use machine, report-text or report-html.');

            return 1;
        }

        $projects = rex_project_manager_report_collector::collect(
            max(1, (int) $input->getOption('stale-hours')),
            rex_project_manager_report_collector::parseList((string) $input->getOption('syslog-ignore')),
            rex_project_manager_report_collector::parseLines(implode("\n", (array) $input->getOption('ignore-message'))),
        );
        $now = new DateTimeImmutable();

        if ('machine' === $format) {
            $mail = rex_project_manager_report_builder::buildMachine($projects, $now);
            $body = $mail['body'];
        } else {
            $mail = rex_project_manager_report_builder::buildReport($projects, $now);
            $body = 'report-html' === $format ? $mail['html'] : $mail['text'];
        }

        $output->writeln('Subject: ' . $mail['subject']);
        $output->writeln('');
        $output->write($body, false, OutputInterface::OUTPUT_RAW);

        return 0;
    }
}
