<?php

namespace Pinoox\Terminal\DevDB\Concerns;

use Pinoox\Component\Database\DevDB\DevDbRuntime;
use Pinoox\Component\Database\DevDB\DevDbTableDescriptor;
use Pinoox\Terminal\DevDB\Support\DevDbCliPresenter;
use Symfony\Component\Console\Style\SymfonyStyle;

trait InteractsWithDevDbCli
{
    protected function resolveTableName(SymfonyStyle $io, DevDbRuntime $runtime, ?string $table, bool $interactive): ?string
    {
        $table = trim((string) $table);
        if ($table !== '') {
            return $table;
        }

        if (!$interactive) {
            $io->error('Table name is required in non-interactive mode.');

            return null;
        }

        return $this->selectTable($io, $runtime);
    }

    protected function selectTable(SymfonyStyle $io, DevDbRuntime $runtime, ?string $prompt = null): ?string
    {
        $status = $runtime->status();
        $choices = DevDbCliPresenter::buildTableChoices($status['tables'] ?? []);

        if ($choices === []) {
            $io->warning('No tables found in DevDB.');

            return null;
        }

        $labels = array_values($choices);
        $selected = $io->choice($prompt ?? 'Select a table', $labels);
        $table = array_search($selected, $choices, true);

        return is_string($table) ? $table : null;
    }

    protected function describeTable(DevDbRuntime $runtime, string $table, int $limit = 10): array
    {
        return DevDbTableDescriptor::describe($runtime, $table, $limit);
    }

    protected function askRowLimit(SymfonyStyle $io, int $default = 10): int
    {
        $value = trim((string) $io->ask('How many rows should be shown?', (string) $default));

        if ($value === '' || !ctype_digit($value)) {
            return max(0, $default);
        }

        return max(0, (int) $value);
    }

    protected function askExploreAction(SymfonyStyle $io): string
    {
        return $io->choice('What would you like to explore?', [
            'List tables',
            'Table structure',
            'Table data',
            'Foreign keys and indexes',
            'Full table inspect',
            'Database status',
            'Switch DevDB connection',
            'Exit',
        ], 'List tables');
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return array<string, mixed>|null
     */
    protected function selectConnection(SymfonyStyle $io, array $entries, ?string $prompt = null): ?array
    {
        if ($entries === []) {
            $io->warning('No DevDB connections were found in platform or app config.');

            return null;
        }

        $choices = [];
        foreach ($entries as $entry) {
            $label = DevDbCliPresenter::connectionChoiceLabel($entry);
            $choices[$label] = $entry;
        }

        $selected = $io->choice($prompt ?? 'Select a DevDB connection', array_keys($choices));

        return $choices[$selected] ?? null;
    }
}
