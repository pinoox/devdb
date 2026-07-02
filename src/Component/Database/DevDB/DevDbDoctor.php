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
}
