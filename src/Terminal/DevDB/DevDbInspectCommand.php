<?php

namespace Pinoox\Terminal\DevDB;

use Pinoox\Component\Terminal;
use Pinoox\Terminal\DevDB\Concerns\InteractsWithDevDbCli;
use Pinoox\Terminal\DevDB\Concerns\UsesDevDbStore;
use Pinoox\Terminal\DevDB\Support\DevDbCliPager;
use Pinoox\Terminal\DevDB\Support\DevDbCliPresenter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'devdb:inspect', description: 'Inspect a Pinoox DevDB table')]
class DevDbInspectCommand extends Terminal
{
    use InteractsWithDevDbCli;
    use UsesDevDbStore;

    protected function configure(): void
    {
        $this
            ->configureConnectionOptions($this)
            ->configurePaginationOptions($this)
            ->addArgument('table', InputArgument::OPTIONAL, 'Table name')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Rows per page (alias of --per-page)', DevDbCliPager::DEFAULT_PER_PAGE)
            ->addOption('structure', null, InputOption::VALUE_NONE, 'Show columns only')
            ->addOption('data', null, InputOption::VALUE_NONE, 'Show rows only')
            ->addOption('relations', null, InputOption::VALUE_NONE, 'Show foreign keys and indexes only')
            ->addOption('browse', 'b', InputOption::VALUE_NONE, 'Interactive paginated row browser')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON')
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

        $table = $this->resolveTableName($io, $runtime, $input->getArgument('table'), $interactive);
        if ($table === null) {
            return Command::FAILURE;
        }

        [$offset, $perPage] = $this->resolvePagination($input);

        if ($input->getOption('browse') && $interactive) {
            $this->browseTableData($io, $runtime, $table, $perPage);

            return Command::SUCCESS;
        }

        try {
            $inspect = $this->describeTable($runtime, $table, $perPage, $offset);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($input->getOption('json')) {
            $io->writeln(json_encode($inspect, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return Command::SUCCESS;
        }

        $view = 'all';
        if ($input->getOption('structure')) {
            $view = 'structure';
        } elseif ($input->getOption('data')) {
            $view = 'data';
        } elseif ($input->getOption('relations')) {
            $view = 'relations';
        }

        if ($runtime->connectionName() !== null) {
            $io->note('Connection: ' . $runtime->connectionName());
        }

        DevDbCliPresenter::renderTableOverview($io, $inspect, $view);

        if ($interactive && $view === 'data' && ($inspect['row_count'] ?? 0) > $perPage) {
            if ($io->confirm('Open interactive row browser?', false)) {
                $this->browseTableData($io, $runtime, $table, $perPage);
            }
        }

        return Command::SUCCESS;
    }
}
