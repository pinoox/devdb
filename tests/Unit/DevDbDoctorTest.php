<?php

use Pinoox\Component\Database\DevDB\DevDbDoctor;
use Pinoox\Component\Database\DevDB\DevDbStore;

it('detects and repairs stale sequences', function () {
    $store = new DevDbStore(devdb_test_path('doctor'));
    $store->createTable('users', [
        'id' => ['type' => 'integer', 'primary' => true, 'auto_increment' => true],
        'email' => ['type' => 'string'],
    ]);
    $store->replaceTable('users', [
        ['id' => 7, 'email' => 'ava@example.com'],
    ]);
    $store->saveSequences(['users' => 2]);

    $doctor = new DevDbDoctor($store);

    expect($doctor->inspect()['ok'])->toBeFalse()
        ->and($doctor->inspect()['issues'][0]['type'])->toBe('stale_sequence');

    $repair = $doctor->repair();

    expect($repair['after']['ok'])->toBeTrue()
        ->and($store->sequences()['users'])->toBe(7)
        ->and($repair['repairs'][0]['type'])->toBe('sequence_updated');
});
