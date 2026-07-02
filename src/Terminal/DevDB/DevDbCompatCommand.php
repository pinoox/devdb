<?php

namespace Pinoox\Terminal\DevDB;

use Pinoox\Component\Database\DevDB\DevDbCompatibilityReport;
use Pinoox\Component\Terminal;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'devdb:compat', description: 'Show DevDB compatibility report')]
class DevDbCompatCommand extends Terminal
{
    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);
        $report = (new DevDbCompatibilityReport())->mysql();

        if ($input->getOption('json')) {
            $io->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $io->title('DevDB Compatibility');
        $io->definitionList(
            ['Target' => $report['target']],
            ['Supported' => (string) $report['summary']['supported']],
            ['Partial' => (string) $report['summary']['partial']],
            ['Metadata only' => (string) $report['summary']['metadata_only']],
            ['Unsupported' => (string) $report['summary']['unsupported']],
        );
        $io->table(['Area', 'Feature', 'Status'], array_map(
            fn (array $feature): array => [$feature['area'], $feature['feature'], $feature['status']],
            $report['features'],
        ));
        $io->note($report['recommendation']);

        return Command::SUCCESS;
    }
}
