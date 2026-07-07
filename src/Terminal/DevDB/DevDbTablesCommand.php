<?php

namespace Pinoox\Terminal\DevDB;

use Pinoox\Component\Terminal;
use Pinoox\Terminal\DevDB\Concerns\InteractsWithDevDbCli;
use Pinoox\Terminal\DevDB\Concerns\UsesDevDbStore;
use Pinoox\Terminal\DevDB\Support\DevDbCliPager;
use Pinoox\Terminal\DevDB\Support\DevDbCliPresenter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'devdb:tables', description: 'List Pinoox DevDB tables')]
class DevDbTablesCommand extends Terminal
{
    use InteractsWithDevDbCli;
    use UsesDevDbStore;

    protected function configure(): void
    {
        $this
            ->configureConnectionOptions($this)
            ->configurePaginationOptions($this)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON')
            ->addOption('inspect', 'i', InputOption::VALUE_NONE, 'Prompt to inspect a selected table')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Rows per page after --inspect', DevDbCliPager::DEFAULT_PER_PAGE)
            ->addOption('browse', 'b', InputOption::VALUE_NONE, 'Open paginated row browser after --inspect')
            ->addOption('no-interaction', 'n', InputOption::VALUE_NONE, 'Disable prompts');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);
        $interactive = $input->isInteractive() && !$input->getOption('no-interaction');

        if (!$this->bootstrapRuntime($input, $io)) {
            return Command::FAILURE;
        }

        $runtime = $this->runtime();
        $status = $runtime->status();

        if ($input->getOption('json')) {
            $io->writeln(json_encode($status['tables'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return Command::SUCCESS;
        }

        DevDbCliPresenter::renderStatusHeader($io, $status, $runtime->connectionName());
        DevDbCliPresenter::renderTables($io, $status, !$input->getOption('inspect'));

        if (!$input->getOption('inspect')) {
            return Command::SUCCESS;
        }

        if (!$interactive) {
            $io->error('The --inspect option requires an interactive terminal.');

            return Command::FAILURE;
        }

        $table = $this->selectTable($io, $runtime, 'Select a table to inspect');
        if ($table === null) {
            return Command::SUCCESS;
        }

        $perPage = DevDbCliPager::normalizePerPage((int) $input->getOption('limit'));

        if ($input->getOption('browse')) {
            $this->browseTableData($io, $runtime, $table, $perPage);

            return Command::SUCCESS;
        }

        [$offset] = $this->resolvePagination($input);

        try {
            $inspect = $this->describeTable($runtime, $table, $perPage, $offset);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        DevDbCliPresenter::renderTableOverview($io, $inspect, 'all');

        return Command::SUCCESS;
    }
}
