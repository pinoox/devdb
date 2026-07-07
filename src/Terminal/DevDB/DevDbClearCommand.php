<?php

namespace Pinoox\Terminal\DevDB;

use Pinoox\Component\Terminal;
use Pinoox\Terminal\DevDB\Concerns\UsesDevDbStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'devdb:clear', description: 'Clear Pinoox DevDB JSON storage')]
class DevDbClearCommand extends Terminal
{
    use UsesDevDbStore;

    protected function configure(): void
    {
        $this
            ->configureConnectionOptions($this)
            ->addOption('force', null, InputOption::VALUE_NONE, 'Clear without confirmation')
            ->addOption('no-snapshot', null, InputOption::VALUE_NONE, 'Do not create a snapshot before clearing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);

        if (!$this->bootstrapRuntime($input, $io)) {
            return Command::FAILURE;
        }

        $runtime = $this->runtime();
        $target = $runtime->connectionName() ?? $runtime->path();

        if (!$input->getOption('force') && !$io->confirm('Clear Pinoox DevDB data for ' . $target . '?', false)) {
            $io->warning('Cancelled.');

            return Command::SUCCESS;
        }

        if (!$input->getOption('no-snapshot')) {
            $this->store()->snapshot('before-clear-' . date('Ymd_His'));
        }

        $this->runtime()->clear();
        $io->success('Pinoox DevDB cleared for ' . $target . '.');

        return Command::SUCCESS;
    }
}
