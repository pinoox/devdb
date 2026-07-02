<?php

namespace Pinoox\DevDB\Compat;

class DevMysqliResult
{
    public int|string $num_rows = 0;

    /** @var list<object> */
    private array $rows;

    private int $cursor = 0;

    /**
     * @param list<object> $rows
     */
    public function __construct(array $rows)
    {
        $this->rows = array_values($rows);
        $this->num_rows = count($this->rows);
    }

    public function fetch_assoc(): ?array
    {
        $row = $this->rows[$this->cursor] ?? null;
        if ($row === null) {
            return null;
        }

        $this->cursor++;

        return (array) $row;
    }

    public function fetch_object(?string $class = null, array $constructor_args = []): object|null
    {
        $row = $this->fetch_assoc();
        if ($row === null) {
            return null;
        }

        if ($class !== null && class_exists($class)) {
            $object = new $class(...$constructor_args);
            foreach ($row as $key => $value) {
                $object->{$key} = $value;
            }

            return $object;
        }

        return (object) $row;
    }

    public function fetch_array(): ?array
    {
        $row = $this->fetch_assoc();

        return $row === null ? null : array_replace($row, array_values($row));
    }

    public function fetch_all(): array
    {
        $rows = [];
        while (($row = $this->fetch_assoc()) !== null) {
            $rows[] = $row;
        }

        return $rows;
    }

    public function data_seek(int $offset): bool
    {
        if ($offset < 0 || $offset >= count($this->rows)) {
            return false;
        }

        $this->cursor = $offset;

        return true;
    }

    public function free(): void
    {
        $this->rows = [];
        $this->cursor = 0;
        $this->num_rows = 0;
    }
}
