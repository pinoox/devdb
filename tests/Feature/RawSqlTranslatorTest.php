<?php

use Pinoox\Component\Database\DevDB\DevDbException;
use Pinoox\DevDB\DevDatabase;

it('translates raw CRUD statements with bindings, ordering, limits, and aggregates', function () {
    $db = DevDatabase::open(devdb_test_path('raw_crud'));
    $db->statement('create table posts (id integer primary key auto_increment, title varchar(120), status varchar(20), views int)');

    expect($db->execute(
        'insert into posts (title, status, views) values (?, ?, ?), (?, ?, ?), (?, ?, ?)',
        ['Intro', 'published', 10, 'Draft', 'draft', 5, 'Review', 'published', 30],
    ))->toBe(3);

    $published = $db->select('select id, title from posts where status = ? order by views desc limit 1', ['published']);
    $aggregate = $db->selectOne('select count(*) as total, sum(views) as views from posts where id in (?, ?, ?)', [1, 2, 3]);

    expect($published[0]->title)->toBe('Review')
        ->and($aggregate->total)->toBe(3)
        ->and($aggregate->views)->toBe(45.0)
        ->and($db->execute('update posts set status = ? where title like ?', ['published', 'Draft%']))->toBe(1)
        ->and($db->execute('delete from posts where views < ?', [10]))->toBe(1)
        ->and($db->selectOne('select count(*) as total from posts')->total)->toBe(2);
});

it('supports W3Schools-style DISTINCT, NOT, defaults, and INSERT VALUES without column lists', function () {
    $db = DevDatabase::open(devdb_test_path('raw_w3schools_basics'));
    $db->statement('create table customers (id integer primary key auto_increment, name varchar(120) not null, city varchar(80) default "Tehran", country varchar(80), email varchar(120), unique index customers_email_unique (email))');

    $db->statement(
        'insert into customers values (?, ?, ?, ?, ?), (?, ?, ?, ?, ?), (?, ?, ?, ?, ?)',
        [1, 'Ava', 'Tehran', 'Iran', 'ava@example.com', 2, 'Noah', 'Berlin', 'Germany', 'noah@example.com', 3, 'Mina', 'Tehran', 'Iran', 'mina@example.com'],
    );
    $db->statement(
        'insert into customers (name, country, email) values (?, ?, ?)',
        ['Sara', 'Iran', 'sara@example.com'],
    );

    $countries = $db->select('SELECT DISTINCT country FROM customers ORDER BY country');
    $notIran = $db->select('select name from customers where not country = ? order by id', ['Iran']);
    $defaulted = $db->selectOne('select city from customers where email = ?', ['sara@example.com']);

    expect(array_map(fn ($row) => $row->country, $countries))->toBe(['Germany', 'Iran'])
        ->and(array_map(fn ($row) => $row->name, $notIran))->toBe(['Noah'])
        ->and($defaulted->city)->toBe('Tehran')
        ->and(fn () => $db->statement('insert into customers (name, email) values (?, ?)', ['Duplicate', 'ava@example.com']))
        ->toThrow(DevDbException::class, 'unique constraint violation');
});

it('supports W3Schools-style INSERT INTO SELECT and EXISTS predicates', function () {
    $db = DevDatabase::open(devdb_test_path('raw_w3schools_insert_select'));
    $db->statement('create table customers (id integer primary key, name varchar(120), country varchar(80))');
    $db->statement('create table customer_archive (id integer primary key auto_increment, name varchar(120), country varchar(80))');
    $db->statement(
        'insert into customers (id, name, country) values (?, ?, ?), (?, ?, ?), (?, ?, ?)',
        [1, 'Ava', 'Iran', 2, 'Noah', 'Germany', 3, 'Mina', 'Iran'],
    );

    expect($db->execute(
        'insert into customer_archive (name, country) select name, country from customers where country = ?',
        ['Iran'],
    ))->toBe(2);

    $exists = $db->select('select name from customers where exists (select id from customer_archive where country = ?) order by id', ['Iran']);
    $notExists = $db->select('select name from customers where not exists (select id from customer_archive where country = ?)', ['France']);
    $missingExists = $db->select('select name from customers where exists (select id from customer_archive where country = ?)', ['France']);

    expect(array_map(fn ($row) => $row->name, $exists))->toBe(['Ava', 'Noah', 'Mina'])
        ->and(array_map(fn ($row) => $row->name, $notExists))->toBe(['Ava', 'Noah', 'Mina'])
        ->and($missingExists)->toBe([]);
});

it('translates joins, grouped rows, aliases, and boolean expressions', function () {
    $db = DevDatabase::open(devdb_test_path('raw_join'));
    $db->statement('create table users (id integer primary key, name varchar(80), role varchar(20))');
    $db->statement('create table posts (id integer primary key, user_id integer, title varchar(80), status varchar(20), views int)');

    $db->statement(
        'insert into users (id, name, role) values (?, ?, ?), (?, ?, ?)',
        [1, 'Ava', 'author', 2, 'Noah', 'editor'],
    );
    $db->statement(
        'insert into posts (id, user_id, title, status, views) values (?, ?, ?, ?, ?), (?, ?, ?, ?, ?), (?, ?, ?, ?, ?)',
        [1, 1, 'Intro', 'published', 10, 2, 1, 'Draft', 'draft', 5, 3, 2, 'Review', 'published', 30],
    );

    $joined = $db->select(
        'select p.id as post_id, u.name as author from posts p join users u on u.id = p.user_id where (p.status = ? or p.views >= ?) order by p.views desc',
        ['draft', 20],
    );
    $grouped = $db->select(
        'select u.role, count(*) as total, sum(p.views) as views from posts p join users u on u.id = p.user_id group by u.role having total >= ? order by views desc',
        [1],
    );

    expect(array_map(fn ($row) => $row->post_id, $joined))->toBe([3, 2])
        ->and($joined[0]->author)->toBe('Noah')
        ->and($grouped[0]->role)->toBe('editor')
        ->and($grouped[1]->role)->toBe('author')
        ->and($grouped[1]->views)->toBe(15.0);
});

it('supports table aliases with and without AS using case-insensitive SQL keywords', function () {
    $db = DevDatabase::open(devdb_test_path('raw_alias_case'));
    $db->statement('CREATE TABLE pages (page_id integer primary key, title varchar(120))');
    $db->statement(
        'INSERT INTO pages (page_id, title) VALUES (?, ?), (?, ?), (?, ?)',
        [1, 'Home', 2, 'About', 3, 'Contact'],
    );

    $withAs = $db->select('SELECT p.page_id FROM pages AS p WHERE p.page_id > ? ORDER BY p.page_id', [1]);
    $withoutAs = $db->select('select p.page_id from pages p where p.page_id > ? order by p.page_id desc', [1]);

    expect(array_map(fn ($row) => $row->page_id, $withAs))->toBe([2, 3])
        ->and(array_map(fn ($row) => $row->page_id, $withoutAs))->toBe([3, 2]);
});

it('translates common SQL functions in projections and predicates', function () {
    $db = DevDatabase::open(devdb_test_path('raw_functions'));
    $db->statement('create table events (id integer primary key auto_increment, email varchar(120), title varchar(80), created_at datetime, amount int)');
    $db->statement(
        'insert into events (email, title, created_at, amount) values (?, ?, ?, ?), (?, ?, ?, ?)',
        ['AVA@EXAMPLE.COM', ' Launch ', '2026-06-29 10:15:00', -9, 'noah@example.com', null, '2026-06-30 10:00:00', 4],
    );

    $row = $db->selectOne(
        "select lower(email) as email, upper(trim(title)) as title, date(created_at) as day, abs(amount) as amount, concat(substr(email, 1, 3), '-', year(created_at)) as token from events where date(created_at) = ? and lower(email) like ?",
        ['2026-06-29', 'ava%'],
    );

    expect($row->email)->toBe('ava@example.com')
        ->and($row->title)->toBe('LAUNCH')
        ->and($row->day)->toBe('2026-06-29')
        ->and($row->amount)->toBe(9.0)
        ->and($row->token)->toBe('AVA-2026');
});

it('accepts MySQL dump style schema syntax', function () {
    $db = DevDatabase::open(devdb_test_path('raw_mysql_dump'));

    $db->statement('SET NAMES utf8mb4');
    $db->statement('SET FOREIGN_KEY_CHECKS = 0');
    $db->statement('DROP TABLE IF EXISTS `discounts`');
    $db->statement(<<<'SQL'
CREATE TABLE `discounts` (
  `discount_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `type` enum('percentage','fixed') CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'percentage',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`discount_id`) USING BTREE,
  UNIQUE INDEX `discounts_code_unique`(`code`) USING BTREE,
  INDEX `discounts_code_is_active_index`(`code`, `is_active`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 6 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_bin ROW_FORMAT = Dynamic
SQL);

    $db->statement('insert into discounts (code, type, is_active) values (?, ?, ?)', ['SUMMER', 'fixed', 1]);
    $row = $db->selectOne('select discount_id, code from discounts');
    $columns = $db->select('describe discounts');
    $indexes = $db->select('show keys from discounts');

    expect($row->discount_id)->toBe(6)
        ->and($columns[2]->Type)->toBe('enum')
        ->and(array_map(fn ($index) => $index->Key_name, $indexes))->toContain('discounts_code_unique', 'discounts_code_is_active_index');
});

it('enforces strict column, unique, and foreign key constraints', function () {
    $db = DevDatabase::open(devdb_test_path('raw_constraints'));
    $db->executeDump(<<<'SQL'
CREATE TABLE users (
  id integer NOT NULL AUTO_INCREMENT,
  email varchar(120) NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE INDEX users_email_unique (email)
);
CREATE TABLE posts (
  id integer NOT NULL AUTO_INCREMENT,
  user_id integer NOT NULL,
  status enum('draft','published') NOT NULL DEFAULT 'draft',
  PRIMARY KEY (id),
  CONSTRAINT posts_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE
);
SQL);

    $db->statement('insert into users (email) values (NULL), (NULL), (?)', ['ava@example.com']);
    $db->statement('insert into posts (user_id, status) values (?, DEFAULT)', [1]);

    expect($db->selectOne('select count(*) as aggregate from users')->aggregate)->toBe(3)
        ->and($db->selectOne('select status from posts')->status)->toBe('draft')
        ->and(fn () => $db->statement('insert into users (email) values (?)', ['ava@example.com']))
        ->toThrow(DevDbException::class, 'unique constraint')
        ->and(fn () => $db->statement('insert into posts (user_id, status) values (?, ?)', [999, 'draft']))
        ->toThrow(DevDbException::class, 'foreign key constraint')
        ->and(fn () => $db->statement('insert into posts (user_id, status) values (?, ?)', [1, 'archived']))
        ->toThrow(DevDbException::class, 'ENUM constraint')
        ->and(fn () => $db->statement('insert into posts (status) values (?)', ['draft']))
        ->toThrow(DevDbException::class, 'NOT NULL constraint');
});

it('supports unions, simple subqueries, views, conditional joins, locks, and more functions', function () {
    $db = DevDatabase::open(devdb_test_path('raw_compat_expanded'));
    $db->executeDump(<<<'SQL'
CREATE TABLE users (id integer primary key auto_increment, name varchar(80), role varchar(20));
CREATE TABLE posts (id integer primary key auto_increment, user_id integer, title varchar(120), status varchar(20), views int, created_at datetime);
INSERT INTO users (name, role) VALUES ('Ava', 'editor'), ('Noah', 'author'), ('Mina', 'author');
INSERT INTO posts (user_id, title, status, views, created_at) VALUES
  (1, 'Launch', 'published', 10, '2026-06-29 10:15:00'),
  (2, 'Draft', 'draft', 4, '2026-06-30 11:00:00'),
  (3, 'Guide', 'published', 7, '2026-07-01 09:00:00');
CREATE VIEW published_posts AS SELECT id, user_id, title, views FROM posts WHERE status = 'published';
LOCK TABLES posts READ;
UNLOCK TABLES;
SQL);

    $union = $db->select("select name from users where role = 'editor' union select title from posts where status = 'draft'");
    $unionAll = $db->select("select role from users where role = 'author' union all select role from users where role = 'author'");
    $subquery = $db->select('select title from posts where user_id in (select id from users where role = ?) order by id', ['author']);
    $scalar = $db->selectOne('select title from posts where views = (select max(views) from posts)');
    $view = $db->select('select title from published_posts order by id');
    $joined = $db->select("select u.name, p.title from users u left join posts p on u.id = p.user_id and p.status = 'published' where u.role = 'author' order by u.id");
    $functions = $db->selectOne("select if(views > 5, 'hot', 'cold') as mood, greatest(views, 20) as top_views, least(views, 5) as low_views, left(title, 2) as left_title, right(title, 2) as right_title, date_format(created_at, '%Y/%m/%d') as day from posts where title = 'Launch'");

    expect(array_map(fn ($row) => $row->name, $union))->toBe(['Ava', 'Draft'])
        ->and(array_map(fn ($row) => $row->role, $unionAll))->toBe(['author', 'author', 'author', 'author'])
        ->and(array_map(fn ($row) => $row->title, $subquery))->toBe(['Draft', 'Guide'])
        ->and($scalar->title)->toBe('Launch')
        ->and(array_map(fn ($row) => $row->title, $view))->toBe(['Launch', 'Guide'])
        ->and(array_map(fn ($row) => $row->title, $joined))->toBe([null, 'Guide'])
        ->and($functions->mood)->toBe('hot')
        ->and($functions->top_views)->toBe(20)
        ->and($functions->low_views)->toBe(5)
        ->and($functions->left_title)->toBe('La')
        ->and($functions->right_title)->toBe('ch')
        ->and($functions->day)->toBe('2026/06/29');
});

it('throws useful errors for unsupported or invalid raw SQL', function () {
    $db = DevDatabase::open(devdb_test_path('raw_errors'));
    $db->statement('create table posts (id integer primary key, title varchar(120))');

    expect(fn () => $db->statement('create trigger posts_ai after insert on posts for each row set @x = 1'))
        ->toThrow(DevDbException::class, 'raw SQL statement "CREATE"')
        ->and(fn () => $db->select('with recursive ids as (select 1) select * from ids'))
        ->toThrow(DevDbException::class, 'raw SELECT syntax');
});
