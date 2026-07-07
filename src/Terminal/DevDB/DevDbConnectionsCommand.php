<?php

namespace Pinoox\Terminal\DevDB;

use Pinoox\Component\Database\DevDB\DevDbConnectionCatalog;
use Pinoox\Component\Terminal;
use Pinoox\Terminal\DevDB\Concerns\UsesDevDbStore;
use Pinoox\Terminal\DevDB\Support\DevDbCliPresenter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'devdb:connections', description: 'List configured Pinoox DevDB connections')]
class DevDbConnectionsCommand extends Terminal
{
    use UsesDevDbStore;

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);
        $entries = DevDbConnectionCatalog::all();

        if ($input->getOption('json')) {
            $io->writeln(json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return Command::SUCCESS;
        }

        $io->title('Pinoox DevDB connections');
        DevDbCliPresenter::renderConnectionCatalog($io, $entries);

        return Command::SUCCESS;
    }
}
