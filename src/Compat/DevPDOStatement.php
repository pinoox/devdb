<?php

namespace Pinoox\DevDB\Compat;

use Pinoox\DevDB\DevDatabase;

class DevPDOStatement
{
    /** @var list<object> */
    private array $rows = [];

    /** @var array<int|string,mixed> */
    private array $boundValues = [];

    private int $cursor = 0;

    private int $affectedRows = 0;

    private int $fetchMode = DevPDO::FETCH_BOTH;

    public function __construct(private DevDatabase $database, private string $sql)
    {
    }

    public function execute(?array $params = null): bool
    {
        $bindings = $this->normalizeBindings($params ?? $this->boundValues);
        $sql = $this->normalizeSqlPlaceholders($this->sql, $params ?? $this->boundValues);
        $this->cursor = 0;
        $this->rows = [];
        $this->affectedRows = 0;

        if ($this->isSelect($sql)) {
            $this->rows = $this->database->select($sql, $bindings);
            $this->affectedRows = count($this->rows);

            return true;
        }

        $this->affectedRows = $this->database->execute($sql, $bindings);

        return true;
    }

    public function bindValue(int|string $param, mixed $value, int $type = DevPDO::PARAM_STR): bool
    {
        $this->boundValues[$param] = $value;

        return true;
    }

    public function bindParam(int|string $param, mixed &$var, int $type = DevPDO::PARAM_STR): bool
    {
        $this->boundValues[$param] = $var;

        return true;
    }

    public function fetch(?int $mode = null): mixed
    {
        $row = $this->rows[$this->cursor] ?? null;
        if ($row === null) {
            return false;
        }

        $this->cursor++;

        return $this->formatRow((array) $row, $mode ?? $this->fetchMode);
    }

    public function fetchAll(?int $mode = null): array
    {
        $mode ??= $this->fetchMode;
        $rows = array_slice($this->rows, $this->cursor);
        $this->cursor = count($this->rows);

        return array_map(fn (object $row): mixed => $this->formatRow((array) $row, $mode), $rows);
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $row = $this->fetch(DevPDO::FETCH_NUM);

        return is_array($row) ? ($row[$column] ?? false) : false;
    }

    public function rowCount(): int
    {
        return $this->affectedRows;
    }

    public function setFetchMode(int $mode): self
    {
        $this->fetchMode = $mode;

        return $this;
    }

    private function isSelect(string $sql): bool
    {
        return preg_match('/^(select|show|describe|desc|explain)\b/i', trim($sql)) === 1;
    }

    /**
     * @param array<int|string,mixed> $params
     * @return list<mixed>
     */
    private function normalizeBindings(array $params): array
    {
        if ($params === []) {
            return [];
        }

        if (array_is_list($params)) {
            return array_values($params);
        }

        $bindings = [];
        foreach ($this->namedPlaceholders($this->sql) as $name) {
            $bindings[] = $params[$name] ?? $params[':' . $name] ?? null;
        }

        return $bindings;
    }

    /**
     * @param array<int|string,mixed> $params
     */
    private function normalizeSqlPlaceholders(string $sql, array $params): string
    {
        if ($params === [] || array_is_list($params)) {
            return $sql;
        }

        return preg_replace('/:[A-Za-z_][A-Za-z0-9_]*/', '?', $sql) ?? $sql;
    }

    /**
     * @return list<string>
     */
    private function namedPlaceholders(string $sql): array
    {
        preg_match_all('/:([A-Za-z_][A-Za-z0-9_]*)/', $sql, $matches);

        return $matches[1] ?? [];
    }

    private function formatRow(array $row, int $mode): mixed
    {
        return match ($mode) {
            DevPDO::FETCH_ASSOC => $row,
            DevPDO::FETCH_NUM => array_values($row),
            DevPDO::FETCH_OBJ => (object) $row,
            default => array_replace($row, array_values($row)),
        };
    }
}
