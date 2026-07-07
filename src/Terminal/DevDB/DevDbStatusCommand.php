<?php

namespace Pinoox\Terminal\DevDB;

use Pinoox\Component\Database\DevDB\DevDbConnectionCatalog;
use Pinoox\Component\Database\DevDB\DevDbRuntime;
use Pinoox\Component\Terminal;
use Pinoox\Terminal\DevDB\Concerns\UsesDevDbStore;
use Pinoox\Terminal\DevDB\Support\DevDbCliPresenter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'devdb:status', description: 'Show Pinoox DevDB status')]
class DevDbStatusCommand extends Terminal
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

        if ($input->getOption('all')) {
            return $this->renderAllConnections($io, (bool) $input->getOption('json'));
        }

        if (!$this->bootstrapRuntime($input, $io)) {
            return Command::FAILURE;
        }

        $runtime = $this->runtime();
        $status = $runtime->status();

        if ($input->getOption('json')) {
            $io->writeln(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        DevDbCliPresenter::renderStatusHeader($io, $status, $runtime->connectionName());

        if (($status['tables'] ?? []) !== []) {
            $io->section('Tables');
            $io->table(['Table', 'Columns', 'Rows', 'Primary key', 'Indexes'], array_map(static fn ($table) => [
                $table['table'],
                (string) $table['columns'],
                (string) $table['rows'],
                $table['primary_key'] ?? '-',
                (string) ($table['indexes'] ?? 0),
            ], $status['tables']));
        }

        $io->note('Try `php pinoox devdb:explore`, `php pinoox devdb:connections`, or `php pinoox devdb:status --all`.');

        return Command::SUCCESS;
    }

    private function renderAllConnections(SymfonyStyle $io, bool $asJson): int
    {
        $entries = DevDbConnectionCatalog::all();
        $payload = [];

        foreach ($entries as $entry) {
            $runtime = DevDbRuntime::fromCatalogEntry($entry);
            $status = $runtime->status();
            $payload[] = [
                'connection' => $entry['name'] ?? null,
                'label' => $entry['label'] ?? null,
                'source' => $entry['source'] ?? null,
                'package' => $entry['package'] ?? null,
                'path' => $entry['path'] ?? null,
                'shared_path' => $entry['shared_path'] ?? false,
                'status' => $status,
            ];
        }

        if ($asJson) {
            $io->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return Command::SUCCESS;
        }

        $io->title('Pinoox DevDB — all connections');
        DevDbCliPresenter::renderConnectionCatalog($io, $entries);

        foreach ($payload as $item) {
            $io->section((string) ($item['label'] ?? $item['connection'] ?? 'DevDB'));
            DevDbCliPresenter::renderStatusHeader($io, $item['status'], (string) ($item['connection'] ?? ''));
            DevDbCliPresenter::renderTables($io, $item['status'], false);
        }

        return Command::SUCCESS;
    }
}
