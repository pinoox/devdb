<?php

use Pinoox\Component\Database\DevDB\DevDbBenchmark;
use Pinoox\DevDB\DevDatabase;

it('runs a small benchmark without leaving temporary tables behind', function () {
    $db = DevDatabase::open(devdb_test_path('benchmark'));

    $result = (new DevDbBenchmark())->run($db, 12);

    expect($result['rows'])->toBe(12)
        ->and($result['counted_rows'])->toBe(12)
        ->and($result['selected_rows'])->toBeGreaterThan(0)
        ->and($result['total_ms'])->toBeGreaterThanOrEqual(0)
        ->and($db->select('show tables'))->toBe([]);
});
