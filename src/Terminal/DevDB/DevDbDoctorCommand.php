<?php

namespace Pinoox\Terminal\DevDB;

use Pinoox\Component\Database\DevDB\DevDbDoctor;
use Pinoox\Component\Terminal;
use Pinoox\Terminal\DevDB\Concerns\UsesDevDbStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'devdb:doctor', description: 'Check Pinoox DevDB health')]
class DevDbDoctorCommand extends Terminal
{
    use UsesDevDbStore;

    protected function configure(): void
    {
        $this
            ->configureConnectionOptions($this)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);

        if (!$this->bootstrapRuntime($input, $io)) {
            return Command::FAILURE;
        }

        $result = (new DevDbDoctor($this->store()))->inspect();

        if ($input->getOption('json')) {
            $io->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $io->title('DevDB Doctor');
        $io->definitionList(
            ['Path' => $result['path']],
            ['Schema version' => (string) $result['schema_version']],
            ['Status' => $result['ok'] ? 'OK' : 'Needs repair'],
        );

        if ($result['issues'] !== []) {
            $io->section('Issues');
            foreach ($result['issues'] as $issue) {
                $io->warning($issue['message']);
            }

            return Command::FAILURE;
        }

        $io->success('DevDB looks healthy.');

        return Command::SUCCESS;
    }
}
