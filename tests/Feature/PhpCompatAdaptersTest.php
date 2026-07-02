<?php

use Pinoox\DevDB\Compat\DevMysqli;
use Pinoox\DevDB\Compat\DevPDO;

it('provides a PDO-like adapter for plain PHP code', function () {
    $pdo = DevPDO::open(devdb_test_path('compat_pdo'));

    expect($pdo->exec('create table users (id integer primary key auto_increment, name varchar(80), email varchar(120))'))->toBe(0)
        ->and($pdo->exec("insert into users (name, email) values ('Ava', 'ava@example.com')"))->toBe(1)
        ->and($pdo->lastInsertId())->toBe('1');

    $statement = $pdo->prepare('insert into users (name, email) values (:name, :email)');
    expect($statement)->not->toBeFalse();
    $statement->execute([
        'name' => 'Noah',
        'email' => 'noah@example.com',
    ]);

    $query = $pdo->prepare('select id, name from users where email like ? order by id');
    $query->execute(['%@example.com']);

    expect($query->rowCount())->toBe(2)
        ->and($query->fetch(DevPDO::FETCH_ASSOC))->toBe(['id' => 1, 'name' => 'Ava'])
        ->and($query->fetchColumn())->toBe(2);

    $objects = $pdo->query('select name from users order by id', DevPDO::FETCH_OBJ)->fetchAll();

    expect($objects)->toHaveCount(2)
        ->and($objects[1]->name)->toBe('Noah');
});

it('provides a mysqli-like adapter for plain PHP code', function () {
    $mysqli = DevMysqli::open(devdb_test_path('compat_mysqli'));

    expect($mysqli->query('create table posts (id integer primary key auto_increment, title varchar(120), status varchar(20))'))->toBeTrue()
        ->and($mysqli->query("insert into posts (title, status) values ('First', 'draft')"))->toBeTrue()
        ->and($mysqli->insert_id)->toBe(1)
        ->and($mysqli->affected_rows)->toBe(1);

    $result = $mysqli->query("select id, title from posts where status = 'draft'");

    expect($result)->not->toBeFalse()
        ->and($result->num_rows)->toBe(1)
        ->and($result->fetch_assoc())->toBe(['id' => 1, 'title' => 'First']);

    $mysqli->begin_transaction();
    $mysqli->query("update posts set title = 'Changed' where id = 1");
    $mysqli->rollback();

    $row = $mysqli->query('select title from posts where id = 1')->fetch_object();

    expect($row->title)->toBe('First')
        ->and($mysqli->query('select * from missing_table'))->toBeFalse()
        ->and($mysqli->errno)->toBe(1)
        ->and($mysqli->error)->toContain('does not exist');
});
