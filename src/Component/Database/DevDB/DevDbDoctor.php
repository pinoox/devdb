<?php

namespace Pinoox\Component\Database\DevDB;

final class DevDbDoctor
{
    public function __construct(private DevDbStore $store)
    {
    }

    public function inspect(): array
    {
        $schema = $this->store->schema();
        $sequences = $this->store->sequences();
        $issues = [];
        $tables = [];

        foreach (($schema['tables'] ?? []) as $table => $meta) {
            $table = (string) $table;
            $rows = $this->store->readTable($table);
            $primaryKey = (string) ($meta['primary_key'] ?? '');
            $maxId = $primaryKey !== '' ? $this->maxId($rows, $primaryKey) : 0;
            $sequence = (int) ($sequences[$table] ?? 0);
            $dataPath = $this->store->pathForTable($table);

            if (!is_file($dataPath)) {
                $issues[] = [
                    'type' => 'missing_data_file',
                    'table' => $table,
                    'message' => 'Data file is missing for table "' . $table . '".',
                ];
            }

            if ($primaryKey !== '' && $sequence < $maxId) {
                $issues[] = [
                    'type' => 'stale_sequence',
                    'table' => $table,
                    'message' => 'Sequence for table "' . $table . '" is lower than the current max primary key.',
                    'expected' => $maxId,
                    'actual' => $sequence,
                ];
            }

            foreach ($this->missingColumns($rows, array_keys((array) ($meta['columns'] ?? []))) as $column => $count) {
                $issues[] = [
                    'type' => 'missing_column_values',
                    'table' => $table,
                    'column' => $column,
                    'rows' => $count,
                    'message' => 'Column "' . $column . '" is missing from ' . $count . ' row(s) in table "' . $table . '".',
                ];
            }

            $tables[] = [
                'table' => $table,
                'rows' => count($rows),
                'primary_key' => $primaryKey ?: null,
                'sequence' => $sequence,
                'max_id' => $maxId,
            ];
        }

        return [
            'ok' => $issues === [],
            'path' => $this->store->root(),
            'schema_version' => $schema['version'] ?? DevDbStore::SCHEMA_VERSION,
            'issues' => $issues,
            'tables' => $tables,
        ];
    }

    public function repair(): array
    {
        $before = $this->inspect();
        $schema = $this->store->schema();
        $sequences = $this->store->sequences();
        $repairs = [];

        foreach (($schema['tables'] ?? []) as $table => $meta) {
            $table = (string) $table;
            $rows = $this->store->readTable($table);
            $columns = (array) ($meta['columns'] ?? []);
            $missing = $this->missingColumns($rows, array_keys($columns));
            if ($missing !== []) {
                foreach ($rows as &$row) {
                    foreach ($missing as $column => $_count) {
                        if (!array_key_exists($column, $row)) {
                            $row[$column] = $this->defaultValueForColumn((array) ($columns[$column] ?? []));
                        }
                    }
                }
                unset($row);
                foreach ($missing as $column => $count) {
                    $repairs[] = [
                        'type' => 'column_values_backfilled',
                        'table' => $table,
                        'column' => $column,
                        'rows' => $count,
                    ];
                }
            }
            $this->store->replaceTable($table, $rows);

            $primaryKey = (string) ($meta['primary_key'] ?? '');
            if ($primaryKey !== '') {
                $maxId = $this->maxId($rows, $primaryKey);
                if ((int) ($sequences[$table] ?? 0) < $maxId) {
                    $sequences[$table] = $maxId;
                    $repairs[] = [
                        'type' => 'sequence_updated',
                        'table' => $table,
                        'value' => $maxId,
                    ];
                }
            }
        }

        $this->store->saveSequences($sequences);
        $this->store->saveSchema($schema);
        $this->store->writeManifest();

        return [
            'before' => $before,
            'after' => $this->inspect(),
            'repairs' => $repairs,
        ];
    }

    private function maxId(array $rows, string $primaryKey): int
    {
        $max = 0;
        foreach ($rows as $row) {
            if (isset($row[$primaryKey]) && is_numeric($row[$primaryKey])) {
                $max = max($max, (int) $row[$primaryKey]);
            }
        }

        return $max;
    }

    private function missingColumns(array $rows, array $columns): array
    {
        $missing = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            foreach ($columns as $column) {
                $column = (string) $column;
                if (!array_key_exists($column, $row)) {
                    $missing[$column] = (int) ($missing[$column] ?? 0) + 1;
                }
            }
        }

        return $missing;
    }

    private function defaultValueForColumn(array $column): mixed
    {
        if (!array_key_exists('default', $column)) {
            return null;
        }

        $default = $column['default'];
        if (is_string($default)) {
            return match (strtolower($default)) {
                'current_timestamp' => date('Y-m-d H:i:s'),
                'current_date' => date('Y-m-d'),
                'current_time' => date('H:i:s'),
                default => $default,
            };
        }

        return $default;
    }
}
