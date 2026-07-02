<?php

namespace Pinoox\Component\Database\DevDB\Engines;

use Pinoox\Component\Database\DevDB\DevDbStore;

final class JsonDevDbEngine implements DevDbEngineInterface
{
    public function __construct(private DevDbStore $store)
    {
    }

    public function name(): string
    {
        return 'json';
    }

    public function path(): string
    {
        return $this->store->root();
    }

    public function store(): DevDbStore
    {
        return $this->store;
    }

    public function status(): array
    {
        $status = $this->store->status();
        $status['engine'] = $this->name();

        return $status;
    }

    public function inspectTable(string $table, int $limit = 10): array
    {
        return $this->store->inspectTable($table, $limit);
    }

    public function export(): array
    {
        return $this->store->export();
    }

    public function clear(): void
    {
        $this->store->clear();
    }
}
