<?php

namespace Pinoox\Component\Database\DevDB\Engines;

interface DevDbEngineInterface
{
    public function name(): string;

    public function path(): string;

    public function status(): array;

    public function inspectTable(string $table, int $limit = 10, int $offset = 0): array;

    public function export(): array;

    public function clear(): void;
}
