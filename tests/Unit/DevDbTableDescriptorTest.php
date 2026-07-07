<?php

use Pinoox\Component\Database\DevDB\DevDbStore;
use Pinoox\Component\Database\DevDB\DevDbTableDescriptor;

it('enriches inspect metadata with foreign keys and indexes', function () {
    $inspect = DevDbTableDescriptor::enrich([
        'table' => 'posts',
        'columns' => [],
        'indexes' => [
            ['name' => 'primary', 'index' => 'primary', 'columns' => ['id']],
            ['name' => 'unique', 'index' => 'posts_slug_unique', 'columns' => ['slug']],
            ['name' => 'index', 'index' => 'posts_status_index', 'columns' => ['status']],
            [
                'name' => 'foreign',
                'index' => 'posts_user_id_foreign',
                'columns' => ['user_id'],
                'on' => 'users',
                'references' => ['id'],
                'onUpdate' => 'cascade',
                'onDelete' => 'set null',
            ],
        ],
        'rows' => [],
        'row_count' => 0,
    ]);

    expect($inspect['foreign_keys'])->toHaveCount(1)
        ->and($inspect['foreign_keys'][0]['referenced_table'])->toBe('users')
        ->and($inspect['unique_indexes'])->toHaveCount(1)
        ->and($inspect['indexes_list'])->toHaveCount(1)
        ->and($inspect['primary_index']['columns'])->toBe(['id']);
});

it('describes stored tables with unique indexes and row counts', function () {
    $path = devdb_test_path('descriptor');
    $store = new DevDbStore($path);
    $store->createTable('users', [
        'id' => ['type' => 'integer', 'primary' => true, 'auto_increment' => true],
        'email' => ['type' => 'string', 'nullable' => false],
    ], [
        ['name' => 'unique', 'index' => 'users_email_unique', 'columns' => ['email']],
    ]);
    $store->replaceTable('users', [
        ['id' => 1, 'email' => 'ava@example.com'],
    ]);

    $inspect = DevDbTableDescriptor::enrich($store->inspectTable('users', 5));

    expect($inspect['row_count'])->toBe(1)
        ->and($inspect['unique_indexes'][0]['name'])->toBe('users_email_unique')
        ->and($inspect['foreign_keys'])->toBe([]);

    devdb_remove_path($path);
});
