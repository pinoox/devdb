<?php

namespace Pinoox\Component\Database\DevDB;

use PDO;

final class DevDbMySqlExporter
{
    /**
     * @param array<string,mixed> $export
     */
    public function toSql(array $export, bool $dropTables = true): string
    {
        $schema = is_array($export['schema'] ?? null) ? $export['schema'] : [];
        $data = is_array($export['data'] ?? null) ? $export['data'] : [];
        $statements = [
            'SET NAMES utf8mb4;',
            'SET FOREIGN_KEY_CHECKS=0;',
        ];

        foreach (($schema['tables'] ?? []) as $table => $meta) {
            $table = (string) $table;
            if ($dropTables) {
                $statements[] = 'DROP TABLE IF EXISTS ' . $this->quoteIdentifier($table) . ';';
            }

            $statements[] = $this->createTableSql($table, is_array($meta) ? $meta : []) . ';';
        }

        foreach ($data as $table => $rows) {
            if (!is_array($rows) || $rows === []) {
                continue;
            }

            foreach (array_chunk(array_values($rows), 100) as $chunk) {
                $statements[] = $this->insertSql((string) $table, $chunk) . ';';
            }
        }

        $statements[] = 'SET FOREIGN_KEY_CHECKS=1;';

        return implode(PHP_EOL . PHP_EOL, $statements) . PHP_EOL;
    }

    /**
     * @param array<string,mixed> $export
     */
    public function sync(PDO $pdo, array $export, bool $dropTables = true): void
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        foreach ($this->splitStatements($this->toSql($export, $dropTables)) as $statement) {
            $pdo->exec($statement);
        }
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function createTableSql(string $table, array $meta): string
    {
        $lines = [];
        $columns = is_array($meta['columns'] ?? null) ? $meta['columns'] : [];
        $indexes = is_array($meta['indexes'] ?? null) ? $meta['indexes'] : [];
        $primaryKey = (string) ($meta['primary_key'] ?? '');

        foreach ($columns as $name => $column) {
            $lines[] = '  ' . $this->quoteIdentifier((string) $name) . ' ' . $this->columnType(is_array($column) ? $column : []);
        }

        if ($primaryKey !== '') {
            $lines[] = '  PRIMARY KEY (' . $this->quoteIdentifier($primaryKey) . ')';
        }

        foreach ($indexes as $index) {
            if (!is_array($index)) {
                continue;
            }

            $type = (string) ($index['name'] ?? 'index');
            if ($type === 'primary') {
                continue;
            }

            $columns = array_values((array) ($index['columns'] ?? $index['column'] ?? []));
            if ($columns === []) {
                continue;
            }

            $name = (string) ($index['index'] ?? implode('_', $columns));
            $columnSql = implode(', ', array_map(fn (mixed $column): string => $this->quoteIdentifier((string) $column), $columns));

            if ($type === 'unique') {
                $lines[] = '  UNIQUE KEY ' . $this->quoteIdentifier($name) . ' (' . $columnSql . ')';
                continue;
            }

            if ($type === 'foreign' && isset($index['references_table'], $index['references_columns'])) {
                $referenceColumns = array_values((array) $index['references_columns']);
                $referenceSql = implode(', ', array_map(fn (mixed $column): string => $this->quoteIdentifier((string) $column), $referenceColumns));
                $line = '  CONSTRAINT ' . $this->quoteIdentifier($name) . ' FOREIGN KEY (' . $columnSql . ') REFERENCES ' . $this->quoteIdentifier((string) $index['references_table']) . ' (' . $referenceSql . ')';
                if (!empty($index['on_delete'])) {
                    $line .= ' ON DELETE ' . (string) $index['on_delete'];
                }
                if (!empty($index['on_update'])) {
                    $line .= ' ON UPDATE ' . (string) $index['on_update'];
                }
                $lines[] = $line;
                continue;
            }

            $lines[] = '  KEY ' . $this->quoteIdentifier($name) . ' (' . $columnSql . ')';
        }

        return 'CREATE TABLE ' . $this->quoteIdentifier($table) . ' (' . PHP_EOL
            . implode(',' . PHP_EOL, $lines) . PHP_EOL
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function insertSql(string $table, array $rows): string
    {
        $columns = array_keys((array) ($rows[0] ?? []));
        $columnSql = implode(', ', array_map(fn (string $column): string => $this->quoteIdentifier($column), $columns));
        $values = [];

        foreach ($rows as $row) {
            $values[] = '(' . implode(', ', array_map(fn (string $column): string => $this->quoteValue($row[$column] ?? null), $columns)) . ')';
        }

        return 'INSERT INTO ' . $this->quoteIdentifier($table) . ' (' . $columnSql . ') VALUES' . PHP_EOL
            . implode(',' . PHP_EOL, array_map(fn (string $value): string => '  ' . $value, $values));
    }

    /**
     * @param array<string,mixed> $column
     */
    private function columnType(array $column): string
    {
        $type = strtolower((string) ($column['type'] ?? 'string'));
        $nullable = !empty($column['nullable']) ? ' NULL' : ' NOT NULL';
        $default = array_key_exists('default', $column) && $column['default'] !== null ? ' DEFAULT ' . $this->quoteValue($column['default']) : '';
        $autoIncrement = !empty($column['auto_increment']) ? ' AUTO_INCREMENT' : '';
        $unsigned = !empty($column['unsigned']) ? ' UNSIGNED' : '';

        if ($type === 'enum' && isset($column['values']) && is_array($column['values'])) {
            return 'ENUM(' . implode(', ', array_map(fn (mixed $value): string => $this->quoteValue($value), $column['values'])) . ')' . $nullable . $default;
        }

        $sqlType = match ($type) {
            'integer', 'int' => 'INT' . $unsigned,
            'bigint' => 'BIGINT' . $unsigned,
            'boolean' => 'TINYINT(1)',
            'decimal' => 'DECIMAL(' . (int) ($column['precision'] ?? 10) . ',' . (int) ($column['scale'] ?? 0) . ')',
            'float' => 'DOUBLE',
            'text' => 'TEXT',
            'date' => 'DATE',
            'time' => 'TIME',
            'datetime', 'timestamp' => 'DATETIME',
            'json' => 'JSON',
            default => 'VARCHAR(' . (int) ($column['length'] ?? 255) . ')',
        };

        return $sqlType . $nullable . $default . $autoIncrement;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function quoteValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $value) . "'";
    }

    /**
     * @return list<string>
     */
    private function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $quote = null;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            if ($quote !== null) {
                $buffer .= $char;
                if ($char === $quote && ($i === 0 || $sql[$i - 1] !== '\\')) {
                    $quote = null;
                }
                continue;
            }
            if ($char === "'" || $char === '"') {
                $quote = $char;
                $buffer .= $char;
                continue;
            }
            if ($char === ';') {
                $statement = trim($buffer);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }

        $statement = trim($buffer);
        if ($statement !== '') {
            $statements[] = $statement;
        }

        return $statements;
    }
}
