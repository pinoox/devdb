<?php

namespace Pinoox\DevDB\Compat;

use Pinoox\DevDB\DevDatabase;
use Throwable;

class DevMysqli
{
    public int|string $insert_id = 0;

    public int|string $affected_rows = 0;

    public int $errno = 0;

    public string $error = '';

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

    public function query(string $query): DevMysqliResult|bool
    {
        $this->clearError();

        try {
            if ($this->isSelect($query)) {
                $rows = $this->database->select($query);
                $this->affected_rows = count($rows);

                return new DevMysqliResult($rows);
            }

            $this->affected_rows = $this->database->execute($query);
            $this->insert_id = $this->database->lastInsertId() ?? 0;

            return true;
        } catch (Throwable $exception) {
            $this->errno = 1;
            $this->error = $exception->getMessage();
            $this->affected_rows = -1;

            return false;
        }
    }

    public function real_escape_string(string $string): string
    {
        return addslashes($string);
    }

    public function escape_string(string $string): string
    {
        return $this->real_escape_string($string);
    }

    public function begin_transaction(): bool
    {
        return $this->database->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->database->commit();
    }

    public function rollback(): bool
    {
        return $this->database->rollBack();
    }

    public function close(): bool
    {
        return true;
    }

    private function clearError(): void
    {
        $this->errno = 0;
        $this->error = '';
    }

    private function isSelect(string $sql): bool
    {
        return preg_match('/^(select|show|describe|desc|explain)\b/i', trim($sql)) === 1;
    }
}
