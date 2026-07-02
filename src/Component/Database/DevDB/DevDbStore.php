<?php

namespace Pinoox\Component\Database\DevDB;

use Pinoox\Support\SystemConfig;

final class DevDbStore
{
    public const SCHEMA_VERSION = 1;

    private string $root;

    /** @var list<array<string, mixed>> */
    private array $transactionSnapshots = [];

    public function __construct(?string $root = null)
    {
        $this->root = rtrim(str_replace('\\', '/', $root ?? SystemConfig::resolvePath('~/storage/devdb')), '/');
        $this->ensureDirectories();
    }

    public function root(): string
    {
        return $this->root;
    }

    public function hasTable(string $table): bool
    {
        $schema = $this->schema();

        return isset($schema['tables'][$table]);
    }

    public function schema(): array
    {
        return $this->readJson($this->root . '/schema.json', $this->emptySchema());
    }

    public function saveSchema(array $schema): void
    {
        $schema['version'] ??= self::SCHEMA_VERSION;
        $schema['tables'] ??= [];
        $this->writeJson($this->root . '/schema.json', $schema);
        $this->writeJson($this->root . '/meta/indexes.json', $this->buildIndexMetadata($schema));
    }

    public function createTable(string $table, array $columns, array $indexes = []): void
    {
        $schema = $this->schema();
        $schema['tables'][$table] = [
            'columns' => $columns,
            'indexes' => $indexes,
            'primary_key' => $this->primaryKeyFromColumns($columns),
            'updated_at' => date(DATE_ATOM),
        ];
        $this->saveSchema($schema);
        $this->writeJson($this->dataPath($table), $this->readTable($table));

        $sequences = $this->sequences();
        $sequences[$table] ??= 0;
        $this->saveSequences($sequences);
    }

    public function alterTable(string $table, array $columns, array $indexes = []): void
    {
        $schema = $this->schema();
        $current = $schema['tables'][$table] ?? ['columns' => [], 'indexes' => []];
        $current['columns'] = array_replace($current['columns'] ?? [], $columns);
        $current['indexes'] = $this->uniqueIndexes(array_merge($current['indexes'] ?? [], $indexes));
        $current['primary_key'] = $this->primaryKeyFromColumns($current['columns']);
        $current['updated_at'] = date(DATE_ATOM);
        $schema['tables'][$table] = $current;
        $this->saveSchema($schema);
    }

    public function dropTable(string $table): void
    {
        $schema = $this->schema();
        unset($schema['tables'][$table]);
        $this->saveSchema($schema);

        $path = $this->dataPath($table);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function readTable(string $table): array
    {
        return $this->readJson($this->dataPath($table), []);
    }

    public function replaceTable(string $table, array $rows): void
    {
        $this->writeJson($this->dataPath($table), array_values($rows));
    }

    public function pathForTable(string $table): string
    {
        return $this->dataPath($table);
    }

    public function clear(): void
    {
        $this->removeDirectory($this->root);
        $this->ensureDirectories();
        $this->saveSchema($this->emptySchema());
        $this->saveSequences([]);
        $this->writeJson($this->root . '/meta/migrations.json', []);
    }

    public function snapshot(?string $name = null): array
    {
        $name = $this->safeSnapshotName($name ?: date('Ymd_His'));
        $payload = [
            'name' => $name,
            'created_at' => date(DATE_ATOM),
            'export' => $this->export(),
        ];

        $this->writeJson($this->snapshotPath($name), $payload);

        return [
            'name' => $name,
            'created_at' => $payload['created_at'],
            'path' => $this->snapshotPath($name),
            'tables' => array_keys($payload['export']['schema']['tables'] ?? []),
        ];
    }

    public function snapshots(): array
    {
        $dir = $this->root . '/meta/snapshots';
        if (!is_dir($dir)) {
            return [];
        }

        $snapshots = [];
        foreach (glob($dir . '/*.json') ?: [] as $path) {
            $payload = $this->readJson($path, []);
            if ($payload === []) {
                continue;
            }

            $snapshots[] = [
                'name' => (string) ($payload['name'] ?? basename($path, '.json')),
                'created_at' => (string) ($payload['created_at'] ?? ''),
                'path' => $path,
                'tables' => array_keys($payload['export']['schema']['tables'] ?? []),
            ];
        }

        usort($snapshots, static fn (array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']));

        return $snapshots;
    }

    public function restoreSnapshot(string $name): void
    {
        $path = $this->snapshotPath($name);
        $payload = $this->readJson($path, []);
        $export = $payload['export'] ?? null;

        if (!is_array($export)) {
            throw new DevDbException('DevDB snapshot "' . $name . '" does not exist.');
        }

        $this->import($export);
        $this->writeJson($path, $payload);
    }

    public function deleteSnapshot(string $name): bool
    {
        $path = $this->snapshotPath($name);
        if (!is_file($path)) {
            return false;
        }

        return @unlink($path);
    }

    public function currentManifest(): array
    {
        $files = [];
        foreach ($this->trackedFiles() as $path) {
            $relative = ltrim(str_replace($this->root, '', $path), '/\\');
            $files[$relative] = [
                'mtime' => filemtime($path) ?: 0,
                'size' => filesize($path) ?: 0,
                'hash' => sha1_file($path) ?: '',
            ];
        }

        ksort($files);

        return [
            'generated_at' => date(DATE_ATOM),
            'file_count' => count($files),
            'total_size' => array_sum(array_map(static fn (array $file): int => (int) ($file['size'] ?? 0), $files)),
            'files' => $files,
        ];
    }

    public function writeManifest(): array
    {
        $manifest = $this->currentManifest();
        $this->writeJson($this->root . '/meta/manifest.json', $manifest);

        return $manifest;
    }

    public function manifest(): array
    {
        return $this->readJson($this->root . '/meta/manifest.json', []);
    }

    public function hasChangesSinceManifest(): bool
    {
        $saved = $this->manifest();
        if (!isset($saved['files']) || !is_array($saved['files'])) {
            return true;
        }

        return ($saved['files'] ?? []) !== ($this->currentManifest()['files'] ?? []);
    }

    public function status(): array
    {
        $schema = $this->schema();
        $tables = [];

        foreach (($schema['tables'] ?? []) as $table => $meta) {
            $tables[] = [
                'table' => (string) $table,
                'columns' => count($meta['columns'] ?? []),
                'rows' => count($this->readTable((string) $table)),
                'primary_key' => $meta['primary_key'] ?? null,
                'indexes' => count($meta['indexes'] ?? []),
                'data_size' => is_file($this->dataPath((string) $table)) ? filesize($this->dataPath((string) $table)) ?: 0 : 0,
            ];
        }

        return [
            'path' => $this->root,
            'schema_version' => $schema['version'] ?? self::SCHEMA_VERSION,
            'table_count' => count($tables),
            'row_count' => array_sum(array_map(static fn (array $table): int => (int) $table['rows'], $tables)),
            'data_size' => array_sum(array_map(static fn (array $table): int => (int) $table['data_size'], $tables)),
            'has_manifest_changes' => $this->hasChangesSinceManifest(),
            'tables' => $tables,
            'migration_count' => count($this->migrations()),
        ];
    }

    public function inspectTable(string $table, int $limit = 10): array
    {
        $schema = $this->schema();
        $meta = $schema['tables'][$table] ?? null;

        if (!is_array($meta)) {
            throw new DevDbException('DevDB table "' . $table . '" does not exist.');
        }

        return [
            'table' => $table,
            'columns' => $meta['columns'] ?? [],
            'indexes' => $meta['indexes'] ?? [],
            'primary_key' => $meta['primary_key'] ?? null,
            'rows' => array_slice($this->readTable($table), 0, max(0, $limit)),
            'row_count' => count($this->readTable($table)),
        ];
    }

    public function export(): array
    {
        $schema = $this->schema();
        $data = [];

        foreach (array_keys($schema['tables'] ?? []) as $table) {
            $data[$table] = $this->readTable((string) $table);
        }

        return [
            'schema' => $schema,
            'data' => $data,
            'meta' => [
                'migrations' => $this->migrations(),
                'sequences' => $this->sequences(),
                'indexes' => $this->readJson($this->root . '/meta/indexes.json', []),
            ],
        ];
    }

    public function import(array $payload): void
    {
        $this->clear();
        $schema = is_array($payload['schema'] ?? null) ? $payload['schema'] : $this->emptySchema();
        $this->saveSchema($schema);

        foreach ((array) ($payload['data'] ?? []) as $table => $rows) {
            if (is_array($rows)) {
                $this->replaceTable((string) $table, $rows);
            }
        }

        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $this->writeJson($this->root . '/meta/migrations.json', is_array($meta['migrations'] ?? null) ? $meta['migrations'] : []);
        $this->saveSequences(is_array($meta['sequences'] ?? null) ? $meta['sequences'] : []);
        $this->writeJson($this->root . '/meta/indexes.json', is_array($meta['indexes'] ?? null) ? $meta['indexes'] : []);
    }

    public function beginTransaction(): void
    {
        $this->transactionSnapshots[] = $this->export();
    }

    public function commitTransaction(): void
    {
        array_pop($this->transactionSnapshots);
    }

    public function rollbackTransaction(): void
    {
        $snapshot = array_pop($this->transactionSnapshots);
        if (is_array($snapshot)) {
            $this->import($snapshot);
        }
    }

    public function transactionLevel(): int
    {
        return count($this->transactionSnapshots);
    }

    public function nextId(string $table): int
    {
        $sequences = $this->sequences();
        $sequences[$table] = (int) ($sequences[$table] ?? 0) + 1;
        $this->saveSequences($sequences);

        return $sequences[$table];
    }

    public function sequences(): array
    {
        return $this->readJson($this->root . '/meta/sequences.json', []);
    }

    public function saveSequences(array $sequences): void
    {
        $this->writeJson($this->root . '/meta/sequences.json', $sequences);
    }

    public function migrations(): array
    {
        return $this->readJson($this->root . '/meta/migrations.json', []);
    }

    public function recordMigration(string $package, string $migration, int $batch, ?string $checksum = null): void
    {
        $records = $this->migrations();
        foreach ($records as $record) {
            if (($record['package'] ?? null) === $package && ($record['migration'] ?? null) === $migration) {
                return;
            }
        }

        $records[] = [
            'package' => $package,
            'migration' => $migration,
            'batch' => $batch,
            'checksum' => $checksum ?? sha1($package . ':' . $migration),
            'executed_at' => date(DATE_ATOM),
            'created_at' => date(DATE_ATOM),
        ];
        $this->writeJson($this->root . '/meta/migrations.json', $records);
    }

    public function migrationStatus(): array
    {
        $records = $this->migrations();

        usort($records, static fn (array $a, array $b): int => [$a['batch'] ?? 0, $a['migration'] ?? ''] <=> [$b['batch'] ?? 0, $b['migration'] ?? '']);

        return [
            'count' => count($records),
            'last_batch' => $records === [] ? 0 : max(array_map(static fn (array $record): int => (int) ($record['batch'] ?? 0), $records)),
            'records' => $records,
        ];
    }

    private function dataPath(string $table): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $table);

        return $this->root . '/data/' . $safe . '.json';
    }

    private function ensureDirectories(): void
    {
        foreach ([$this->root, $this->root . '/data', $this->root . '/meta', $this->root . '/meta/snapshots'] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }
    }

    private function emptySchema(): array
    {
        return [
            'version' => self::SCHEMA_VERSION,
            'generated_by' => 'pinoox-devdb',
            'tables' => [],
        ];
    }

    private function primaryKeyFromColumns(array $columns): ?string
    {
        foreach ($columns as $name => $column) {
            if (!empty($column['primary']) || !empty($column['auto_increment'])) {
                return (string) $name;
            }
        }

        return null;
    }

    private function buildIndexMetadata(array $schema): array
    {
        $indexes = [];

        foreach (($schema['tables'] ?? []) as $table => $meta) {
            $tableIndexes = [];
            if (!empty($meta['primary_key'])) {
                $tableIndexes['primary'] = [
                    'type' => 'primary',
                    'columns' => [(string) $meta['primary_key']],
                ];
            }

            foreach (($meta['indexes'] ?? []) as $index) {
                $columns = $index['columns'] ?? $index['column'] ?? [];
                $columns = is_array($columns) ? array_values($columns) : [$columns];
                $name = (string) ($index['index'] ?? $index['name'] ?? implode('_', $columns));

                if ($name !== '') {
                    $tableIndexes[$name] = [
                        'type' => (string) ($index['name'] ?? 'index'),
                        'columns' => $columns,
                    ];
                }
            }

            $indexes[(string) $table] = $tableIndexes;
        }

        return $indexes;
    }

    private function uniqueIndexes(array $indexes): array
    {
        $seen = [];
        $unique = [];

        foreach ($indexes as $index) {
            $key = json_encode($index);
            if (!is_string($key) || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $index;
        }

        return $unique;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
    }

    private function snapshotPath(string $name): string
    {
        return $this->root . '/meta/snapshots/' . $this->safeSnapshotName($name) . '.json';
    }

    private function safeSnapshotName(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $name) ?: 'snapshot';

        return trim($name, '._') ?: 'snapshot';
    }

    private function trackedFiles(): array
    {
        $patterns = [
            $this->root . '/schema.json',
            $this->root . '/data/*.json',
            $this->root . '/meta/indexes.json',
            $this->root . '/meta/migrations.json',
            $this->root . '/meta/sequences.json',
        ];
        $files = [];

        foreach ($patterns as $pattern) {
            foreach (glob($pattern) ?: [] as $path) {
                if (is_file($path)) {
                    $files[] = str_replace('\\', '/', $path);
                }
            }
        }

        sort($files);

        return $files;
    }

    private function readJson(string $path, array $default): array
    {
        if (!is_file($path)) {
            return $default;
        }

        $json = file_get_contents($path);
        $data = is_string($json) ? json_decode($json, true) : null;

        return is_array($data) ? $data : $default;
    }

    private function writeJson(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new DevDbException('Unable to write DevDB file: ' . $path);
        }

        try {
            flock($handle, LOCK_EX);
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
