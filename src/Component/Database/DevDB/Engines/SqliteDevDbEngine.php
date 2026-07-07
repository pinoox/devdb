<?php

namespace Pinoox\Component\Database\DevDB\Engines;

use PDO;
use Pinoox\Component\Database\DevDB\DevDbException;
use Pinoox\Component\Database\DevDB\DevDbStore;

final class SqliteDevDbEngine implements DevDbEngineInterface
{
    public function __construct(private string $path, private string $database)
    {
    }

    public function name(): string
    {
        return 'sqlite';
    }

    public function path(): string
    {
        return $this->path;
    }

    public function database(): string
    {
        return $this->database;
    }

    public function status(): array
    {
        $tables = [];
        if (is_file($this->database)) {
            foreach ($this->sqliteTables() as $table) {
                $tables[] = [
                    'table' => $table,
                    'columns' => count($this->sqliteColumns($table)),
                    'rows' => (int) $this->pdo()->query('SELECT COUNT(*) AS count FROM "' . str_replace('"', '""', $table) . '"')->fetch()['count'],
                    'primary_key' => $this->sqlitePrimaryKey($table),
                    'indexes' => 0,
                    'data_size' => filesize($this->database) ?: 0,
                ];
            }
        }

        return [
            'engine' => $this->name(),
            'path' => $this->path,
            'database' => $this->database,
            'schema_version' => DevDbStore::SCHEMA_VERSION,
            'table_count' => count($tables),
            'row_count' => array_sum(array_map(static fn (array $table): int => (int) $table['rows'], $tables)),
            'data_size' => is_file($this->database) ? filesize($this->database) ?: 0 : 0,
            'tables' => $tables,
            'migration_count' => 0,
        ];
    }

    public function inspectTable(string $table, int $limit = 10): array
    {
        $columns = $this->sqliteColumns($table);
        if ($columns === []) {
            throw new DevDbException('DevDB table "' . $table . '" does not exist.');
        }

        $quoted = '"' . str_replace('"', '""', $table) . '"';
        $rows = $this->pdo()->query('SELECT * FROM ' . $quoted . ' LIMIT ' . max(0, $limit))->fetchAll();
        $count = (int) $this->pdo()->query('SELECT COUNT(*) AS count FROM ' . $quoted)->fetch()['count'];

        return [
            'table' => $table,
            'columns' => $columns,
            'indexes' => $this->sqliteIndexes($table),
            'primary_key' => $this->sqlitePrimaryKey($table),
            'rows' => $rows,
            'row_count' => $count,
        ];
    }

    public function export(): array
    {
        $schema = [
            'version' => DevDbStore::SCHEMA_VERSION,
            'generated_by' => 'pinoox-devdb-sqlite',
            'tables' => [],
        ];
        $data = [];

        foreach ($this->sqliteTables() as $table) {
            $schema['tables'][$table] = [
                'columns' => $this->sqliteColumns($table),
                'indexes' => [],
                'primary_key' => $this->sqlitePrimaryKey($table),
            ];
            $data[$table] = $this->pdo()->query('SELECT * FROM "' . str_replace('"', '""', $table) . '"')->fetchAll();
        }

        return [
            'schema' => $schema,
            'data' => $data,
            'meta' => [
                'engine' => $this->name(),
                'database' => $this->database,
            ],
        ];
    }

    public function clear(): void
    {
        if (!is_file($this->database)) {
            return;
        }

        $pdo = $this->pdo();
        foreach ($this->sqliteTables() as $table) {
            $pdo->exec('DROP TABLE IF EXISTS "' . str_replace('"', '""', $table) . '"');
        }
    }

    private function pdo(): PDO
    {
        $dir = dirname($this->database);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return new PDO('sqlite:' . $this->database, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    private function sqliteTables(): array
    {
        if (!is_file($this->database)) {
            return [];
        }

        $rows = $this->pdo()->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll();

        return array_map(static fn ($row) => (string) $row['name'], $rows);
    }

    private function sqliteColumns(string $table): array
    {
        if (!is_file($this->database)) {
            return [];
        }

        $statement = $this->pdo()->query('PRAGMA table_info("' . str_replace('"', '""', $table) . '")');
        if ($statement === false) {
            return [];
        }

        $columns = [];
        foreach ($statement->fetchAll() as $column) {
            $columns[(string) $column['name']] = [
                'type' => strtolower((string) $column['type']),
                'nullable' => (int) $column['notnull'] === 0,
                'default' => $column['dflt_value'],
                'primary' => (int) $column['pk'] > 0,
            ];
        }

        return $columns;
    }

    private function sqlitePrimaryKey(string $table): ?string
    {
        foreach ($this->sqliteColumns($table) as $name => $column) {
            if (!empty($column['primary'])) {
                return (string) $name;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sqliteIndexes(string $table): array
    {
        if (!is_file($this->database)) {
            return [];
        }

        $indexes = [];
        $primary = $this->sqlitePrimaryKey($table);
        if ($primary !== null) {
            $indexes[] = [
                'name' => 'primary',
                'index' => 'primary',
                'columns' => [$primary],
            ];
        }

        $quoted = '"' . str_replace('"', '""', $table) . '"';
        $statement = $this->pdo()->query('PRAGMA index_list(' . $quoted . ')');
        if ($statement !== false) {
            foreach ($statement->fetchAll() as $index) {
                $name = (string) ($index['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $columns = $this->sqliteIndexColumns($name);
                $indexes[] = [
                    'name' => ((int) ($index['unique'] ?? 0)) === 1 ? 'unique' : 'index',
                    'index' => $name,
                    'columns' => $columns,
                ];
            }
        }

        foreach ($this->sqliteForeignKeys($table) as $foreignKey) {
            $indexes[] = $foreignKey;
        }

        return $indexes;
    }

    /**
     * @return list<string>
     */
    private function sqliteIndexColumns(string $indexName): array
    {
        $quoted = '"' . str_replace('"', '""', $indexName) . '"';
        $statement = $this->pdo()->query('PRAGMA index_info(' . $quoted . ')');
        if ($statement === false) {
            return [];
        }

        $columns = [];
        foreach ($statement->fetchAll() as $column) {
            $name = (string) ($column['name'] ?? '');
            if ($name !== '') {
                $columns[] = $name;
            }
        }

        return $columns;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sqliteForeignKeys(string $table): array
    {
        if (!is_file($this->database)) {
            return [];
        }

        $quoted = '"' . str_replace('"', '""', $table) . '"';
        $statement = $this->pdo()->query('PRAGMA foreign_key_list(' . $quoted . ')');
        if ($statement === false) {
            return [];
        }

        $foreignKeys = [];
        foreach ($statement->fetchAll() as $row) {
            $from = (string) ($row['from'] ?? '');
            if ($from === '') {
                continue;
            }

            $foreignKeys[] = [
                'name' => 'foreign',
                'index' => $table . '_' . $from . '_foreign',
                'columns' => [$from],
                'on' => (string) ($row['table'] ?? ''),
                'references' => [(string) ($row['to'] ?? '')],
                'onUpdate' => (string) ($row['on_update'] ?? ''),
                'onDelete' => (string) ($row['on_delete'] ?? ''),
            ];
        }

        return $foreignKeys;
    }
}
