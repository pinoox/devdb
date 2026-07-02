<?php

namespace Pinoox\Component\Database\DevDB\Engines;

use Pinoox\Component\Database\DevDB\DevDbStore;

final class DevDbEngineFactory
{
    public function make(string $engine, string $path, string $sqliteDatabase): DevDbEngineInterface
    {
        $engine = strtolower($engine);

        if ($engine === 'sqlite' || ($engine === 'auto' && extension_loaded('pdo_sqlite'))) {
            return new SqliteDevDbEngine($path, $sqliteDatabase);
        }

        return new JsonDevDbEngine(new DevDbStore($path));
    }
}
