<?php

namespace Pinoox\Terminal\DevDB;

use Pinoox\Component\Terminal;
use Pinoox\Terminal\DevDB\Concerns\InteractsWithDevDbCli;
use Pinoox\Terminal\DevDB\Concerns\UsesDevDbStore;
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
            ->addArgument('table', InputArgument::OPTIONAL, 'Table name')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Rows to show', 10)
            ->addOption('structure', null, InputOption::VALUE_NONE, 'Show columns only')
            ->addOption('data', null, InputOption::VALUE_NONE, 'Show rows only')
            ->addOption('relations', null, InputOption::VALUE_NONE, 'Show foreign keys and indexes only')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON')
            ->addOption('no-interaction', 'n', InputOption::VALUE_NONE, 'Disable prompts');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);
        $interactive = $input->isInteractive() && !$input->getOption('no-interaction');
        $limit = (int) $input->getOption('limit');

        if (!$this->bootstrapRuntime($input, $io)) {
            return Command::FAILURE;
        }

        $runtime = $this->runtime();

        $table = $this->resolveTableName($io, $runtime, $input->getArgument('table'), $interactive);
        if ($table === null) {
            return Command::FAILURE;
        }

        try {
            $inspect = $this->describeTable($runtime, $table, $limit);
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

        return Command::SUCCESS;
    }
}
