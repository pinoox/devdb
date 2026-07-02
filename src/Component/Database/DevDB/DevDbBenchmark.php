<?php

namespace Pinoox\Component\Database\DevDB;

use Pinoox\DevDB\DevDatabase;

final class DevDbBenchmark
{
    public function run(DevDatabase $db, int $rows = 100): array
    {
        $rows = max(1, $rows);
        $table = 'devdb_benchmark_' . substr(md5((string) microtime(true)), 0, 8);

        $started = microtime(true);
        $db->statement('create table ' . $table . ' (id integer primary key auto_increment, name varchar(80), score int)');
        $schemaMs = $this->elapsedMs($started);

        $started = microtime(true);
        for ($i = 1; $i <= $rows; $i++) {
            $db->statement('insert into ' . $table . ' (name, score) values (?, ?)', ['Row ' . $i, $i % 10]);
        }
        $insertMs = $this->elapsedMs($started);

        $started = microtime(true);
        $selected = $db->select('select * from ' . $table . ' where score >= ? order by id desc limit 10', [5]);
        $selectMs = $this->elapsedMs($started);

        $started = microtime(true);
        $count = $db->selectOne('select count(*) as total from ' . $table)->total ?? 0;
        $countMs = $this->elapsedMs($started);

        $db->statement('drop table if exists ' . $table);

        return [
            'rows' => $rows,
            'schema_ms' => $schemaMs,
            'insert_ms' => $insertMs,
            'select_ms' => $selectMs,
            'count_ms' => $countMs,
            'selected_rows' => count($selected),
            'counted_rows' => (int) $count,
            'total_ms' => round($schemaMs + $insertMs + $selectMs + $countMs, 3),
        ];
    }

    private function elapsedMs(float $started): float
    {
        return round((microtime(true) - $started) * 1000, 3);
    }
}
