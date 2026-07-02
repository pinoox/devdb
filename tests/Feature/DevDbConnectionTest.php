<?php

use Pinoox\Component\Database\Connections\DevDbConnection;

it('supports Illuminate schema builder and query builder CRUD operations', function () {
    $path = devdb_test_path('connection_builder');
    $connection = new DevDbConnection(null, 'devdb', '', ['path' => $path]);

    $connection->getSchemaBuilder()->create('posts', function ($table) {
        $table->increments('id');
        $table->string('title');
        $table->string('status')->nullable();
        $table->integer('views')->nullable();
    });

    $id = $connection->table('posts')->insertGetId([
        'title' => 'Hello',
        'status' => 'draft',
        'views' => 5,
    ]);
    $connection->table('posts')->where('id', $id)->update(['status' => 'published']);

    $row = $connection->table('posts')
        ->where('status', 'published')
        ->whereIn('id', [$id])
        ->first(['id', 'title', 'status']);

    expect($id)->toBe(1)
        ->and($row->title)->toBe('Hello')
        ->and($connection->table('posts')->count())->toBe(1)
        ->and($connection->table('posts')->where('id', $id)->delete())->toBe(1)
        ->and($connection->table('posts')->exists())->toBeFalse();

    devdb_remove_path($path);
});

it('supports joins, grouped query builder aggregates, locks, and transactions', function () {
    $connection = new DevDbConnection(null, 'devdb', '', ['path' => devdb_test_path('connection_advanced')]);
    $connection->getSchemaBuilder()->create('users', function ($table) {
        $table->increments('id');
        $table->string('name');
        $table->string('role');
    });
    $connection->getSchemaBuilder()->create('posts', function ($table) {
        $table->increments('id');
        $table->integer('user_id');
        $table->string('status');
        $table->integer('views');
    });

    $connection->table('users')->insert([
        ['id' => 1, 'name' => 'Ava', 'role' => 'author'],
        ['id' => 2, 'name' => 'Noah', 'role' => 'editor'],
    ]);
    $connection->table('posts')->insert([
        ['id' => 1, 'user_id' => 1, 'status' => 'published', 'views' => 10],
        ['id' => 2, 'user_id' => 1, 'status' => 'draft', 'views' => 5],
        ['id' => 3, 'user_id' => 2, 'status' => 'published', 'views' => 30],
    ]);

    $joined = $connection->table('posts')
        ->join('users', 'users.id', '=', 'posts.user_id')
        ->where('users.role', 'author')
        ->orderBy('posts.id')
        ->get(['posts.id as post_id', 'users.name as author']);
    $grouped = $connection->table('posts')
        ->groupBy('status')
        ->having('aggregate_count', '>=', 1)
        ->orderBy('status')
        ->get(['status', 'aggregate_count', 'sum_views']);

    expect($joined->pluck('post_id')->all())->toBe([1, 2])
        ->and($connection->table('posts')->lockForUpdate()->where('id', 1)->first()->views)->toBe(10)
        ->and($connection->table('posts')->sum('views'))->toBe(45.0)
        ->and($grouped->firstWhere('status', 'published')->aggregate_count)->toBe(2);

    $connection->beginTransaction();
    $connection->table('posts')->insert(['id' => 4, 'user_id' => 2, 'status' => 'draft', 'views' => 1]);
    expect($connection->table('posts')->count())->toBe(4);
    $connection->rollBack();
    expect($connection->table('posts')->count())->toBe(3);
});

it('adds timestamp convenience and soft deletes when matching columns exist', function () {
    $path = devdb_test_path('connection_timestamps');
    $connection = new DevDbConnection(null, 'devdb', '', ['path' => $path]);
    $schema = $connection->getSchemaBuilder();
    $schema->create('notes', function ($table) {
        $table->increments('id');
        $table->string('body');
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->timestamp('deleted_at')->nullable();
    });

    $id = $connection->table('notes')->insertGetId(['body' => 'Draft']);
    $created = $connection->table('notes')->where('id', $id)->first();
    $connection->table('notes')->where('id', $id)->update(['body' => 'Updated']);
    $connection->table('notes')->where('id', $id)->delete();
    $deleted = $connection->table('notes')->where('id', $id)->first();

    expect($created->created_at)->not->toBeNull()
        ->and($created->updated_at)->not->toBeNull()
        ->and($deleted->body)->toBe('Updated')
        ->and($deleted->deleted_at)->not->toBeNull();

    devdb_remove_path($path);
});

it('handles raw SQL, information schema probes, and compatibility statements', function () {
    $connection = new DevDbConnection(null, 'devdb', '', ['path' => devdb_test_path('connection_raw')]);
    $connection->statement('create table migrations (id integer primary key auto_increment, migration varchar(190))');

    expect($connection->statement('SET NAMES utf8mb4'))->toBeTrue()
        ->and($connection->statement('SET FOREIGN_KEY_CHECKS=0'))->toBeTrue()
        ->and($connection->selectOne(
            'SELECT 1 AS found FROM information_schema.tables WHERE table_schema = ? AND table_name = ? LIMIT 1',
            ['devdb', 'migrations'],
        )->found)->toBe(1)
        ->and($connection->selectOne(
            'SELECT 1 AS found FROM information_schema.tables WHERE table_schema = ? AND table_name = ? LIMIT 1',
            ['devdb', 'missing'],
        ))->toBeNull();
});
