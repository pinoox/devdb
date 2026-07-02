<?php

use Pinoox\DevDB\DevDatabase;

it('runs standalone CRUD and raw SQL queries without framework bootstrap', function () {
    $path = devdb_test_path('standalone');
    $db = DevDatabase::open($path);
    $db->createTable('notes', [
        'id' => ['type' => 'integer', 'primary' => true, 'auto_increment' => true],
        'body' => 'string',
        'created_at' => 'string',
    ]);

    expect($db->statement(
        'insert into notes (body, created_at) values (?, ?), (?, ?)',
        ['First', '2026-06-29 10:00:00', 'Second', '2026-06-30 10:00:00'],
    ))->toBeTrue();

    $rows = $db->select(
        'select id, upper(body) as title, date(created_at) as day from notes where date(created_at) >= ? order by id desc',
        ['2026-06-29'],
    );
    $latest = $db->selectOne('select * from notes order by id desc limit 1');

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->id)->toBe(2)
        ->and($rows[0]->title)->toBe('SECOND')
        ->and($latest->body)->toBe('Second')
        ->and($db->execute('delete from notes where id = ?', [1]))->toBe(1);

    $db->clear();
    expect($db->store()->status()['table_count'])->toBe(0);

    devdb_remove_path($path);
});

it('creates schema through raw SQL in standalone mode', function () {
    $path = devdb_test_path('standalone_schema');
    $db = DevDatabase::open($path);

    $db->statement('create table users (id integer primary key auto_increment, email varchar(190) not null, created_at datetime)');
    $db->statement('create unique index users_email_unique on users (email)');
    $db->statement('insert into users (email, created_at) values (?, ?)', ['ava@example.com', '2026-06-29 10:00:00']);

    $tables = $db->select('show tables');
    $columns = $db->select('describe users');
    $indexes = $db->select('show index from users');

    expect($tables[0]->table)->toBe('users')
        ->and(array_map(fn ($column) => $column->Field, $columns))->toBe(['id', 'email', 'created_at'])
        ->and($columns[0]->Extra)->toBe('auto_increment')
        ->and(array_map(fn ($index) => $index->Key_name, $indexes))->toContain('users_email_unique');

    devdb_remove_path($path);
});

it('exposes snapshots and change manifests through the standalone API', function () {
    $path = devdb_test_path('standalone_snapshots');
    $db = DevDatabase::open($path);
    $db->statement('create table notes (id integer primary key, body varchar(120))');
    $db->statement('insert into notes (id, body) values (?, ?)', [1, 'Before']);

    $db->writeManifest();
    $snapshot = $db->snapshot('checkpoint');
    $db->statement('update notes set body = ? where id = ?', ['After', 1]);

    expect($snapshot['name'])->toBe('checkpoint')
        ->and($db->hasChangesSinceManifest())->toBeTrue()
        ->and($db->selectOne('select body from notes where id = ?', [1])->body)->toBe('After')
        ->and($db->snapshots()[0]['name'])->toBe('checkpoint');

    $db->restoreSnapshot('checkpoint');

    expect($db->selectOne('select body from notes where id = ?', [1])->body)->toBe('Before')
        ->and($db->deleteSnapshot('checkpoint'))->toBeTrue();

    devdb_remove_path($path);
});
