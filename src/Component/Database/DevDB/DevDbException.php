<?php

namespace Pinoox\Component\Database\DevDB;

class DevDbException extends \RuntimeException
{
    public static function unsupported(string $feature, ?string $table = null): self
    {
        $scope = $table !== null && $table !== '' ? ' on table "' . $table . '"' : '';

        return new self(
            'Pinoox DevDB does not support ' . $feature . $scope . '. '
            . 'Try simplifying the query, enabling the SQLite engine when available, or using MySQL/PostgreSQL for exact SQL-server behavior.',
        );
    }

    public static function invalidState(string $message, ?string $hint = null): self
    {
        return new self($hint === null ? $message : $message . ' Hint: ' . $hint);
    }
}
