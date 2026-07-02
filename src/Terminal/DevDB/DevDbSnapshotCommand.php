<?php

namespace Pinoox\Terminal\DevDB;

use Pinoox\Component\Terminal;
use Pinoox\Terminal\DevDB\Concerns\UsesDevDbStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'devdb:snapshot', description: 'Create, list, restore, or delete DevDB snapshots')]
class DevDbSnapshotCommand extends Terminal
{
    use UsesDevDbStore;

    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::OPTIONAL, 'create, list, restore, or delete', 'create')
            ->addArgument('name', InputArgument::OPTIONAL, 'Snapshot name')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);
        $action = strtolower((string) $input->getArgument('action'));
        $name = $input->getArgument('name');
        $name = is_string($name) && $name !== '' ? $name : null;

        try {
            $result = match ($action) {
                'create' => $this->store()->snapshot($name),
                'list' => $this->store()->snapshots(),
                'restore' => $this->restore($name),
                'delete' => $this->delete($name),
                default => throw new \InvalidArgumentException('Unknown snapshot action: ' . $action),
            };
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        if ($input->getOption('json')) {
            $io->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        if ($action === 'list') {
            $io->table(['Name', 'Created at', 'Tables'], array_map(static fn (array $snapshot): array => [
                $snapshot['name'] ?? '',
                $snapshot['created_at'] ?? '',
                implode(', ', (array) ($snapshot['tables'] ?? [])),
            ], $result));

            return Command::SUCCESS;
        }

        $io->success('DevDB snapshot action completed: ' . $action);

        return Command::SUCCESS;
    }

    private function restore(?string $name): array
    {
        if ($name === null) {
            throw new \InvalidArgumentException('Snapshot name is required for restore.');
        }

        $this->store()->restoreSnapshot($name);

        return ['restored' => $name];
    }

    private function delete(?string $name): array
    {
        if ($name === null) {
            throw new \InvalidArgumentException('Snapshot name is required for delete.');
        }

        return ['deleted' => $name, 'ok' => $this->store()->deleteSnapshot($name)];
    }
}
