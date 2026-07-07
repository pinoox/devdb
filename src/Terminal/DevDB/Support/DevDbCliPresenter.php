<?php

namespace Pinoox\Terminal\DevDB\Support;

use Symfony\Component\Console\Style\SymfonyStyle;

final class DevDbCliPresenter
{
    public static function renderStatusHeader(SymfonyStyle $io, array $status, ?string $connection = null): void
    {
        $title = 'Pinoox DevDB';
        if (is_string($connection) && $connection !== '') {
            $title .= ' · ' . $connection;
        }

        DevDbCliTheme::banner($io, $title, (string) ($status['path'] ?? ''));

        $io->definitionList(
            ['Connection' => (string) ($connection ?? $status['connection'] ?? '-')],
            ['Engine' => (string) ($status['engine'] ?? 'json')],
            ['Database' => (string) ($status['database'] ?? '-')],
            ['Schema' => (string) ($status['schema_version'] ?? '-')],
        );

        DevDbCliTheme::statLine($io, [
            'tables' => DevDbCliTheme::formatNumber((int) ($status['table_count'] ?? 0)),
            'rows' => DevDbCliTheme::formatNumber((int) ($status['row_count'] ?? 0)),
            'migrations' => DevDbCliTheme::formatNumber((int) ($status['migration_count'] ?? 0)),
            'data size' => DevDbCliTheme::formatBytes((int) ($status['data_size'] ?? 0)),
        ]);
    }

    /**
     * @param list<array<string, mixed>> $entries
     */
    public static function renderConnectionCatalog(SymfonyStyle $io, array $entries): void
    {
        if ($entries === []) {
            $io->warning('No DevDB connections were found.');

            return;
        }

        DevDbCliTheme::banner($io, 'DevDB connections', count($entries) . ' configured store(s)');

        $io->table(
            ['Name', 'Source', 'Path', 'Engine', 'Prefix', 'Shared'],
            array_map(static fn (array $entry) => [
                DevDbCliTheme::truncate((string) ($entry['name'] ?? '-'), 24),
                self::connectionSourceLabel($entry),
                DevDbCliTheme::truncate((string) ($entry['path'] ?? '-'), 36),
                (string) ($entry['engine'] ?? 'auto'),
                (string) ($entry['prefix'] ?? '-'),
                !empty($entry['shared_path']) ? 'yes' : 'no',
            ], $entries),
        );
        $io->note('Use --connection=<name> or --path=<storage-path> with devdb CLI commands.');
    }

    /**
     * @param array<string, mixed> $entry
     */
    public static function connectionChoiceLabel(array $entry): string
    {
        $label = (string) ($entry['label'] ?? $entry['name'] ?? 'DevDB');
        $engine = (string) ($entry['engine'] ?? 'auto');
        $path = (string) ($entry['path'] ?? '');

        return sprintf('%s [%s] — %s', $label, $engine, DevDbCliTheme::truncate($path, 40));
    }

    /**
     * @param array<string, mixed> $entry
     */
    public static function connectionSourceLabel(array $entry): string
    {
        if (($entry['source'] ?? '') === 'app') {
            return 'app:' . (string) ($entry['package'] ?? '-');
        }

        return 'platform';
    }

    public static function renderTables(SymfonyStyle $io, array $status, bool $withHint = true): void
    {
        $tables = $status['tables'] ?? [];
        $rows = [];
        $index = 1;

        foreach ($tables as $table) {
            if (!is_array($table)) {
                continue;
            }

            $rows[] = [
                (string) $index,
                (string) ($table['table'] ?? '-'),
                (string) ($table['columns'] ?? 0),
                DevDbCliTheme::formatNumber((int) ($table['rows'] ?? 0)),
                (string) ($table['primary_key'] ?? '-'),
                (string) ($table['indexes'] ?? 0),
            ];
            $index++;
        }

        if ($rows === []) {
            $io->warning('No tables found in DevDB.');

            return;
        }

        DevDbCliTheme::banner($io, 'Tables', DevDbCliTheme::formatNumber(count($rows)) . ' table(s)');
        $io->table(['#', 'Table', 'Columns', 'Rows', 'Primary key', 'Indexes'], $rows);

        if ($withHint) {
            $io->note('Try `php pinoox devdb:explore` for an interactive browser, or `devdb:inspect <table> --page=2`.');
        }
    }

    public static function renderTableOverview(SymfonyStyle $io, array $inspect, string $view = 'all'): void
    {
        $table = (string) ($inspect['table'] ?? '-');
        $meta = DevDbCliPager::meta(
            (int) ($inspect['offset'] ?? 0),
            (int) ($inspect['limit'] ?? DevDbCliPager::DEFAULT_PER_PAGE),
            (int) ($inspect['row_count'] ?? 0),
        );

        DevDbCliTheme::banner(
            $io,
            'Table · ' . $table,
            DevDbCliPager::label($meta) . ' · PK ' . (string) ($inspect['primary_key'] ?? '-'),
        );

        $io->definitionList(
            ['Foreign keys' => (string) count($inspect['foreign_keys'] ?? [])],
            ['Indexes' => (string) count($inspect['indexes_list'] ?? []) + count($inspect['unique_indexes'] ?? [])],
        );

        if (in_array($view, ['all', 'structure'], true)) {
            self::renderColumns($io, $inspect);
        }

        if (in_array($view, ['all', 'relations'], true)) {
            self::renderRelations($io, $inspect);
        }

        if (in_array($view, ['all', 'data'], true)) {
            self::renderRows($io, $inspect);
        }
    }

    public static function renderColumns(SymfonyStyle $io, array $inspect): void
    {
        $columns = $inspect['columns'] ?? [];
        if ($columns === []) {
            return;
        }

        $rows = [];
        foreach ($columns as $name => $column) {
            if (!is_array($column)) {
                continue;
            }

            $rows[] = [
                (string) $name,
                (string) ($column['type'] ?? 'string'),
                !empty($column['primary']) ? '●' : '',
                !empty($column['nullable']) ? 'yes' : 'no',
                !empty($column['auto_increment']) ? 'yes' : 'no',
                DevDbCliTheme::truncate($column['default'] ?? '-', 28),
            ];
        }

        $io->section('Columns');
        $io->table(['Column', 'Type', 'PK', 'Null', 'Auto', 'Default'], $rows);
    }

    public static function renderRelations(SymfonyStyle $io, array $inspect): void
    {
        $foreignKeys = $inspect['foreign_keys'] ?? [];
        if ($foreignKeys !== []) {
            $io->section('Foreign keys');
            $io->table(
                ['Name', 'Columns', 'References', 'On update', 'On delete'],
                array_map(static fn (array $foreignKey) => [
                    DevDbCliTheme::truncate((string) ($foreignKey['name'] ?? '-'), 28),
                    implode(', ', $foreignKey['columns'] ?? []),
                    self::formatReference($foreignKey),
                    (string) ($foreignKey['on_update'] ?? '-'),
                    (string) ($foreignKey['on_delete'] ?? '-'),
                ], $foreignKeys),
            );
        }

        $indexes = array_merge(
            $inspect['unique_indexes'] ?? [],
            $inspect['indexes_list'] ?? [],
        );

        if ($indexes !== []) {
            $io->section('Indexes');
            $io->table(
                ['Type', 'Name', 'Columns'],
                array_map(static fn (array $index) => [
                    (string) ($index['type'] ?? 'index'),
                    DevDbCliTheme::truncate((string) ($index['name'] ?? '-'), 32),
                    implode(', ', $index['columns'] ?? []),
                ], $indexes),
            );
        }

        if ($foreignKeys === [] && $indexes === []) {
            $io->section('Relations');
            $io->text('  No foreign keys or indexes defined for this table.');
        }
    }

    public static function renderRows(SymfonyStyle $io, array $inspect, bool $showFooter = true): void
    {
        $rows = $inspect['rows'] ?? [];
        $meta = DevDbCliPager::meta(
            (int) ($inspect['offset'] ?? 0),
            (int) ($inspect['limit'] ?? DevDbCliPager::DEFAULT_PER_PAGE),
            (int) ($inspect['row_count'] ?? 0),
        );

        $io->section('Rows · ' . DevDbCliPager::label($meta));

        if ($rows === []) {
            $io->text('  No rows on this page.');

            return;
        }

        $columns = array_keys($rows[0]);
        $tableRows = [];
        $rowNumber = (int) ($meta['from'] ?? 1);

        foreach ($rows as $row) {
            $line = [(string) $rowNumber];
            foreach ($columns as $column) {
                $line[] = DevDbCliTheme::truncate($row[$column] ?? null, 40);
            }
            $tableRows[] = $line;
            $rowNumber++;
        }

        $io->table(array_merge(['#'], $columns), $tableRows);

        if ($showFooter && ($meta['has_next'] ?? false)) {
            $io->note('More rows available — use --page, --offset, or browse interactively in devdb:explore.');
        }
    }

    /**
     * @param array<int, array<string, mixed>> $tables
     */
    public static function buildTableChoices(array $tables): array
    {
        $choices = [];

        foreach ($tables as $table) {
            $name = (string) ($table['table'] ?? '');
            if ($name === '') {
                continue;
            }

            $choices[$name] = sprintf(
                '%s  ·  %s rows  ·  %s cols',
                $name,
                DevDbCliTheme::formatNumber((int) ($table['rows'] ?? 0)),
                DevDbCliTheme::formatNumber((int) ($table['columns'] ?? 0)),
            );
        }

        return $choices;
    }

    private static function formatReference(array $foreignKey): string
    {
        $table = (string) ($foreignKey['referenced_table'] ?? '-');
        $columns = $foreignKey['referenced_columns'] ?? [];

        if ($columns === []) {
            return $table;
        }

        return $table . '(' . implode(', ', $columns) . ')';
    }
}
