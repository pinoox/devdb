<?php

namespace Pinoox\DevDB\Compat;

use Pinoox\DevDB\DevDatabase;

class DevPDO
{
    public const FETCH_ASSOC = \PDO::FETCH_ASSOC;
    public const FETCH_NUM = \PDO::FETCH_NUM;
    public const FETCH_BOTH = \PDO::FETCH_BOTH;
    public const FETCH_OBJ = \PDO::FETCH_OBJ;
    public const PARAM_NULL = \PDO::PARAM_NULL;
    public const PARAM_INT = \PDO::PARAM_INT;
    public const PARAM_STR = \PDO::PARAM_STR;
    public const PARAM_BOOL = \PDO::PARAM_BOOL;

    private DevDatabase $database;

    public function __construct(?string $path = null, array $options = [])
    {
        $this->database = DevDatabase::open($path);

        if (array_key_exists('strict', $options)) {
            $this->database->strict((bool) $options['strict']);
        }
    }

    public static function open(?string $path = null, array $options = []): self
    {
        return new self($path, $options);
    }

    public function database(): DevDatabase
    {
        return $this->database;
    }

    public function query(string $query, ?int $fetchMode = null): DevPDOStatement|false
    {
        $statement = new DevPDOStatement($this->database, $query);

        return $statement->execute() ? $statement->setFetchMode($fetchMode ?? self::FETCH_BOTH) : false;
    }

    public function prepare(string $query, array $options = []): DevPDOStatement|false
    {
        return new DevPDOStatement($this->database, $query);
    }

    public function exec(string $statement): int|false
    {
        return $this->database->execute($statement);
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return (string) ($this->database->lastInsertId() ?? '0');
    }

    public function beginTransaction(): bool
    {
        return $this->database->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->database->commit();
    }

    public function rollBack(): bool
    {
        return $this->database->rollBack();
    }

    public function quote(string $string, int $type = self::PARAM_STR): string|false
    {
        return "'" . str_replace("'", "''", $string) . "'";
    }
}
