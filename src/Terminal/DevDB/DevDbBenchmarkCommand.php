<?php

namespace Pinoox\Terminal\DevDB;

use Pinoox\Component\Database\DevDB\DevDbBenchmark;
use Pinoox\Component\Terminal;
use Pinoox\DevDB\DevDatabase;
use Pinoox\Terminal\DevDB\Concerns\UsesDevDbStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'devdb:benchmark', description: 'Run a small DevDB development benchmark')]
class DevDbBenchmarkCommand extends Terminal
{
    use UsesDevDbStore;

    protected function configure(): void
    {
        $this
            ->configureConnectionOptions($this)
            ->addOption('rows', null, InputOption::VALUE_REQUIRED, 'Rows to insert during the benchmark', 100)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);

        if (!$this->bootstrapRuntime($input, $io)) {
            return Command::FAILURE;
        }

        $rows = max(1, (int) $input->getOption('rows'));
        $db = DevDatabase::open($this->store()->root());
        $result = (new DevDbBenchmark())->run($db, $rows);

        if ($input->getOption('json')) {
            $io->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $io->title('DevDB Benchmark');
        $io->table(['Metric', 'Value'], [
            ['Rows', (string) $result['rows']],
            ['Schema', $result['schema_ms'] . ' ms'],
            ['Insert', $result['insert_ms'] . ' ms'],
            ['Select', $result['select_ms'] . ' ms'],
            ['Count', $result['count_ms'] . ' ms'],
            ['Total', $result['total_ms'] . ' ms'],
        ]);

        return Command::SUCCESS;
    }
}
