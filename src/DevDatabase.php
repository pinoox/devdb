<?php

namespace Pinoox\DevDB;

use Pinoox\Component\Database\DevDB\DevDbSqlTranslator;
use Pinoox\Component\Database\DevDB\DevDbStore;

final class DevDatabase
{
    private DevDbStore $store;

    private DevDbSqlTranslator $sql;

    private bool $strict = true;

    public function __construct(?string $path = null)
    {
        $this->store = new DevDbStore($path);
        $this->sql = new DevDbSqlTranslator($this->store, $this->strict);
    }

    public static function open(?string $path = null): self
    {
        return new self($path);
    }

    public function store(): DevDbStore
    {
        return $this->store;
    }

    /**
     * @param array<string, string|array<string, mixed>> $columns
     */
    public function createTable(string $table, array $columns, array $indexes = []): void
    {
        $metadata = [];

        foreach ($columns as $name => $definition) {
            $definition = is_array($definition) ? $definition : ['type' => (string) $definition];
            $metadata[(string) $name] = array_replace([
                'type' => 'string',
                'nullable' => true,
                'primary' => false,
                'auto_increment' => false,
            ], $definition);
        }

        $this->store->createTable($table, $metadata, $indexes);
    }

    public function select(string $sql, array $bindings = []): array
    {
        return $this->sql->select($sql, $bindings);
    }

    public function selectOne(string $sql, array $bindings = []): ?object
    {
        return $this->select($sql, $bindings)[0] ?? null;
    }

    public function execute(string $sql, array $bindings = []): int
    {
        return $this->sql->execute($sql, $bindings);
    }

    public function lastInsertId(): ?int
    {
        return $this->sql->lastInsertId();
    }

    /**
     * Execute a multi-statement SQL dump. Statements that are known MySQL
     * compatibility commands are accepted as no-ops.
     */
    public function executeDump(string $sql): array
    {
        return $this->sql->executeDump($sql);
    }

    /**
     * Return a small debug plan that explains how DevDB understands a query.
     */
    public function explain(string $sql): array
    {
        return $this->sql->explain($sql);
    }

    public function strict(bool $enabled = true): self
    {
        $this->strict = $enabled;
        $this->sql = new DevDbSqlTranslator($this->store, $this->strict);

        return $this;
    }

    public function statement(string $sql, array $bindings = []): bool
    {
        $this->execute($sql, $bindings);

        return true;
    }

    public function clear(): void
    {
        $this->store->clear();
    }

    public function beginTransaction(): bool
    {
        $this->store->beginTransaction();

        return true;
    }

    public function commit(): bool
    {
        $this->store->commitTransaction();

        return true;
    }

    public function rollBack(): bool
    {
        $this->store->rollbackTransaction();

        return true;
    }

    public function savepoint(string $name): bool
    {
        $this->store->savepoint($name);

        return true;
    }

    public function rollbackToSavepoint(string $name): bool
    {
        $this->store->rollbackToSavepoint($name);

        return true;
    }

    public function releaseSavepoint(string $name): bool
    {
        $this->store->releaseSavepoint($name);

        return true;
    }

    public function snapshot(?string $name = null): array
    {
        return $this->store->snapshot($name);
    }

    public function snapshots(): array
    {
        return $this->store->snapshots();
    }

    public function restoreSnapshot(string $name): void
    {
        $this->store->restoreSnapshot($name);
    }

    public function deleteSnapshot(string $name): bool
    {
        return $this->store->deleteSnapshot($name);
    }

    public function writeManifest(): array
    {
        return $this->store->writeManifest();
    }

    public function hasChangesSinceManifest(): bool
    {
        return $this->store->hasChangesSinceManifest();
    }
}
