<?php

use Pinoox\Component\Database\DevDB\DevDbException;
use Pinoox\Component\Database\DevDB\DevDbStore;

it('stores schema, rows, indexes, sequences, and table inspection metadata', function () {
    $path = devdb_test_path('store');
    $store = new DevDbStore($path);

    $store->createTable('users', [
        'id' => ['type' => 'integer', 'primary' => true, 'auto_increment' => true],
        'email' => ['type' => 'string', 'nullable' => false],
        'name' => ['type' => 'string', 'nullable' => true],
    ], [
        ['name' => 'unique', 'index' => 'users_email_unique', 'columns' => ['email']],
    ]);

    $id = $store->nextId('users');
    $store->replaceTable('users', [
        ['id' => $id, 'email' => 'ava@example.com', 'name' => 'Ava'],
    ]);

    $status = $store->status();
    $inspect = $store->inspectTable('users', 10);
    $indexes = json_decode((string) file_get_contents($path . '/meta/indexes.json'), true);

    expect($store->hasTable('users'))->toBeTrue()
        ->and($status['table_count'])->toBe(1)
        ->and($status['tables'][0]['rows'])->toBe(1)
        ->and($status['row_count'])->toBe(1)
        ->and($status['data_size'])->toBeGreaterThan(0)
        ->and($status['tables'][0]['indexes'])->toBe(1)
        ->and($inspect['primary_key'])->toBe('id')
        ->and($inspect['row_count'])->toBe(1)
        ->and($inspect['rows'][0]['email'])->toBe('ava@example.com')
        ->and($indexes['users']['primary']['columns'])->toBe(['id'])
        ->and($indexes['users']['users_email_unique']['columns'])->toBe(['email'])
        ->and(is_file($path . '/schema.json'))->toBeTrue()
        ->and(is_file($path . '/data/users.json'))->toBeTrue()
        ->and(is_file($path . '/meta/sequences.json'))->toBeTrue();

    devdb_remove_path($path);
});

it('exports, imports, clears, and restores transaction snapshots', function () {
    $path = devdb_test_path('store_export');
    $store = new DevDbStore($path);
    $store->createTable('notes', [
        'id' => ['type' => 'integer', 'primary' => true, 'auto_increment' => true],
        'body' => ['type' => 'string'],
    ]);
    $store->replaceTable('notes', [
        ['id' => 1, 'body' => 'Before'],
    ]);

    $export = $store->export();
    $store->clear();

    expect($store->status()['table_count'])->toBe(0);

    $store->import($export);
    expect($store->inspectTable('notes')['rows'][0]['body'])->toBe('Before');

    $store->beginTransaction();
    $store->replaceTable('notes', [
        ['id' => 1, 'body' => 'Changed'],
    ]);
    expect($store->inspectTable('notes')['rows'][0]['body'])->toBe('Changed');
    $store->rollbackTransaction();
    expect($store->inspectTable('notes')['rows'][0]['body'])->toBe('Before');

    $store->beginTransaction();
    $store->replaceTable('notes', [
        ['id' => 1, 'body' => 'Committed'],
    ]);
    $store->commitTransaction();
    expect($store->inspectTable('notes')['rows'][0]['body'])->toBe('Committed');

    devdb_remove_path($path);
});

it('creates, restores, lists, and deletes named snapshots', function () {
    $path = devdb_test_path('store_snapshots');
    $store = new DevDbStore($path);
    $store->createTable('notes', [
        'id' => ['type' => 'integer', 'primary' => true, 'auto_increment' => true],
        'body' => ['type' => 'string'],
    ]);
    $store->replaceTable('notes', [
        ['id' => 1, 'body' => 'Before'],
    ]);

    $snapshot = $store->snapshot('before-change');
    $store->replaceTable('notes', [
        ['id' => 1, 'body' => 'After'],
    ]);

    expect($snapshot['name'])->toBe('before-change')
        ->and(is_file($snapshot['path']))->toBeTrue()
        ->and($store->snapshots()[0]['name'])->toBe('before-change')
        ->and($store->inspectTable('notes')['rows'][0]['body'])->toBe('After');

    $store->restoreSnapshot('before-change');

    expect($store->inspectTable('notes')['rows'][0]['body'])->toBe('Before')
        ->and($store->deleteSnapshot('before-change'))->toBeTrue()
        ->and($store->snapshots())->toBe([]);

    devdb_remove_path($path);
});

it('tracks file changes using a manifest', function () {
    $path = devdb_test_path('store_manifest');
    $store = new DevDbStore($path);
    $store->createTable('notes', [
        'id' => ['type' => 'integer', 'primary' => true],
        'body' => ['type' => 'string'],
    ]);
    $store->replaceTable('notes', [
        ['id' => 1, 'body' => 'Stable'],
    ]);

    expect($store->hasChangesSinceManifest())->toBeTrue();

    $manifest = $store->writeManifest();
    expect($manifest['files'])->not->toBeEmpty()
        ->and($manifest['file_count'])->toBeGreaterThan(0)
        ->and($manifest['total_size'])->toBeGreaterThan(0)
        ->and($store->hasChangesSinceManifest())->toBeFalse();

    $store->replaceTable('notes', [
        ['id' => 1, 'body' => 'Changed'],
    ]);

    expect($store->hasChangesSinceManifest())->toBeTrue();

    devdb_remove_path($path);
});

it('throws clear errors for missing tables', function () {
    $store = new DevDbStore(devdb_test_path('store_missing'));

    expect(fn () => $store->inspectTable('missing'))->toThrow(DevDbException::class, 'does not exist');
});

it('records migration checksums and reports migration status', function () {
    $store = new DevDbStore(devdb_test_path('store_migrations'));
    $store->recordMigration('com_example_blog', '2026_07_02_000000_create_posts', 1, 'abc123');

    $status = $store->migrationStatus();

    expect($status['count'])->toBe(1)
        ->and($status['last_batch'])->toBe(1)
        ->and($status['records'][0]['checksum'])->toBe('abc123')
        ->and($status['records'][0]['executed_at'])->not->toBeEmpty();
});
