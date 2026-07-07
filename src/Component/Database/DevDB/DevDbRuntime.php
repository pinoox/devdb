<?php

namespace Pinoox\Component\Database\DevDB;

use Pinoox\Component\Database\DevDB\Engines\DevDbEngineFactory;
use Pinoox\Component\Database\DevDB\Engines\DevDbEngineInterface;
use Pinoox\Support\SystemConfig;

final class DevDbRuntime
{
    public function __construct(
        private ?string $path = null,
        private ?string $engine = null,
        private ?string $sqliteDatabase = null,
        private ?string $connectionName = null,
    ) {
    }

    public static function create(): self
    {
        return new self();
    }

    /**
     * @param array<string, mixed> $entry
     */
    public static function fromCatalogEntry(array $entry): self
    {
        return new self(
            path: (string) ($entry['path'] ?? ''),
            engine: (string) ($entry['engine'] ?? 'auto'),
            sqliteDatabase: (string) ($entry['sqlite_database'] ?? ''),
            connectionName: (string) ($entry['name'] ?? ''),
        );
    }

    public static function forPath(string $path, ?string $engine = null): self
    {
        $path = SystemConfig::resolvePath($path);

        return new self(
            path: $path,
            engine: $engine ?? 'auto',
            sqliteDatabase: $path . '/devdb.sqlite',
        );
    }

    public function connectionName(): ?string
    {
        return $this->connectionName;
    }

    public function store(): DevDbStore
    {
        return new DevDbStore($this->path());
    }

    public function engineDriver(): DevDbEngineInterface
    {
        return (new DevDbEngineFactory())->make($this->configuredEngine(), $this->path(), $this->sqliteDatabase());
    }

    public function engine(): string
    {
        return $this->engineDriver()->name();
    }

    public function configuredEngine(): string
    {
        if ($this->engine !== null && $this->engine !== '') {
            $engine = strtolower(trim($this->engine));

            return in_array($engine, ['auto', 'sqlite', 'json'], true) ? $engine : 'auto';
        }

        $engine = strtolower(trim((string) SystemConfig::env('DEVDB_ENGINE', 'auto')));

        return in_array($engine, ['auto', 'sqlite', 'json'], true) ? $engine : 'auto';
    }

    public function path(): string
    {
        if ($this->path !== null && $this->path !== '') {
            return SystemConfig::resolvePath($this->path);
        }

        $path = (string) SystemConfig::env('DEVDB_PATH', '');
        if ($path === '') {
            $path = SystemConfig::resolvePath('~/storage/devdb');
        }

        return SystemConfig::resolvePath($path);
    }

    public function sqliteDatabase(): string
    {
        if ($this->sqliteDatabase !== null && $this->sqliteDatabase !== '') {
            return SystemConfig::resolvePath($this->sqliteDatabase);
        }

        $database = (string) SystemConfig::env('DEVDB_SQLITE_DATABASE', '');

        return $database !== '' ? SystemConfig::resolvePath($database) : $this->path() . '/devdb.sqlite';
    }

    public function status(): array
    {
        $status = $this->engineDriver()->status();
        $status['connection'] = $this->connectionName;

        return $status;
    }

    public function inspectTable(string $table, int $limit = 10, int $offset = 0): array
    {
        return $this->engineDriver()->inspectTable($table, $limit, $offset);
    }

    public function export(): array
    {
        return $this->engineDriver()->export();
    }

    public function clear(): void
    {
        $this->engineDriver()->clear();
    }
}
