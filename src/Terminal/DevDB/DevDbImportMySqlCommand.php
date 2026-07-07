<?php

namespace Pinoox\Terminal\DevDB;

use Pinoox\Component\Terminal;
use Pinoox\DevDB\DevDatabase;
use Pinoox\Terminal\DevDB\Concerns\UsesDevDbStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'devdb:import:mysql', description: 'Import a MySQL SQL dump into Pinoox DevDB')]
class DevDbImportMySqlCommand extends Terminal
{
    use UsesDevDbStore;

    protected function configure(): void
    {
        $this
            ->configureConnectionOptions($this)
            ->addArgument('file', InputArgument::REQUIRED, 'Input SQL dump file')
            ->addOption('loose', null, InputOption::VALUE_NONE, 'Disable strict constraint checks during import')
            ->addOption('no-snapshot', null, InputOption::VALUE_NONE, 'Do not create a snapshot before import');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);

        if (!$this->bootstrapRuntime($input, $io)) {
            return Command::FAILURE;
        }

        $file = (string) $input->getArgument('file');

        if (!is_file($file)) {
            $io->error('SQL dump file does not exist: ' . $file);

            return Command::FAILURE;
        }

        try {
            $db = DevDatabase::open($this->runtime()->path());
            if ($input->getOption('loose')) {
                $db->strict(false);
            }
            if (!$input->getOption('no-snapshot')) {
                $db->snapshot('before-mysql-import-' . date('Ymd_His'));
            }

            $results = $db->executeDump((string) file_get_contents($file));
        } catch (\Throwable $exception) {
            $io->error('DevDB MySQL import failed: ' . $exception->getMessage());

            return Command::FAILURE;
        }

        $io->success('DevDB MySQL import completed. Statements: ' . count($results));

        return Command::SUCCESS;
    }
}
