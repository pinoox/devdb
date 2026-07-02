<?php

use Pinoox\DevDB\DevDatabase;

it('supports schema introspection commands', function () {
    $db = DevDatabase::open(devdb_test_path('schema_introspection'));
    $db->statement('create table users (id integer primary key auto_increment, name varchar(120) not null, email varchar(190) default null)');
    $db->statement('create index users_name_index on users (name)');

    $tables = $db->select('show tables');
    $tablesLike = $db->select("show tables like 'user%'");
    $columns = $db->select('show columns from users');
    $indexes = $db->select('show indexes from users');

    expect(array_map(fn ($table) => $table->table, $tables))->toBe(['users'])
        ->and(array_map(fn ($table) => $table->table, $tablesLike))->toBe(['users'])
        ->and($columns[0]->Field)->toBe('id')
        ->and($columns[0]->Key)->toBe('PRI')
        ->and($columns[1]->Null)->toBe('NO')
        ->and(array_map(fn ($index) => $index->Key_name, $indexes))->toContain('PRIMARY', 'users_name_index');
});

it('accepts database-level W3Schools MySQL statements as local compatibility commands', function () {
    $db = DevDatabase::open(devdb_test_path('schema_database_commands'));

    expect($db->statement('CREATE DATABASE testDB'))->toBeTrue()
        ->and($db->statement('USE testDB'))->toBeTrue()
        ->and($db->select('SHOW DATABASES')[0]->database)->toBe('devdb')
        ->and($db->statement('DROP DATABASE testDB'))->toBeTrue();
});

it('supports schema mutation commands', function () {
    $db = DevDatabase::open(devdb_test_path('schema_mutation'));
    $db->statement('create table users (id integer primary key auto_increment, email varchar(190))');
    $db->statement('insert into users (email) values (?)', ['old@example.com']);
    $db->statement('alter table users add column status varchar(20) default "active"');
    $db->statement('alter table users rename column status to state');
    $db->statement('alter table users add index users_state_index (state)');

    expect(array_map(fn ($column) => $column->Field, $db->select('desc users')))->toBe(['id', 'email', 'state'])
        ->and(array_map(fn ($index) => $index->Key_name, $db->select('show keys from users')))->toContain('users_state_index')
        ->and($db->selectOne('select state from users where email = ?', ['old@example.com'])->state)->toBe('active');

    $db->statement('alter table users drop column state');
    $db->statement('drop index users_state_index on users');

    expect(array_map(fn ($column) => $column->Field, $db->select('desc users')))->toBe(['id', 'email'])
        ->and(array_map(fn ($index) => $index->Key_name, $db->select('show keys from users')))->not->toContain('users_state_index');

    expect($db->execute('drop table if exists users'))->toBe(1)
        ->and($db->select('show tables'))->toBe([]);
});

it('backfills existing rows when adding a defaulted column', function () {
    $db = DevDatabase::open(devdb_test_path('schema_add_column_default_backfill'));
    $db->statement('create table posts (id integer primary key auto_increment, title varchar(120))');
    $db->statement('insert into posts (title) values (?)', ['Before migration']);

    $db->statement('alter table posts add column status varchar(20) not null default "draft"');
    $db->statement('insert into posts (title) values (?)', ['After migration']);

    $rows = $db->select('select id, title, status from posts order by id');

    expect($rows[0]->status)->toBe('draft')
        ->and($rows[1]->status)->toBe('draft');
});

it('supports modify and change column schema mutations', function () {
    $db = DevDatabase::open(devdb_test_path('schema_modify_change'));
    $db->statement('create table users (id integer primary key auto_increment, email varchar(120), status varchar(20) default "pending")');
    $db->statement('create index users_status_index on users (status)');
    $db->statement('insert into users (email, status) values (?, ?)', ['old@example.com', 'active']);

    $db->statement('alter table users modify column email varchar(190) not null');
    $db->statement('alter table users change column status state varchar(30) default "active"');

    $columns = $db->select('desc users');
    $indexes = $db->select('show keys from users');

    expect(array_map(fn ($column) => $column->Field, $columns))->toBe(['id', 'email', 'state'])
        ->and($columns[1]->Null)->toBe('NO')
        ->and($columns[1]->Type)->toBe('string(190)')
        ->and($db->selectOne('select state from users where email = ?', ['old@example.com'])->state)->toBe('active')
        ->and($indexes[1]->Column_name)->toBe('state');
});

it('renames tables while preserving rows and metadata', function () {
    $db = DevDatabase::open(devdb_test_path('schema_rename'));
    $db->statement('create table old_posts (id integer primary key auto_increment, title varchar(120))');
    $db->statement('insert into old_posts (title) values (?)', ['Hello']);
    $db->statement('alter table old_posts rename to posts');

    expect($db->select('show tables')[0]->table)->toBe('posts')
        ->and($db->selectOne('select * from posts')->title)->toBe('Hello');
});
