<?php

namespace Pinoox\Terminal\DevDB;

use Pinoox\Component\Database\DevDB\DevDbMySqlExporter;
use Pinoox\Component\Terminal;
use Pinoox\Terminal\DevDB\Concerns\UsesDevDbStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'devdb:export:mysql', description: 'Export Pinoox DevDB as a MySQL SQL dump')]
class DevDbExportMySqlCommand extends Terminal
{
    use UsesDevDbStore;

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::OPTIONAL, 'Optional output SQL file')
            ->addOption('no-drop', null, InputOption::VALUE_NONE, 'Do not emit DROP TABLE statements');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);
        $sql = (new DevDbMySqlExporter())->toSql($this->runtime()->export(), !$input->getOption('no-drop'));
        $file = $input->getArgument('file');

        if (is_string($file) && $file !== '') {
            $dir = dirname($file);
            if ($dir !== '.' && !is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            file_put_contents($file, $sql);
            $io->success('DevDB MySQL export written: ' . $file);

            return Command::SUCCESS;
        }

        $io->writeln($sql);

        return Command::SUCCESS;
    }
}
