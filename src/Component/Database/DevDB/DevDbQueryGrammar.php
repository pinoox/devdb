<?php

namespace Pinoox\Component\Database\DevDB;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;

class DevDbQueryGrammar extends SQLiteGrammar
{
    public function __construct(Connection $connection)
    {
        if (method_exists(SQLiteGrammar::class, '__construct')) {
            parent::__construct($connection);
        }

        $this->connection = $connection;
    }

    protected function wrapAliasedTable($value, $prefix = null)
    {
        $segments = preg_split('/\s+as\s+/i', $value);

        $prefix ??= $this->connection->getTablePrefix();

        return $this->wrapTable($segments[0], $prefix) . ' as ' . $this->wrapValue($segments[1]);
    }

    protected function wrapAliasedValue($value, $prefixAlias = false)
    {
        $segments = preg_split('/\s+as\s+/i', $value);

        return $this->wrap($segments[0]) . ' as ' . $this->wrapValue($segments[1]);
    }

    protected function wrapSegments($segments)
    {
        if (count($segments) > 1) {
            return collect($segments)->map(fn ($segment) => $this->wrapValue($segment))->implode('.');
        }

        return parent::wrapSegments($segments);
    }
}
