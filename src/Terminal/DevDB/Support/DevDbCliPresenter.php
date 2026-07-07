<?php

namespace Pinoox\Terminal\DevDB\Support;

use Symfony\Component\Console\Style\SymfonyStyle;

final class DevDbCliPresenter
{
    public static function renderStatusHeader(SymfonyStyle $io, array $status, ?string $connection = null): void
    {
        $title = 'Pinoox DevDB';
        if (is_string($connection) && $connection !== '') {
            $title .= ' — ' . $connection;
        }

        $io->title($title);
        $io->definitionList(
            ['Connection' => (string) ($connection ?? $status['connection'] ?? '-')],
            ['Path' => (string) ($status['path'] ?? '-')],
            ['Engine' => (string) ($status['engine'] ?? 'json')],
            ['Database' => (string) ($status['database'] ?? '-')],
            ['Schema version' => (string) ($status['schema_version'] ?? '-')],
            ['Tables' => (string) ($status['table_count'] ?? 0)],
            ['Rows' => (string) ($status['row_count'] ?? 0)],
            ['Migrations' => (string) ($status['migration_count'] ?? 0)],
        );
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

        $io->section('DevDB connections');
        $io->table(
            ['Name', 'Source', 'Path', 'Engine', 'Prefix', 'Shared path'],
            array_map(static fn (array $entry) => [
                (string) ($entry['name'] ?? '-'),
                self::connectionSourceLabel($entry),
                (string) ($entry['path'] ?? '-'),
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
        $path = (string) ($entry['path'] ?? '');
        $suffix = $path !== '' ? ' — ' . $path : '';

        return $label . $suffix;
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
        $rows = array_map(static fn (array $table) => [
            (string) ($table['table'] ?? '-'),
            (string) ($table['columns'] ?? 0),
            (string) ($table['rows'] ?? 0),
            (string) ($table['primary_key'] ?? '-'),
            (string) ($table['indexes'] ?? 0),
        ], $status['tables'] ?? []);

        if ($rows === []) {
            $io->warning('No tables found in DevDB.');

            return;
        }

        $io->section('Tables');
        $io->table(['Table', 'Columns', 'Rows', 'Primary key', 'Indexes'], $rows);

        if ($withHint) {
            $io->note('Use `php pinoox devdb:inspect <table>` or `php pinoox devdb:explore` for details.');
        }
    }

    public static function renderTableOverview(SymfonyStyle $io, array $inspect, string $view = 'all'): void
    {
        $io->title('DevDB table: ' . ($inspect['table'] ?? '-'));
        $io->definitionList(
            ['Rows' => (string) ($inspect['row_count'] ?? 0)],
            ['Primary key' => (string) ($inspect['primary_key'] ?? '-')],
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
                !empty($column['primary']) ? 'yes' : 'no',
                !empty($column['nullable']) ? 'yes' : 'no',
                !empty($column['auto_increment']) ? 'yes' : 'no',
                self::formatScalar($column['default'] ?? '-'),
            ];
        }

        $io->section('Columns');
        $io->table(['Column', 'Type', 'Primary', 'Nullable', 'Auto inc.', 'Default'], $rows);
    }

    public static function renderRelations(SymfonyStyle $io, array $inspect): void
    {
        $foreignKeys = $inspect['foreign_keys'] ?? [];
        if ($foreignKeys !== []) {
            $io->section('Foreign keys');
            $io->table(
                ['Name', 'Columns', 'References', 'On update', 'On delete'],
                array_map(static fn (array $foreignKey) => [
                    (string) ($foreignKey['name'] ?? '-'),
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
                    (string) ($index['name'] ?? '-'),
                    implode(', ', $index['columns'] ?? []),
                ], $indexes),
            );
        }

        if ($foreignKeys === [] && $indexes === []) {
            $io->section('Relations');
            $io->text('No foreign keys or indexes defined for this table.');
        }
    }

    public static function renderRows(SymfonyStyle $io, array $inspect): void
    {
        $rows = $inspect['rows'] ?? [];
        if ($rows === []) {
            $io->section('Rows');
            $io->text('No rows found.');

            return;
        }

        $columns = array_keys($rows[0]);
        $io->section('Rows');
        $io->table($columns, array_map(static fn (array $row) => array_map(
            static fn (string $column) => self::formatScalar($row[$column] ?? null),
            $columns,
        ), $rows));

        $shown = count($rows);
        $total = (int) ($inspect['row_count'] ?? $shown);
        if ($total > $shown) {
            $io->note(sprintf('Showing %d of %d rows. Use --limit to see more.', $shown, $total));
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
                '%s (%d rows, %d columns)',
                $name,
                (int) ($table['rows'] ?? 0),
                (int) ($table['columns'] ?? 0),
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

    private static function formatScalar(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }
}
