<?php

namespace Pinoox\Component\Database\DevDB;

final class DevDbTableDescriptor
{
    public static function describe(DevDbRuntime $runtime, string $table, int $limit = 10, int $offset = 0): array
    {
        $inspect = $runtime->inspectTable($table, $limit, $offset);

        return self::enrich($inspect);
    }

    /**
     * @param array<string, mixed> $inspect
     * @return array<string, mixed>
     */
    public static function enrich(array $inspect): array
    {
        $foreignKeys = [];
        $uniqueIndexes = [];
        $indexes = [];
        $primary = null;

        foreach ($inspect['indexes'] ?? [] as $index) {
            if (!is_array($index)) {
                continue;
            }

            $kind = strtolower((string) ($index['name'] ?? ''));

            if ($kind === 'foreign') {
                $foreignKeys[] = self::normalizeForeignKey($index);
                continue;
            }

            if ($kind === 'unique') {
                $uniqueIndexes[] = self::normalizeIndex($index, 'unique');
                continue;
            }

            if ($kind === 'index') {
                $indexes[] = self::normalizeIndex($index, 'index');
                continue;
            }

            if ($kind === 'primary') {
                $primary = self::normalizeIndex($index, 'primary');
            }
        }

        $inspect['foreign_keys'] = $foreignKeys;
        $inspect['unique_indexes'] = $uniqueIndexes;
        $inspect['indexes_list'] = $indexes;
        $inspect['primary_index'] = $primary;

        return $inspect;
    }

    /**
     * @param array<string, mixed> $index
     * @return array<string, mixed>
     */
    private static function normalizeForeignKey(array $index): array
    {
        $referenced = $index['references'] ?? $index['foreignColumns'] ?? $index['foreign_columns'] ?? [];

        return [
            'name' => (string) ($index['index'] ?? $index['foreign'] ?? 'foreign'),
            'columns' => self::stringList($index['columns'] ?? []),
            'referenced_table' => (string) ($index['on'] ?? $index['table'] ?? $index['foreign_table'] ?? '-'),
            'referenced_columns' => self::stringList($referenced),
            'on_update' => (string) ($index['onUpdate'] ?? $index['on_update'] ?? '-'),
            'on_delete' => (string) ($index['onDelete'] ?? $index['on_delete'] ?? '-'),
        ];
    }

    /**
     * @param array<string, mixed> $index
     * @return array<string, mixed>
     */
    private static function normalizeIndex(array $index, string $type): array
    {
        return [
            'type' => $type,
            'name' => (string) ($index['index'] ?? $index['name'] ?? $type),
            'columns' => self::stringList($index['columns'] ?? []),
            'algorithm' => $index['algorithm'] ?? null,
            'unique' => $type === 'unique' || $type === 'primary',
        ];
    }

    /**
     * @param mixed $values
     * @return list<string>
     */
    private static function stringList(mixed $values): array
    {
        if (!is_array($values)) {
            return $values === null || $values === '' ? [] : [(string) $values];
        }

        return array_values(array_map(static fn ($value) => (string) $value, $values));
    }
}
