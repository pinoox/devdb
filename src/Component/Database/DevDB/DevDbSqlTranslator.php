<?php

namespace Pinoox\Component\Database\DevDB;

final class DevDbSqlTranslator
{
    public function __construct(private DevDbStore $store)
    {
    }

    /**
     * @return list<object>
     */
    public function select(string $sql, array $bindings = []): array
    {
        $sql = $this->normalizeSql($sql);

        if (preg_match('/^show\s+databases$/i', $sql) === 1) {
            return [(object) ['Database' => 'devdb', 'database' => 'devdb']];
        }

        if (preg_match('/^show\s+tables(?:\s+like\s+(?<like>.+))?$/i', $sql, $match) === 1) {
            return $this->showTables(isset($match['like']) ? $this->parseLiteral($match['like']) : null);
        }

        if (preg_match('/^(?:describe|desc)\s+(?<table>[`"\[\]A-Za-z0-9_.-]+)$/i', $sql, $match) === 1) {
            return $this->describeTable($this->cleanIdentifier($match['table']));
        }

        if (preg_match('/^show\s+columns\s+from\s+(?<table>[`"\[\]A-Za-z0-9_.-]+)$/i', $sql, $match) === 1) {
            return $this->describeTable($this->cleanIdentifier($match['table']));
        }

        if (preg_match('/^show\s+(?:index|indexes|keys)\s+from\s+(?<table>[`"\[\]A-Za-z0-9_.-]+)$/i', $sql, $match) === 1) {
            return $this->showIndexes($this->cleanIdentifier($match['table']));
        }

        $query = $this->parseSelect($sql);
        $rows = $this->sourceRows($query['from'], $query['joins']);
        $rows = array_values(array_filter(
            $rows,
            fn (array $row): bool => $this->matchesWhere($row, $query['where'], $bindings),
        ));

        if ($query['group'] !== '') {
            $rows = $this->groupRows($rows, $query['group'], $query['columns']);
            $rows = array_values(array_filter(
                $rows,
                fn (array $row): bool => $this->matchesWhere($row, $query['having'], $bindings),
            ));
        } elseif ($this->containsAggregate($query['columns'])) {
            $rows = [$this->aggregateRow($rows, $query['columns'])];
        } else {
            $rows = $this->sortRows($rows, $query['order']);
            $rows = $this->sliceRows($rows, $query['limit'], $query['offset']);
            $rows = array_map(fn (array $row): array => $this->projectRow($row, $query['columns']), $rows);
            if ($query['distinct']) {
                $rows = $this->distinctRows($rows);
            }

            return array_map(fn (array $row): object => (object) $this->unqualifyProjectedRow($row), $rows);
        }

        if ($query['distinct']) {
            $rows = $this->distinctRows($rows);
        }
        $rows = $this->sortRows($rows, $query['order']);
        $rows = $this->sliceRows($rows, $query['limit'], $query['offset']);

        return array_map(fn (array $row): object => (object) $this->unqualifyProjectedRow($row), $rows);
    }

    public function execute(string $sql, array $bindings = []): int
    {
        $sql = $this->normalizeSql($sql);

        if (preg_match('/^insert\s+/i', $sql) === 1) {
            return $this->insert($sql, $bindings);
        }

        if (preg_match('/^update\s+/i', $sql) === 1) {
            return $this->update($sql, $bindings);
        }

        if (preg_match('/^delete\s+/i', $sql) === 1) {
            return $this->delete($sql, $bindings);
        }

        if (preg_match('/^set\s+/i', $sql) === 1) {
            return 0;
        }

        if (preg_match('/^(?:create|drop)\s+database\s+/i', $sql) === 1 || preg_match('/^use\s+/i', $sql) === 1) {
            return 0;
        }

        if (preg_match('/^create\s+table\s+/i', $sql) === 1) {
            $this->createTable($sql);

            return 0;
        }

        if (preg_match('/^drop\s+table\s+/i', $sql) === 1) {
            return $this->dropTable($sql);
        }

        if (preg_match('/^alter\s+table\s+/i', $sql) === 1) {
            $this->alterTable($sql);

            return 0;
        }

        if (preg_match('/^create\s+(?:unique\s+)?index\s+/i', $sql) === 1) {
            $this->createIndex($sql);

            return 0;
        }

        if (preg_match('/^drop\s+index\s+/i', $sql) === 1) {
            $this->dropIndex($sql);

            return 0;
        }

        if (preg_match('/^truncate\s+(?:table\s+)?(?<table>[`"\[\]A-Za-z0-9_.-]+)/i', $sql, $match) === 1) {
            $table = $this->cleanIdentifier($match['table']);
            $this->assertTable($table);
            $this->store->replaceTable($table, []);

            return 0;
        }

        throw DevDbException::unsupported('raw SQL statement "' . $this->statementName($sql) . '"');
    }

    private function insert(string $sql, array $bindings): int
    {
        if (preg_match('/^insert\s+into\s+(?<table>[`"\[\]A-Za-z0-9_.-]+)(?:\s*\((?<columns>[^)]+)\))?\s+(?<select>select\s+.+)$/is', $sql, $insertSelectMatch) === 1) {
            return $this->insertFromSelect($insertSelectMatch, $bindings);
        }

        if (preg_match('/^insert\s+into\s+(?<table>[`"\[\]A-Za-z0-9_.-]+)(?:\s*\((?<columns>[^)]+)\))?\s*values\s*(?<values>.+)$/is', $sql, $match) !== 1) {
            throw DevDbException::unsupported('raw INSERT syntax');
        }

        $table = $this->cleanIdentifier($match['table']);
        $this->assertTable($table);
        $columns = isset($match['columns']) && trim((string) $match['columns']) !== ''
            ? array_map(fn (string $column): string => $this->cleanIdentifier($column), $this->splitCsv($match['columns']))
            : array_keys($this->store->schema()['tables'][$table]['columns'] ?? []);
        $groups = $this->parseValueGroups($match['values']);
        $rows = $this->store->readTable($table);
        $primaryKey = $this->primaryKey($table);
        $bindingOffset = 0;
        $affected = 0;

        foreach ($groups as $group) {
            $values = $this->splitCsv($group);
            if (count($values) !== count($columns)) {
                throw new DevDbException('DevDB raw SQL INSERT column count does not match value count.');
            }

            $row = [];
            foreach ($columns as $index => $column) {
                $row[$column] = $this->parseValue($values[$index], $bindings, $bindingOffset);
            }

            if ($primaryKey !== null && (!array_key_exists($primaryKey, $row) || $row[$primaryKey] === null)) {
                $row[$primaryKey] = $this->store->nextId($table);
            } elseif ($primaryKey !== null && is_numeric($row[$primaryKey] ?? null)) {
                $this->bumpSequence($table, (int) $row[$primaryKey]);
            }

            $row = $this->applyColumnDefaults($table, $row);
            $this->guardUniqueConstraints($table, $row, $rows);
            $rows[] = $row;
            $affected++;
        }

        $this->store->replaceTable($table, $rows);

        return $affected;
    }

    private function insertFromSelect(array $match, array $bindings): int
    {
        $table = $this->cleanIdentifier($match['table']);
        $this->assertTable($table);
        $columns = isset($match['columns']) && trim((string) $match['columns']) !== ''
            ? array_map(fn (string $column): string => $this->cleanIdentifier($column), $this->splitCsv($match['columns']))
            : array_keys($this->store->schema()['tables'][$table]['columns'] ?? []);
        $rows = $this->store->readTable($table);
        $primaryKey = $this->primaryKey($table);
        $affected = 0;

        foreach ($this->select($match['select'], $bindings) as $selected) {
            $values = array_values((array) $selected);
            $row = [];
            foreach ($columns as $index => $column) {
                $row[$column] = $values[$index] ?? null;
            }

            if ($primaryKey !== null && (!array_key_exists($primaryKey, $row) || $row[$primaryKey] === null)) {
                $row[$primaryKey] = $this->store->nextId($table);
            } elseif ($primaryKey !== null && is_numeric($row[$primaryKey] ?? null)) {
                $this->bumpSequence($table, (int) $row[$primaryKey]);
            }

            $row = $this->applyColumnDefaults($table, $row);
            $this->guardUniqueConstraints($table, $row, $rows);
            $rows[] = $row;
            $affected++;
        }

        $this->store->replaceTable($table, $rows);

        return $affected;
    }

    private function update(string $sql, array $bindings): int
    {
        if (preg_match('/^update\s+(?<table>[`"\[\]A-Za-z0-9_.-]+)\s+set\s+(?<set>.+?)(?:\s+where\s+(?<where>.+))?$/is', $sql, $match) !== 1) {
            throw DevDbException::unsupported('raw UPDATE syntax');
        }

        $table = $this->cleanIdentifier($match['table']);
        $this->assertTable($table);
        $bindingOffset = 0;
        $assignments = $this->parseAssignments($match['set'], $bindings, $bindingOffset);
        $where = trim((string) ($match['where'] ?? ''));
        $rows = $this->store->readTable($table);
        $affected = 0;

        foreach ($rows as $index => $row) {
            $qualified = $this->qualifyRow((array) $row, $table, $table);
            if (!$this->matchesWhere($qualified, $where, $bindings, $bindingOffset)) {
                continue;
            }

            $updatedRow = array_replace((array) $row, $assignments);
            $otherRows = $rows;
            unset($otherRows[$index]);
            $this->guardUniqueConstraints($table, $updatedRow, $otherRows);
            $rows[$index] = $updatedRow;
            $affected++;
        }

        $this->store->replaceTable($table, $rows);

        return $affected;
    }

    private function delete(string $sql, array $bindings): int
    {
        if (preg_match('/^delete\s+from\s+(?<table>[`"\[\]A-Za-z0-9_.-]+)(?:\s+where\s+(?<where>.+))?$/is', $sql, $match) !== 1) {
            throw DevDbException::unsupported('raw DELETE syntax');
        }

        $table = $this->cleanIdentifier($match['table']);
        $this->assertTable($table);
        $where = trim((string) ($match['where'] ?? ''));
        $kept = [];
        $affected = 0;

        foreach ($this->store->readTable($table) as $row) {
            $qualified = $this->qualifyRow((array) $row, $table, $table);
            if ($this->matchesWhere($qualified, $where, $bindings)) {
                $affected++;
                continue;
            }

            $kept[] = (array) $row;
        }

        $this->store->replaceTable($table, $kept);

        return $affected;
    }

    private function createTable(string $sql): void
    {
        $parsed = $this->parseCreateTableStatement($sql);
        if ($parsed === null) {
            throw DevDbException::unsupported('raw CREATE TABLE syntax');
        }

        $table = $parsed['table'];
        if ($this->store->hasTable($table)) {
            if ($parsed['if_not_exists']) {
                return;
            }

            throw new DevDbException('DevDB table "' . $table . '" already exists.');
        }

        [$columns, $indexes] = $this->parseCreateTableBody($parsed['body']);
        $this->store->createTable($table, $columns, $indexes);

        if ($parsed['auto_increment'] !== null) {
            $sequences = $this->store->sequences();
            $sequences[$table] = max(0, $parsed['auto_increment'] - 1);
            $this->store->saveSequences($sequences);
        }
    }

    private function dropTable(string $sql): int
    {
        if (preg_match('/^drop\s+table\s+(?<if>if\s+exists\s+)?(?<tables>.+)$/is', $sql, $match) !== 1) {
            throw DevDbException::unsupported('raw DROP TABLE syntax');
        }

        $affected = 0;
        foreach ($this->splitCsv($match['tables']) as $tableName) {
            $table = $this->cleanIdentifier($tableName);
            if (!$this->store->hasTable($table)) {
                if (trim((string) ($match['if'] ?? '')) !== '') {
                    continue;
                }

                throw new DevDbException('DevDB table "' . $table . '" does not exist for DROP TABLE.');
            }

            $this->store->dropTable($table);
            $affected++;
        }

        return $affected;
    }

    private function alterTable(string $sql): void
    {
        if (preg_match('/^alter\s+table\s+(?<table>[`"\[\]A-Za-z0-9_.-]+)\s+(?<action>.+)$/is', $sql, $match) !== 1) {
            throw DevDbException::unsupported('raw ALTER TABLE syntax');
        }

        $table = $this->cleanIdentifier($match['table']);
        $this->assertTable($table);
        $action = trim($match['action']);
        $schema = $this->store->schema();
        $current = $schema['tables'][$table] ?? ['columns' => [], 'indexes' => []];
        $columns = $current['columns'] ?? [];
        $indexes = $current['indexes'] ?? [];

        if (preg_match('/^(?:add\s+)?column\s+(?<definition>.+)$/is', $action, $columnMatch) === 1
            || preg_match('/^add\s+(?<definition>(?!primary|unique|index|key|constraint).+)$/is', $action, $columnMatch) === 1) {
            [$name, $definition] = $this->parseColumnDefinition($columnMatch['definition']);
            $columns[$name] = $definition;
            $this->saveTableMetadata($table, $columns, $indexes);

            return;
        }

        if (preg_match('/^drop\s+column\s+(?<column>[`"\[\]A-Za-z0-9_.-]+)$/i', $action, $dropMatch) === 1) {
            unset($columns[$this->cleanIdentifier($dropMatch['column'])]);
            $this->saveTableMetadata($table, $columns, $indexes);

            return;
        }

        if (preg_match('/^rename\s+column\s+(?<from>[`"\[\]A-Za-z0-9_.-]+)\s+to\s+(?<to>[`"\[\]A-Za-z0-9_.-]+)$/i', $action, $renameMatch) === 1) {
            $from = $this->cleanIdentifier($renameMatch['from']);
            $to = $this->cleanIdentifier($renameMatch['to']);
            if (isset($columns[$from])) {
                $columns[$to] = $columns[$from];
                unset($columns[$from]);
            }
            $this->saveTableMetadata($table, $columns, $indexes);

            return;
        }

        if (preg_match('/^rename\s+to\s+(?<to>[`"\[\]A-Za-z0-9_.-]+)$/i', $action, $renameTableMatch) === 1) {
            $to = $this->cleanIdentifier($renameTableMatch['to']);
            if ($this->store->hasTable($to)) {
                throw new DevDbException('DevDB table "' . $to . '" already exists.');
            }
            $rows = $this->store->readTable($table);
            $this->store->createTable($to, $columns, $indexes);
            $this->store->replaceTable($to, $rows);
            $this->store->dropTable($table);

            return;
        }

        if (preg_match('/^add\s+(?<constraint>primary\s+key|unique(?:\s+key|\s+index)?|index|key)\s*(?<name>[`"\[\]A-Za-z0-9_.-]+)?\s*\((?<columns>[^)]+)\)$/i', $action, $indexMatch) === 1) {
            $indexes[] = $this->indexDefinition($indexMatch['constraint'], $indexMatch['columns'], $indexMatch['name'] ?? null);
            $this->saveTableMetadata($table, $columns, $indexes);

            return;
        }

        throw DevDbException::unsupported('raw ALTER TABLE action "' . $action . '"');
    }

    private function createIndex(string $sql): void
    {
        if (preg_match('/^create\s+(?<unique>unique\s+)?index\s+(?<name>[`"\[\]A-Za-z0-9_.-]+)\s+on\s+(?<table>[`"\[\]A-Za-z0-9_.-]+)\s*\((?<columns>[^)]+)\)$/i', $sql, $match) !== 1) {
            throw DevDbException::unsupported('raw CREATE INDEX syntax');
        }

        $table = $this->cleanIdentifier($match['table']);
        $this->assertTable($table);
        $schema = $this->store->schema();
        $current = $schema['tables'][$table] ?? ['columns' => [], 'indexes' => []];
        $indexes = $current['indexes'] ?? [];
        $indexes[] = [
            'name' => trim((string) ($match['unique'] ?? '')) !== '' ? 'unique' : 'index',
            'index' => $this->cleanIdentifier($match['name']),
            'columns' => array_map(fn (string $column): string => $this->cleanIdentifier($column), $this->splitCsv($match['columns'])),
        ];

        $this->saveTableMetadata($table, $current['columns'] ?? [], $indexes);
    }

    private function dropIndex(string $sql): void
    {
        if (preg_match('/^drop\s+index\s+(?<name>[`"\[\]A-Za-z0-9_.-]+)(?:\s+on\s+(?<table>[`"\[\]A-Za-z0-9_.-]+))?$/i', $sql, $match) !== 1) {
            throw DevDbException::unsupported('raw DROP INDEX syntax');
        }

        $name = $this->cleanIdentifier($match['name']);
        $schema = $this->store->schema();
        $tables = isset($match['table']) && $match['table'] !== ''
            ? [$this->cleanIdentifier($match['table'])]
            : array_keys($schema['tables'] ?? []);

        foreach ($tables as $table) {
            $current = $schema['tables'][$table] ?? null;
            if (!is_array($current)) {
                continue;
            }

            $indexes = array_values(array_filter($current['indexes'] ?? [], function (array $index) use ($name): bool {
                return (string) ($index['index'] ?? $index['name'] ?? '') !== $name;
            }));
            $this->saveTableMetadata((string) $table, $current['columns'] ?? [], $indexes);
        }
    }

    /**
     * @return array{columns:string,distinct:bool,from:array{name:string,alias:string},joins:list<array{type:string,table:string,alias:string,first:string,second:string}>,where:string,group:string,having:string,order:string,limit:?int,offset:int}
     */
    private function parseSelect(string $sql): array
    {
        $sql = $this->normalizeSql($sql);

        if (preg_match('/\s+union\s+/i', $sql) === 1) {
            throw DevDbException::unsupported('raw SELECT unions');
        }

        if (preg_match('/^select\s+(?<distinct>distinct\s+)?(?<columns>.+?)\s+from\s+(?<tail>.+)$/is', $sql, $match) !== 1) {
            throw DevDbException::unsupported('raw SELECT syntax');
        }

        $tail = trim($match['tail']);
        $source = $this->extractLeadingSource($tail);
        $clauses = substr($tail, strlen($source));
        $where = $this->extractClause($clauses, 'where', ['group by', 'having', 'order by', 'limit', 'offset']);
        $group = $this->extractClause($clauses, 'group by', ['having', 'order by', 'limit', 'offset']);
        $having = $this->extractClause($clauses, 'having', ['order by', 'limit', 'offset']);
        $order = $this->extractClause($clauses, 'order by', ['limit', 'offset']);
        $limit = $this->extractClause($clauses, 'limit', ['offset']);
        $offset = $this->extractClause($clauses, 'offset', []);
        [$from, $joins] = $this->parseSource($source);

        return [
            'columns' => trim($match['columns']),
            'distinct' => trim((string) ($match['distinct'] ?? '')) !== '',
            'from' => $from,
            'joins' => $joins,
            'where' => $where,
            'group' => $group,
            'having' => $having,
            'order' => $order,
            'limit' => $limit !== '' ? max(0, (int) $limit) : null,
            'offset' => $offset !== '' ? max(0, (int) $offset) : 0,
        ];
    }

    private function extractLeadingSource(string $tail): string
    {
        $positions = [];
        foreach ([' where ', ' group by ', ' having ', ' order by ', ' limit ', ' offset '] as $needle) {
            $position = stripos(' ' . $tail . ' ', $needle);
            if ($position !== false) {
                $positions[] = max(0, $position - 1);
            }
        }

        return trim($positions === [] ? $tail : substr($tail, 0, min($positions)));
    }

    /**
     * @return array{0:array{name:string,alias:string},1:list<array{type:string,table:string,alias:string,first:string,second:string}>}
     */
    private function parseSource(string $source): array
    {
        $source = trim($source);
        $joinPattern = '/\s+(left\s+join|inner\s+join|join)\s+/i';
        $parts = preg_split($joinPattern, $source, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts) || $parts === []) {
            throw DevDbException::unsupported('raw SELECT source syntax');
        }

        $from = $this->parseTableAlias((string) array_shift($parts));
        $this->assertTable($from['name']);
        $joins = [];

        while ($parts !== []) {
            $type = strtolower(trim((string) array_shift($parts)));
            $body = trim((string) array_shift($parts));
            if (preg_match('/^(?<table>.+?)\s+on\s+(?<first>[`"\[\]A-Za-z0-9_.-]+)\s*=\s*(?<second>[`"\[\]A-Za-z0-9_.-]+)$/is', $body, $match) !== 1) {
                throw DevDbException::unsupported('raw JOIN syntax');
            }

            $table = $this->parseTableAlias($match['table']);
            $this->assertTable($table['name']);
            $joins[] = [
                'type' => str_starts_with($type, 'left') ? 'left' : 'inner',
                'table' => $table['name'],
                'alias' => $table['alias'],
                'first' => $this->cleanIdentifier($match['first'], false),
                'second' => $this->cleanIdentifier($match['second'], false),
            ];
        }

        return [$from, $joins];
    }

    /**
     * @return array{name:string,alias:string}
     */
    private function parseTableAlias(string $value): array
    {
        $value = trim($value);
        if (preg_match('/^(?<table>[`"\[\]A-Za-z0-9_.-]+)(?:\s+as\s+|\s+)?(?<alias>[`"\[\]A-Za-z0-9_.-]+)?$/i', $value, $match) !== 1) {
            throw DevDbException::unsupported('raw table alias syntax "' . $value . '"');
        }

        $table = $this->cleanIdentifier($match['table']);
        $alias = isset($match['alias']) && $match['alias'] !== '' ? $this->cleanIdentifier($match['alias']) : $table;

        return ['name' => $table, 'alias' => $alias];
    }

    /**
     * @param array{name:string,alias:string} $from
     * @param list<array{type:string,table:string,alias:string,first:string,second:string}> $joins
     */
    private function sourceRows(array $from, array $joins): array
    {
        $rows = array_map(
            fn (array $row): array => $this->qualifyRow($row, $from['name'], $from['alias']),
            $this->store->readTable($from['name']),
        );

        foreach ($joins as $join) {
            $joinRows = array_map(
                fn (array $row): array => $this->qualifyRow($row, $join['table'], $join['alias']),
                $this->store->readTable($join['table']),
            );
            $nullJoinRow = $this->nullRow($join['table'], $join['alias']);
            $combinedRows = [];

            foreach ($rows as $row) {
                $matched = false;
                foreach ($joinRows as $joinRow) {
                    $combined = array_replace($row, $joinRow);
            if ($this->expressionValue($combined, $join['first']) == $this->expressionValue($combined, $join['second'])) {
                        $combinedRows[] = $combined;
                        $matched = true;
                    }
                }

                if (!$matched && $join['type'] === 'left') {
                    $combinedRows[] = array_replace($row, $nullJoinRow);
                }
            }

            $rows = $combinedRows;
        }

        return $rows;
    }

    private function qualifyRow(array $row, string $table, string $alias): array
    {
        $qualified = $row;
        foreach ($row as $column => $value) {
            $qualified[$table . '.' . $column] = $value;
            $qualified[$alias . '.' . $column] = $value;
        }

        return $qualified;
    }

    private function nullRow(string $table, string $alias): array
    {
        $columns = array_keys($this->store->schema()['tables'][$table]['columns'] ?? []);
        $row = [];

        foreach ($columns as $column) {
            $row[$table . '.' . $column] = null;
            $row[$alias . '.' . $column] = null;
        }

        return $row;
    }

    private function matchesWhere(array $row, string $where, array $bindings, int $bindingOffset = 0): bool
    {
        $where = $this->trimOuterParentheses(trim($where));
        if ($where === '') {
            return true;
        }

        $bindingsUsed = $bindingOffset;
        $orGroups = $this->splitBoolean($where, 'or');
        $matchedAnyGroup = false;

        foreach ($orGroups as $group) {
            $matchedGroup = true;
            foreach ($this->splitBoolean($group, 'and') as $condition) {
                if (!$this->matchesCondition($row, $condition, $bindings, $bindingsUsed)) {
                    $matchedGroup = false;
                }
            }

            if ($matchedGroup) {
                $matchedAnyGroup = true;
            }
        }

        return $matchedAnyGroup;
    }

    private function matchesCondition(array $row, string $condition, array $bindings, int &$bindingsUsed): bool
    {
        $condition = $this->trimOuterParentheses(trim($condition));

        if (preg_match('/^not\s+(?<condition>.+)$/is', $condition, $notMatch) === 1) {
            return !$this->matchesCondition($row, $notMatch['condition'], $bindings, $bindingsUsed);
        }

        if (preg_match('/^(?<not>not\s+)?exists\s*\((?<select>select\s+.+)\)$/is', $condition, $existsMatch) === 1) {
            $exists = $this->select($existsMatch['select'], $bindings) !== [];

            return trim((string) ($existsMatch['not'] ?? '')) !== '' ? !$exists : $exists;
        }

        if (count($this->splitBoolean($condition, 'or')) > 1 || count($this->splitBoolean($condition, 'and')) > 1) {
            $result = $this->matchesWhere($row, $condition, $bindings, $bindingsUsed);
            $bindingsUsed += $this->countPlaceholders($condition);

            return $result;
        }

        if (preg_match('/^(?<expression>.+?)\s+is\s+(?<not>not\s+)?null$/i', $condition, $match) === 1) {
            $value = $this->expressionValue($row, $match['expression'], $bindings, $bindingsUsed);
            $isNull = $value === null;

            return trim((string) ($match['not'] ?? '')) !== '' ? !$isNull : $isNull;
        }

        if (preg_match('/^(?<expression>.+?)\s+not\s+in\s*\((?<values>.+)\)$/is', $condition, $match) === 1) {
            return !$this->matchesIn($row, $match['expression'], $match['values'], $bindings, $bindingsUsed);
        }

        if (preg_match('/^(?<expression>.+?)\s+in\s*\((?<values>.+)\)$/is', $condition, $match) === 1) {
            return $this->matchesIn($row, $match['expression'], $match['values'], $bindings, $bindingsUsed);
        }

        if (preg_match('/^(?<expression>.+?)\s+(?<not>not\s+)?between\s+(?<left>.+)\s+and\s+(?<right>.+)$/is', $condition, $match) === 1) {
            $actual = $this->expressionValue($row, $match['expression'], $bindings, $bindingsUsed);
            $left = $this->parseValue($match['left'], $bindings, $bindingsUsed);
            $right = $this->parseValue($match['right'], $bindings, $bindingsUsed);
            $matched = $actual >= $left && $actual <= $right;

            return trim((string) ($match['not'] ?? '')) !== '' ? !$matched : $matched;
        }

        if (preg_match('/^(?<expression>.+?)\s*(?<operator>=|==|!=|<>|>=|<=|>|<|like|not\s+like)\s*(?<value>.+)$/is', $condition, $match) === 1) {
            $actual = $this->expressionValue($row, $match['expression'], $bindings, $bindingsUsed);
            $expected = $this->parseValue($match['value'], $bindings, $bindingsUsed);

            return $this->compare($actual, strtolower($match['operator']), $expected);
        }

        throw DevDbException::unsupported('raw SQL WHERE condition "' . $condition . '"');
    }

    private function matchesIn(array $row, string $expression, string $valuesSql, array $bindings, int &$bindingsUsed): bool
    {
        $actual = $this->expressionValue($row, $expression, $bindings, $bindingsUsed);
        $values = [];
        foreach ($this->splitCsv($valuesSql) as $value) {
            $values[] = $this->parseValue($value, $bindings, $bindingsUsed);
        }

        return in_array($actual, $values, false);
    }

    private function compare(mixed $actual, string $operator, mixed $expected): bool
    {
        return match ($operator) {
            '=', '==' => $actual == $expected,
            '!=', '<>' => $actual != $expected,
            '>' => $actual > $expected,
            '>=' => $actual >= $expected,
            '<' => $actual < $expected,
            '<=' => $actual <= $expected,
            'like' => $this->matchesLike((string) $actual, (string) $expected),
            'not like' => !$this->matchesLike((string) $actual, (string) $expected),
            default => throw DevDbException::unsupported('raw SQL operator "' . $operator . '"'),
        };
    }

    private function groupRows(array $rows, string $group, string $columns): array
    {
        $groupColumns = array_map(fn (string $column): string => $this->cleanIdentifier($column, false), $this->splitCsv($group));
        $groups = [];

        foreach ($rows as $row) {
            $key = json_encode(array_map(fn (string $column): mixed => $this->expressionValue($row, $column), $groupColumns));
            $groups[$key] ??= [];
            $groups[$key][] = $row;
        }

        return array_values(array_map(fn (array $groupRows): array => $this->aggregateRow($groupRows, $columns), $groups));
    }

    private function aggregateRow(array $rows, string $columns): array
    {
        $result = [];
        $first = $rows[0] ?? [];

        foreach ($this->splitCsv($columns) as $column) {
            $column = trim($column);
            if (preg_match('/^(?<fn>count|sum|avg|min|max)\s*\((?<column>.+)\)(?:\s+as\s+(?<alias>[`"\[\]A-Za-z0-9_.-]+))?$/i', $column, $match) === 1) {
                $function = strtolower($match['fn']);
                $name = trim($match['column']) === '*' ? '*' : trim($match['column']);
                $alias = isset($match['alias']) && $match['alias'] !== '' ? $this->cleanIdentifier($match['alias']) : 'aggregate';
                $values = $name === '*' ? $rows : array_map(fn (array $row): mixed => $this->expressionValue($row, $name), $rows);
                $result[$alias] = match ($function) {
                    'count' => count(array_filter($values, fn (mixed $value): bool => $name === '*' || $value !== null)),
                    'sum' => array_sum(array_map('floatval', array_filter($values, 'is_numeric'))),
                    'avg' => $this->average($values),
                    'min' => empty($values) ? null : min($values),
                    'max' => empty($values) ? null : max($values),
                };
                continue;
            }

            [$name, $alias] = $this->columnAndAlias($column);
            $result[$alias] = $this->expressionValue($first, $name);
        }

        return $result;
    }

    private function projectRow(array $row, string $columns): array
    {
        if (trim($columns) === '*') {
            return $row;
        }

        $projected = [];
        foreach ($this->splitCsv($columns) as $column) {
            [$name, $alias] = $this->columnAndAlias($column);
            $projected[$alias] = $this->expressionValue($row, $name);
        }

        return $projected;
    }

    private function distinctRows(array $rows): array
    {
        $seen = [];
        $distinct = [];

        foreach ($rows as $row) {
            $key = json_encode($row);
            if (!is_string($key) || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $distinct[] = $row;
        }

        return array_values($distinct);
    }

    private function sortRows(array $rows, string $order): array
    {
        if ($order === '') {
            return array_values($rows);
        }

        $orders = array_map(function (string $part): array {
            $pieces = preg_split('/\s+/', trim($part));

            return [
                'column' => $this->cleanIdentifier((string) ($pieces[0] ?? ''), false),
                'direction' => strtolower((string) ($pieces[1] ?? 'asc')) === 'desc' ? 'desc' : 'asc',
            ];
        }, $this->splitCsv($order));

        usort($rows, function (array $left, array $right) use ($orders): int {
            foreach ($orders as $order) {
                $result = $this->expressionValue($left, $order['column']) <=> $this->expressionValue($right, $order['column']);
                if ($result !== 0) {
                    return $order['direction'] === 'desc' ? -$result : $result;
                }
            }

            return 0;
        });

        return array_values($rows);
    }

    private function sliceRows(array $rows, ?int $limit, int $offset): array
    {
        return $limit === null ? array_slice($rows, $offset) : array_slice($rows, $offset, $limit);
    }

    private function parseAssignments(string $set, array $bindings, int &$bindingOffset): array
    {
        $assignments = [];

        foreach ($this->splitCsv($set) as $assignment) {
            if (preg_match('/^(?<column>[`"\[\]A-Za-z0-9_.-]+)\s*=\s*(?<value>.+)$/is', trim($assignment), $match) !== 1) {
                throw DevDbException::unsupported('raw UPDATE assignment "' . $assignment . '"');
            }

            $assignments[$this->cleanIdentifier($match['column'])] = $this->parseValue($match['value'], $bindings, $bindingOffset);
        }

        return $assignments;
    }

    private function parseValue(string $value, array $bindings, int &$bindingOffset): mixed
    {
        $value = trim($value);

        if ($value === '?') {
            if (!array_key_exists($bindingOffset, $bindings)) {
                throw new DevDbException('DevDB raw SQL binding is missing for placeholder #' . ($bindingOffset + 1) . '.');
            }

            return $bindings[$bindingOffset++];
        }

        if ((str_starts_with($value, "'") && str_ends_with($value, "'"))
            || (str_starts_with($value, '"') && str_ends_with($value, '"'))) {
            return stripcslashes(substr($value, 1, -1));
        }

        return match (strtolower($value)) {
            'null' => null,
            'true' => true,
            'false' => false,
            default => is_numeric($value) ? ($value + 0) : $this->cleanIdentifier($value, false),
        };
    }

    /**
     * @return array{table:string,body:string,options:string,if_not_exists:bool,auto_increment:?int}|null
     */
    private function parseCreateTableStatement(string $sql): ?array
    {
        if (preg_match('/^create\s+table\s+(?<if>if\s+not\s+exists\s+)?(?<table>[`"\[\]A-Za-z0-9_.-]+)\s*/i', $sql, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $open = strpos($sql, '(', $match[0][1] + strlen($match[0][0]));
        if ($open === false) {
            return null;
        }

        $close = $this->findMatchingParenthesis($sql, $open);
        if ($close === null) {
            return null;
        }

        $options = trim(substr($sql, $close + 1));
        $autoIncrement = null;
        if (preg_match('/\bauto_increment\s*=\s*(?<value>\d+)/i', $options, $optionMatch) === 1) {
            $autoIncrement = (int) $optionMatch['value'];
        }

        return [
            'table' => $this->cleanIdentifier($match['table'][0]),
            'body' => substr($sql, $open + 1, $close - $open - 1),
            'options' => $options,
            'if_not_exists' => trim((string) ($match['if'][0] ?? '')) !== '',
            'auto_increment' => $autoIncrement,
        ];
    }

    private function findMatchingParenthesis(string $value, int $open): ?int
    {
        $depth = 0;
        $quote = null;
        $length = strlen($value);

        for ($i = $open; $i < $length; $i++) {
            $char = $value[$i];

            if ($quote !== null) {
                if ($char === $quote && ($i === 0 || $value[$i - 1] !== '\\')) {
                    $quote = null;
                }
                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                continue;
            }

            if ($char === '(') {
                $depth++;
                continue;
            }

            if ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * @return array{0:array<string, array<string, mixed>>,1:list<array<string, mixed>>}
     */
    private function parseCreateTableBody(string $body): array
    {
        $columns = [];
        $indexes = [];

        foreach ($this->splitCsv($body) as $definition) {
            $definition = trim($definition);
            if (preg_match('/^primary\s+key\s*\((?<columns>[^)]+)\)(?:\s+using\s+\w+)?$/i', $definition, $match) === 1) {
                $indexes[] = $this->indexDefinition('primary key', $match['columns']);
                foreach ($this->splitCsv($match['columns']) as $column) {
                    $name = $this->cleanIdentifier($column);
                    if (isset($columns[$name])) {
                        $columns[$name]['primary'] = true;
                    }
                }
                continue;
            }

            if (preg_match('/^(?:unique\s+(?:key|index)?|index|key)\s*(?<name>[`"\[\]A-Za-z0-9_.-]+)?\s*\((?<columns>[^)]+)\)(?:\s+using\s+\w+)?$/i', $definition, $match) === 1) {
                $indexes[] = $this->indexDefinition($definition, $match['columns'], $match['name'] ?? null);
                continue;
            }

            if (preg_match('/^(?:constraint\s+[`"\[\]A-Za-z0-9_.-]+\s+)?foreign\s+key\s*\((?<columns>[^)]+)\)/i', $definition, $match) === 1) {
                $indexes[] = [
                    'name' => 'foreign',
                    'columns' => array_map(fn (string $column): string => $this->cleanIdentifier($column), $this->splitCsv($match['columns'])),
                ];
                continue;
            }

            [$name, $column] = $this->parseColumnDefinition($definition);
            $columns[$name] = $column;
        }

        return [$columns, $indexes];
    }

    /**
     * @return array{0:string,1:array<string,mixed>}
     */
    private function parseColumnDefinition(string $definition): array
    {
        if (preg_match('/^(?<name>[`"\[\]A-Za-z0-9_.-]+)\s+(?<type>[A-Za-z]+)(?:\s*\((?<size>[^)]+)\))?(?<modifiers>.*)$/is', trim($definition), $match) !== 1) {
            throw DevDbException::unsupported('raw column definition "' . $definition . '"');
        }

        $name = $this->cleanIdentifier($match['name']);
        $type = strtolower($match['type']);
        $size = trim((string) ($match['size'] ?? ''));
        $modifiers = strtolower((string) ($match['modifiers'] ?? ''));
        $default = null;

        if (preg_match('/\bdefault\s+((?:\'[^\']*\'|"[^"]*"|[^\s,]+))/i', (string) ($match['modifiers'] ?? ''), $defaultMatch) === 1) {
            $defaultOffset = 0;
            $default = $this->parseValue($defaultMatch[1], [], $defaultOffset);
        }

        $mapped = $this->mapSqlType($type);
        $column = [
            'type' => $mapped,
            'length' => null,
            'nullable' => !str_contains($modifiers, 'not null'),
            'default' => $default,
            'auto_increment' => str_contains($modifiers, 'auto_increment') || str_contains($modifiers, 'autoincrement'),
            'primary' => str_contains($modifiers, 'primary key'),
            'unsigned' => str_contains($modifiers, 'unsigned'),
            'precision' => null,
            'scale' => null,
            'comment' => null,
        ];

        if ($size !== '') {
            $parts = $this->splitCsv($size);
            if (in_array($mapped, ['enum', 'set'], true)) {
                $column['values'] = array_map(fn (string $item): mixed => $this->parseLiteral($item), $parts);
            } elseif (isset($parts[1])) {
                $column['precision'] = (int) $parts[0];
                $column['scale'] = (int) $parts[1];
            } else {
                $column['length'] = (int) $parts[0];
            }
        }

        if ($column['auto_increment']) {
            $column['primary'] = true;
            $column['nullable'] = false;
        }

        return [$name, $column];
    }

    private function mapSqlType(string $type): string
    {
        return match (strtolower($type)) {
            'int', 'integer', 'tinyint', 'smallint', 'mediumint', 'bigint' => 'integer',
            'varchar', 'char', 'nvarchar', 'nchar', 'string' => 'string',
            'text', 'mediumtext', 'longtext' => 'text',
            'bool', 'boolean' => 'boolean',
            'decimal', 'numeric' => 'decimal',
            'float', 'double', 'real' => 'float',
            'datetime', 'timestamp' => 'datetime',
            'date' => 'date',
            'time' => 'time',
            'json' => 'json',
            'enum' => 'enum',
            'set' => 'set',
            default => strtolower($type),
        };
    }

    private function indexDefinition(string $type, string $columns, ?string $name = null): array
    {
        $columnNames = array_map(fn (string $column): string => $this->cleanIdentifier($column), $this->splitCsv($columns));
        $normalized = strtolower($type);

        return [
            'name' => str_contains($normalized, 'primary') ? 'primary' : (str_contains($normalized, 'unique') ? 'unique' : 'index'),
            'index' => $name !== null && $name !== '' ? $this->cleanIdentifier($name) : implode('_', $columnNames),
            'columns' => $columnNames,
        ];
    }

    private function applyColumnDefaults(string $table, array $row): array
    {
        $columns = $this->store->schema()['tables'][$table]['columns'] ?? [];

        foreach ($columns as $name => $column) {
            if (array_key_exists($name, $row)) {
                continue;
            }

            $row[$name] = array_key_exists('default', $column) ? $column['default'] : null;
        }

        return $row;
    }

    private function guardUniqueConstraints(string $table, array $candidate, array $existingRows): void
    {
        $schema = $this->store->schema();
        $meta = $schema['tables'][$table] ?? [];
        $uniqueIndexes = [];

        if (!empty($meta['primary_key'])) {
            $uniqueIndexes[] = [
                'name' => 'primary',
                'index' => 'PRIMARY',
                'columns' => [(string) $meta['primary_key']],
            ];
        }

        foreach (($meta['indexes'] ?? []) as $index) {
            if (in_array((string) ($index['name'] ?? ''), ['primary', 'unique'], true)) {
                $uniqueIndexes[] = $index;
            }
        }

        foreach ($uniqueIndexes as $index) {
            $columns = array_values((array) ($index['columns'] ?? $index['column'] ?? []));
            if ($columns === []) {
                continue;
            }

            foreach ($existingRows as $row) {
                $matches = true;
                foreach ($columns as $column) {
                    $column = (string) $column;
                    if (($candidate[$column] ?? null) !== ($row[$column] ?? null)) {
                        $matches = false;
                        break;
                    }
                }

                if ($matches) {
                    throw new DevDbException('DevDB unique constraint violation on "' . (string) ($index['index'] ?? $index['name'] ?? implode('_', $columns)) . '".');
                }
            }
        }
    }

    private function bumpSequence(string $table, int $value): void
    {
        $sequences = $this->store->sequences();
        if ((int) ($sequences[$table] ?? 0) >= $value) {
            return;
        }

        $sequences[$table] = $value;
        $this->store->saveSequences($sequences);
    }

    /**
     * @return list<object>
     */
    private function showTables(?string $like = null): array
    {
        $tables = array_keys($this->store->schema()['tables'] ?? []);
        if ($like !== null && $like !== '') {
            $tables = array_values(array_filter($tables, fn (string $table): bool => $this->matchesLike($table, $like)));
        }

        return array_map(fn (string $table): object => (object) [
            'Tables_in_devdb' => $table,
            'table' => $table,
        ], $tables);
    }

    /**
     * @return list<object>
     */
    private function describeTable(string $table): array
    {
        $this->assertTable($table);
        $schema = $this->store->schema();
        $meta = $schema['tables'][$table] ?? [];
        $primary = (string) ($meta['primary_key'] ?? '');

        return array_map(function (string $name, array $column) use ($primary): object {
            return (object) [
                'Field' => $name,
                'Type' => $this->columnSqlType($column),
                'Null' => !empty($column['nullable']) ? 'YES' : 'NO',
                'Key' => $primary === $name || !empty($column['primary']) ? 'PRI' : '',
                'Default' => $column['default'] ?? null,
                'Extra' => !empty($column['auto_increment']) ? 'auto_increment' : '',
            ];
        }, array_keys($meta['columns'] ?? []), array_values($meta['columns'] ?? []));
    }

    /**
     * @return list<object>
     */
    private function showIndexes(string $table): array
    {
        $this->assertTable($table);
        $schema = $this->store->schema();
        $meta = $schema['tables'][$table] ?? [];
        $indexes = $meta['indexes'] ?? [];
        if (!empty($meta['primary_key'])) {
            array_unshift($indexes, [
                'name' => 'primary',
                'index' => 'PRIMARY',
                'columns' => [(string) $meta['primary_key']],
            ]);
        }

        $rows = [];
        foreach ($indexes as $index) {
            $columns = (array) ($index['columns'] ?? $index['column'] ?? []);
            foreach (array_values($columns) as $sequence => $column) {
                $rows[] = (object) [
                    'Table' => $table,
                    'Non_unique' => (($index['name'] ?? '') === 'unique' || ($index['name'] ?? '') === 'primary') ? 0 : 1,
                    'Key_name' => (string) ($index['index'] ?? $index['name'] ?? implode('_', $columns)),
                    'Seq_in_index' => $sequence + 1,
                    'Column_name' => (string) $column,
                ];
            }
        }

        return $rows;
    }

    private function columnSqlType(array $column): string
    {
        $type = (string) ($column['type'] ?? 'string');
        if (!empty($column['length'])) {
            return $type . '(' . $column['length'] . ')';
        }

        if (!empty($column['precision'])) {
            return $type . '(' . $column['precision'] . ',' . ($column['scale'] ?? 0) . ')';
        }

        return $type;
    }

    private function saveTableMetadata(string $table, array $columns, array $indexes): void
    {
        $schema = $this->store->schema();
        $schema['tables'][$table] = array_replace($schema['tables'][$table] ?? [], [
            'columns' => $columns,
            'indexes' => array_values($indexes),
            'primary_key' => $this->primaryKeyFromColumns($columns),
            'updated_at' => date(DATE_ATOM),
        ]);
        $this->store->saveSchema($schema);
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

    private function parseLiteral(string $value): mixed
    {
        $offset = 0;

        return $this->parseValue($value, [], $offset);
    }

    private function expressionValue(array $row, string $expression, array $bindings = [], int &$bindingOffset = 0): mixed
    {
        $expression = $this->trimOuterParentheses(trim($expression));

        if ($expression === '*') {
            return $row;
        }

        if ($expression === '?'
            || strtolower($expression) === 'null'
            || strtolower($expression) === 'true'
            || strtolower($expression) === 'false'
            || is_numeric($expression)
            || ((str_starts_with($expression, "'") && str_ends_with($expression, "'"))
                || (str_starts_with($expression, '"') && str_ends_with($expression, '"')))) {
            return $this->parseValue($expression, $bindings, $bindingOffset);
        }

        if (in_array(strtolower($expression), ['current_date', 'current_time', 'current_timestamp', 'now'], true)) {
            return $this->evaluateFunction(strtolower($expression), []);
        }

        if (preg_match('/^(?<function>[A-Za-z_][A-Za-z0-9_]*)\s*\((?<arguments>.*)\)$/is', $expression, $match) === 1) {
            $function = strtolower($match['function']);
            $arguments = trim($match['arguments']);
            $values = [];
            foreach ($arguments === '' ? [] : $this->splitCsv($arguments) as $argument) {
                $values[] = $this->expressionValue($row, $argument, $bindings, $bindingOffset);
            }

            return $this->evaluateFunction($function, $values);
        }

        return $this->valueForColumn($row, $expression);
    }

    /**
     * @param list<mixed> $values
     */
    private function evaluateFunction(string $function, array $values): mixed
    {
        return match ($function) {
            'date' => $this->formatDate($values[0] ?? 'now', 'Y-m-d'),
            'time' => $this->formatDate($values[0] ?? 'now', 'H:i:s'),
            'datetime', 'timestamp' => $this->formatDate($values[0] ?? 'now', 'Y-m-d H:i:s'),
            'year' => (int) $this->formatDate($values[0] ?? 'now', 'Y'),
            'month' => (int) $this->formatDate($values[0] ?? 'now', 'm'),
            'day', 'dayofmonth' => (int) $this->formatDate($values[0] ?? 'now', 'd'),
            'hour' => (int) $this->formatDate($values[0] ?? 'now', 'H'),
            'minute' => (int) $this->formatDate($values[0] ?? 'now', 'i'),
            'second' => (int) $this->formatDate($values[0] ?? 'now', 's'),
            'lower', 'lcase' => strtolower((string) ($values[0] ?? '')),
            'upper', 'ucase' => strtoupper((string) ($values[0] ?? '')),
            'trim' => trim((string) ($values[0] ?? '')),
            'ltrim' => ltrim((string) ($values[0] ?? '')),
            'rtrim' => rtrim((string) ($values[0] ?? '')),
            'length', 'char_length', 'character_length' => strlen((string) ($values[0] ?? '')),
            'coalesce', 'ifnull' => $this->firstNonNull($values),
            'nullif' => ($values[0] ?? null) == ($values[1] ?? null) ? null : ($values[0] ?? null),
            'concat' => implode('', array_map(fn (mixed $value): string => (string) $value, $values)),
            'substr', 'substring' => substr(
                (string) ($values[0] ?? ''),
                max(0, (int) ($values[1] ?? 1) - 1),
                isset($values[2]) ? (int) $values[2] : null,
            ),
            'replace' => str_replace((string) ($values[1] ?? ''), (string) ($values[2] ?? ''), (string) ($values[0] ?? '')),
            'abs' => abs((float) ($values[0] ?? 0)),
            'round' => round((float) ($values[0] ?? 0), (int) ($values[1] ?? 0)),
            'floor' => floor((float) ($values[0] ?? 0)),
            'ceil', 'ceiling' => ceil((float) ($values[0] ?? 0)),
            'current_date' => date('Y-m-d'),
            'current_time' => date('H:i:s'),
            'current_timestamp', 'now' => date('Y-m-d H:i:s'),
            default => throw DevDbException::unsupported('raw SQL function "' . strtoupper($function) . '"'),
        };
    }

    private function formatDate(mixed $value, string $format): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $timestamp = is_numeric($value) ? (int) $value : strtotime((string) $value);
        if ($timestamp === false) {
            return null;
        }

        return date($format, $timestamp);
    }

    private function firstNonNull(array $values): mixed
    {
        foreach ($values as $value) {
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function parseValueGroups(string $values): array
    {
        $groups = [];
        $depth = 0;
        $quote = null;
        $start = null;
        $length = strlen($values);

        for ($i = 0; $i < $length; $i++) {
            $char = $values[$i];

            if ($quote !== null) {
                if ($char === $quote && ($i === 0 || $values[$i - 1] !== '\\')) {
                    $quote = null;
                }
                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                continue;
            }

            if ($char === '(') {
                if ($depth === 0) {
                    $start = $i + 1;
                }
                $depth++;
                continue;
            }

            if ($char === ')') {
                $depth--;
                if ($depth === 0 && $start !== null) {
                    $groups[] = substr($values, $start, $i - $start);
                    $start = null;
                }
            }
        }

        if ($groups === []) {
            throw DevDbException::unsupported('raw INSERT VALUES syntax');
        }

        return $groups;
    }

    private function valueForColumn(array $row, mixed $column): mixed
    {
        $column = $this->cleanIdentifier((string) $column, false);
        if (array_key_exists($column, $row)) {
            return $row[$column];
        }

        $short = $this->shortColumn($column);

        return $row[$short] ?? null;
    }

    private function containsAggregate(string $columns): bool
    {
        return preg_match('/\b(count|sum|avg|min|max)\s*\(/i', $columns) === 1;
    }

    private function average(array $values): ?float
    {
        $numbers = array_values(array_filter($values, 'is_numeric'));

        return $numbers === [] ? null : array_sum(array_map('floatval', $numbers)) / count($numbers);
    }

    private function matchesLike(string $actual, string $pattern): bool
    {
        $regex = '/^' . str_replace(['%', '_'], ['.*', '.'], preg_quote($pattern, '/')) . '$/iu';

        return preg_match($regex, $actual) === 1;
    }

    /**
     * @return list<string>
     */
    private function splitCsv(string $value): array
    {
        return $this->splitOutsideQuotes($value, ',');
    }

    /**
     * @return list<string>
     */
    private function splitBoolean(string $value, string $boolean): array
    {
        return $this->splitOutsideQuotes($value, ' ' . $boolean . ' ', true);
    }

    /**
     * @return list<string>
     */
    private function splitOutsideQuotes(string $value, string $separator, bool $wordSeparator = false): array
    {
        $parts = [];
        $quote = null;
        $start = 0;
        $depth = 0;
        $length = strlen($value);
        $separatorLength = strlen($separator);

        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];

            if ($quote !== null) {
                if ($char === $quote && ($i === 0 || $value[$i - 1] !== '\\')) {
                    $quote = null;
                }
                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                continue;
            }

            if ($char === '(') {
                $depth++;
                continue;
            }

            if ($char === ')') {
                $depth--;
                continue;
            }

            $matched = $wordSeparator
                ? strcasecmp(substr($value, $i, $separatorLength), $separator) === 0
                : $char === $separator;

            if ($matched && $wordSeparator && trim(strtolower($separator)) === 'and') {
                $segment = substr($value, $start, $i - $start);
                if (preg_match('/\bbetween\b/i', $segment) === 1 && preg_match('/\s+and\s+/i', $segment) !== 1) {
                    continue;
                }
            }

            if ($depth === 0 && $matched) {
                $parts[] = trim(substr($value, $start, $i - $start));
                $i += $separatorLength - 1;
                $start = $i + 1;
            }
        }

        $parts[] = trim(substr($value, $start));

        return array_values(array_filter($parts, fn (string $part): bool => $part !== ''));
    }

    private function extractClause(string $tail, string $clause, array $until): string
    {
        if ($tail === '') {
            return '';
        }

        $pattern = '/\b' . preg_quote($clause, '/') . '\b/i';
        if (preg_match($pattern, $tail, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return '';
        }

        $start = $match[0][1] + strlen($match[0][0]);
        $end = strlen($tail);

        foreach ($until as $next) {
            if (preg_match('/\b' . preg_quote($next, '/') . '\b/i', $tail, $nextMatch, PREG_OFFSET_CAPTURE, $start) === 1) {
                $end = min($end, $nextMatch[0][1]);
            }
        }

        return trim(substr($tail, $start, $end - $start));
    }

    /**
     * @return array{0:string,1:string}
     */
    private function columnAndAlias(string $column): array
    {
        $column = trim($column);
        if (preg_match('/^(?<name>.+?)\s+as\s+(?<alias>[`"\[\]A-Za-z0-9_.-]+)$/i', $column, $match) === 1) {
            return [$this->cleanIdentifier($match['name'], false), $this->cleanIdentifier($match['alias'])];
        }

        if (preg_match('/^(?<name>[`"\[\]A-Za-z0-9_.-]+)\s+(?<alias>[`"\[\]A-Za-z0-9_.-]+)$/i', $column, $match) === 1) {
            return [$this->cleanIdentifier($match['name'], false), $this->cleanIdentifier($match['alias'])];
        }

        $name = $this->cleanIdentifier($column, false);

        return [$name, $this->shortColumn($name)];
    }

    private function cleanIdentifier(string $identifier, bool $short = true): string
    {
        $identifier = trim($identifier);
        $identifier = trim($identifier, '`"[] ');

        if ($short && str_contains($identifier, '.')) {
            return $this->shortColumn($identifier);
        }

        return trim($identifier, '`"[] ');
    }

    private function shortColumn(string $column): string
    {
        if (str_contains($column, '.')) {
            $parts = explode('.', $column);
            $column = (string) end($parts);
        }

        return trim($column, '`"[] ');
    }

    private function trimOuterParentheses(string $value): string
    {
        while (str_starts_with($value, '(') && str_ends_with($value, ')')) {
            $inner = substr($value, 1, -1);
            if ($this->balanced($inner)) {
                $value = trim($inner);
                continue;
            }

            break;
        }

        return $value;
    }

    private function countPlaceholders(string $value): int
    {
        $quote = null;
        $count = 0;
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            if ($quote !== null) {
                if ($char === $quote && ($i === 0 || $value[$i - 1] !== '\\')) {
                    $quote = null;
                }
                continue;
            }
            if ($char === "'" || $char === '"') {
                $quote = $char;
                continue;
            }
            if ($char === '?') {
                $count++;
            }
        }

        return $count;
    }

    private function balanced(string $value): bool
    {
        $depth = 0;
        $quote = null;
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            if ($quote !== null) {
                if ($char === $quote && ($i === 0 || $value[$i - 1] !== '\\')) {
                    $quote = null;
                }
                continue;
            }
            if ($char === "'" || $char === '"') {
                $quote = $char;
                continue;
            }
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth < 0) {
                    return false;
                }
            }
        }

        return $depth === 0 && $quote === null;
    }

    private function unqualifyProjectedRow(array $row): array
    {
        $result = [];
        foreach ($row as $key => $value) {
            if (!str_contains((string) $key, '.')) {
                $result[$key] = $value;
            }
        }

        return $result === [] ? $row : $result;
    }

    private function normalizeSql(string $sql): string
    {
        return rtrim(trim(preg_replace('/\s+/', ' ', $sql) ?? $sql), ';');
    }

    private function assertTable(string $table): void
    {
        if (!$this->store->hasTable($table)) {
            throw new DevDbException('DevDB table "' . $table . '" does not exist for raw SQL query.');
        }
    }

    private function primaryKey(string $table): ?string
    {
        $meta = $this->store->schema()['tables'][$table] ?? [];

        return isset($meta['primary_key']) ? (string) $meta['primary_key'] : null;
    }

    private function statementName(string $sql): string
    {
        return strtoupper(strtok(trim($sql), " \t\r\n") ?: 'SQL');
    }
}
