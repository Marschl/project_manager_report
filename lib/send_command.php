<?php

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Manueller Versand der Statusmails wie im Cronjob. Der Cronjob rückt den gemeldeten Stand immer vor;
 * hier lässt sich das für Versende im Betrieb abschalten.
 */
class rex_command_project_manager_report_send extends rex_console_command
{
    protected function configure(): void
    {
        $this
            ->setDescription('Sends the project manager status mails like the cronjob (state and syslog are always updated)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Send to the test address only and store nothing')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Send to this address instead of the configured recipients (implies --no-advance-baseline)')
            ->addOption('no-advance-baseline', null, InputOption::VALUE_NONE, 'Do not mark the sent state as reported (the next mail repeats these changes)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getStyle($input, $output);
        if (!rex_project_manager_report_store::exists()) {
            $io->error('The state tables are missing, please reinstall the addon.');

            return 1;
        }

        $options = ['advance' => !$input->getOption('no-advance-baseline')];
        if ($input->getOption('dry-run')) {
            $options['dry_run'] = true;
        }
        if (null !== $input->getOption('to')) {
            $to = rex_project_manager_report_collector::parseList((string) $input->getOption('to'));
            if ([] === $to || [] !== rex_project_manager_report_collector::invalidAddresses($to)) {
                $io->error('Invalid address for --to.');

                return 1;
            }
            $options['to'] = $to;
        }

        $result = rex_project_manager_report_service::run($options);
        $text = implode("\n", $result['messages']);
        $result['failed'] ? $io->error($text) : $io->success($text);

        return $result['failed'] ? 1 : 0;
    }
}
