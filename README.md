# DevDB

DevDB is a development-only database component for PHP projects. It gives you a small local database backed by JSON files, with a raw SQL translator for common development queries, so you can build and test features without installing MySQL, PostgreSQL, or SQLite.

DevDB is designed for local development, prototypes, tests, demos, package examples, and single-user tooling. It is not a production database.

## Why DevDB?

Use DevDB when you want a project to run immediately on a developer machine, CI job, demo environment, or package example without asking people to install and configure a database server first.

DevDB is useful for:

- local development without MySQL, PostgreSQL, or SQLite setup
- package examples and documentation demos
- quick prototypes and throwaway experiments
- tests that need simple persistent data
- single-user developer tools
- importing simple MySQL-style schema dumps for local inspection
- trying application flows before choosing the final database

DevDB is not meant for:

- production applications
- multi-user or high-concurrency workloads
- large datasets
- strict relational integrity
- full SQL compatibility
- performance benchmarking
- replacing SQLite, MySQL, PostgreSQL, or SQL Server in deployed systems

The short version: DevDB removes setup friction during development. Use it to start fast, test ideas, and keep examples portable. Switch to SQLite or a real database when correctness, scale, concurrency, or production reliability matters.

## Table of Contents

- [Why DevDB?](#why-devdb)
- [Features](#features)
- [Installation](#installation)
- [Normal PHP Usage](#normal-php-usage)
- [Using DevDB in Pinoox](#using-devdb-in-pinoox)
- [Using DevDB in Laravel](#using-devdb-in-laravel)
- [Storage Format](#storage-format)
- [Raw SQL Support](#raw-sql-support)
- [Schema SQL Support](#schema-sql-support)
- [Snapshots and Change Manifests](#snapshots-and-change-manifests)
- [Standalone API](#standalone-api)
- [Laravel-Compatible Connection](#laravel-compatible-connection)
- [CLI Commands](#cli-commands)
- [Limitations](#limitations)
- [Package Structure](#package-structure)
- [Development](#development)
- [License](#license)

## Features

- JSON-backed local database storage.
- No external database service required.
- File-based schema, data, migrations, and sequence metadata.
- File locking for JSON writes.
- Auto-increment sequences.
- Named snapshots for quick save and restore during development.
- Change manifests for detecting external JSON file changes.
- Common CRUD query support.
- Raw SQL translator for common `SELECT`, `INSERT`, `UPDATE`, `DELETE`, and `TRUNCATE` statements.
- SQL functions such as `DATE`, `LOWER`, `COALESCE`, `CONCAT`, `ROUND`, and more.
- A standalone PHP API through `Pinoox\DevDB\DevDatabase`.
- A Laravel-compatible connection class through `Pinoox\Component\Database\Connections\DevDbConnection`.
- Development-only by design.

## Installation

```bash
composer require --dev pinoox/devdb
```

For monorepo or local package development, use a Composer path repository:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../devdb",
      "options": {
        "symlink": true
      }
    }
  ],
  "require-dev": {
    "pinoox/devdb": "*"
  }
}
```

Then update the package:

```bash
composer update pinoox/devdb
```

## Normal PHP Usage

Use `Pinoox\DevDB\DevDatabase` when you want DevDB as a standalone component outside any framework.

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Pinoox\DevDB\DevDatabase;

$db = DevDatabase::open(__DIR__ . '/storage/devdb');

$db->createTable('notes', [
    'id' => ['type' => 'integer', 'primary' => true, 'auto_increment' => true],
    'body' => 'string',
    'created_at' => 'string',
]);

$db->statement(
    'insert into notes (body, created_at) values (?, ?), (?, ?)',
    [
        'First note',
        '2026-06-29 10:00:00',
        'Second note',
        '2026-06-30 10:00:00',
    ],
);

$notes = $db->select(
    'select id, upper(body) as title, date(created_at) as day from notes where date(created_at) >= ? order by id desc',
    ['2026-06-29'],
);

foreach ($notes as $note) {
    echo $note->id . ': ' . $note->title . ' @ ' . $note->day . PHP_EOL;
}
```

You can also run write statements and inspect the result:

```php
$affected = $db->execute('delete from notes where id = ?', [1]);

$latest = $db->selectOne(
    'select * from notes order by id desc limit 1',
);
```

## Using DevDB in Pinoox

Install DevDB as a development dependency in your app or platform:

```bash
composer require --dev pinoox/devdb
```

Recommended local environment:

```dotenv
APP_ENV=development
DB_CONNECTION=devdb
```

With Pinoox integration enabled, normal app code can continue to use models, migrations, and database facades:

```php
Post::create([
    'title' => 'Hello DevDB',
    'status' => 'published',
]);

$posts = Post::where('status', 'published')
    ->orderBy('id', 'desc')
    ->get();

$rows = DB::app()->select(
    'select id, title from posts where date(created_at) = ?',
    ['2026-06-29'],
);
```

DevDB is intended for local development only. Production environments should use a real database connection.

## Using DevDB in Laravel

DevDB includes a Laravel-compatible connection class. You can register it manually through Laravel's database extension mechanism.

Example service provider:

```php
<?php

namespace App\Providers;

use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;
use Pinoox\Component\Database\Connections\DevDbConnection;

class DevDbServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Connection::resolverFor('devdb', function ($connection, $database, $prefix, $config) {
            return new DevDbConnection(null, $database ?: 'devdb', $prefix, $config);
        });
    }
}
```

Example `config/database.php` connection:

```php
'connections' => [
    'devdb' => [
        'driver' => 'devdb',
        'database' => 'devdb',
        'path' => storage_path('devdb'),
        'prefix' => '',
    ],
],
```

Example `.env`:

```dotenv
APP_ENV=local
DB_CONNECTION=devdb
```

Then you can use Laravel's database API:

```php
DB::table('posts')->insert([
    'title' => 'Hello from DevDB',
    'status' => 'draft',
]);

$posts = DB::table('posts')
    ->where('status', 'draft')
    ->orderBy('id')
    ->get();

$raw = DB::select(
    'select id, upper(title) as title from posts where status = ?',
    ['draft'],
);
```

Laravel migrations may require project-specific integration because DevDB stores schema metadata instead of executing all SQL DDL in JSON mode.

## Storage Format

DevDB stores data under the configured path.

Default standalone example:

```text
storage/devdb/
```

JSON engine files:

```text
storage/devdb/schema.json
storage/devdb/data/{table}.json
storage/devdb/meta/migrations.json
storage/devdb/meta/sequences.json
storage/devdb/meta/indexes.json
```

`schema.json` describes tables and columns. Each table has a JSON data file under `data/`. Auto-increment values are stored in `meta/sequences.json`.

## Raw SQL Support

DevDB translates common raw SQL statements into JSON operations.

Supported data statements:

- `SELECT`
- `SELECT DISTINCT`
- `INSERT`
- `INSERT INTO ... VALUES` with or without an explicit column list
- `INSERT INTO ... SELECT`
- `UPDATE`
- `DELETE`
- `TRUNCATE`

Supported `SELECT` features:

- table aliases
- column aliases
- `INNER JOIN`
- `LEFT JOIN`
- `WHERE`
- `GROUP BY`
- `HAVING`
- `ORDER BY`
- `LIMIT`
- `OFFSET`

Supported operators and predicates:

- `=`
- `==`
- `!=`
- `<>`
- `>`
- `>=`
- `<`
- `<=`
- `LIKE`
- `NOT LIKE`
- `IN`
- `NOT IN`
- `BETWEEN`
- `NOT BETWEEN`
- `IS NULL`
- `IS NOT NULL`
- `AND`
- `OR`
- `NOT`
- `EXISTS`
- `NOT EXISTS`
- parenthesized boolean groups

Supported aggregate functions:

- `COUNT`
- `SUM`
- `AVG`
- `MIN`
- `MAX`

Supported scalar functions:

- `DATE`
- `TIME`
- `DATETIME`
- `TIMESTAMP`
- `YEAR`
- `MONTH`
- `DAY`
- `DAYOFMONTH`
- `HOUR`
- `MINUTE`
- `SECOND`
- `LOWER`
- `LCASE`
- `UPPER`
- `UCASE`
- `TRIM`
- `LTRIM`
- `RTRIM`
- `LENGTH`
- `CHAR_LENGTH`
- `CHARACTER_LENGTH`
- `COALESCE`
- `IFNULL`
- `NULLIF`
- `CONCAT`
- `SUBSTR`
- `SUBSTRING`
- `REPLACE`
- `ABS`
- `ROUND`
- `FLOOR`
- `CEIL`
- `CEILING`
- `CURRENT_DATE`
- `CURRENT_TIME`
- `CURRENT_TIMESTAMP`
- `NOW`

Example:

```php
$rows = $db->select(
    'select date(created_at) as day, count(*) as total, sum(abs(amount)) as volume
     from events
     where lower(email) like ?
     group by date(created_at)
     having total >= ?
     order by day desc',
    ['%@example.com', 1],
);
```

## Schema SQL Support

DevDB can translate common schema and introspection statements into metadata operations.

DevDB also accepts common MySQL dump compatibility syntax such as:

- `SET NAMES utf8mb4`
- `SET FOREIGN_KEY_CHECKS = 0`
- backtick-quoted identifiers
- `AUTO_INCREMENT`
- table options after `CREATE TABLE`, such as `ENGINE`, `AUTO_INCREMENT`, `CHARACTER SET`, `COLLATE`, and `ROW_FORMAT`
- `PRIMARY KEY (...) USING BTREE`
- `UNIQUE INDEX ... USING BTREE`
- `INDEX ... USING BTREE`
- `FOREIGN KEY ... REFERENCES ... ON DELETE ... ON UPDATE ...`
- `ENUM(...)`
- `CREATE DATABASE`, `DROP DATABASE`, and `USE` as local compatibility no-op statements
- `SHOW DATABASES`

Supported schema statements:

- `SET ...`
- `CREATE TABLE`
- `CREATE TABLE IF NOT EXISTS`
- `DROP TABLE`
- `DROP TABLE IF EXISTS`
- `ALTER TABLE ... ADD COLUMN`
- `ALTER TABLE ... DROP COLUMN`
- `ALTER TABLE ... RENAME COLUMN ... TO ...`
- `ALTER TABLE ... RENAME TO ...`
- `ALTER TABLE ... ADD PRIMARY KEY (...)`
- `ALTER TABLE ... ADD UNIQUE (...)`
- `ALTER TABLE ... ADD INDEX (...)`
- `CREATE INDEX ... ON ... (...)`
- `CREATE UNIQUE INDEX ... ON ... (...)`
- `DROP INDEX ...`
- `DROP INDEX ... ON ...`

Supported introspection statements:

- `SHOW TABLES`
- `SHOW TABLES LIKE 'pattern'`
- `DESCRIBE table`
- `DESC table`
- `SHOW COLUMNS FROM table`
- `SHOW INDEX FROM table`
- `SHOW INDEXES FROM table`
- `SHOW KEYS FROM table`

Example:

```php
$db->statement(
    'create table users (
        id integer primary key auto_increment,
        name varchar(120) not null,
        email varchar(190),
        created_at datetime,
        unique key users_email_unique (email)
    )'
);

$db->statement('alter table users add column status varchar(20) default "active"');
$db->statement('create index users_status_index on users (status)');

$tables = $db->select('show tables');
$columns = $db->select('describe users');
$indexes = $db->select('show index from users');
```

## Snapshots and Change Manifests

DevDB can create named snapshots of the full schema, data, and metadata export.

```php
$snapshot = $db->snapshot('before-import');

$db->statement('delete from users where id > ?', [10]);

$db->restoreSnapshot('before-import');
```

List and delete snapshots:

```php
$snapshots = $db->snapshots();
$db->deleteSnapshot('before-import');
```

DevDB can also write a manifest of tracked files and later detect whether any tracked JSON data changed.

```php
$db->writeManifest();

// Later...
if ($db->hasChangesSinceManifest()) {
    // Reload, inspect, or refresh tooling.
}
```

Tracked files include `schema.json`, table data files, and core metadata files. Snapshot files and the manifest file itself are ignored to avoid false positives.

## Standalone API

### `DevDatabase::open()`

```php
$db = DevDatabase::open(__DIR__ . '/storage/devdb');
```

### `createTable()`

```php
$db->createTable('users', [
    'id' => ['type' => 'integer', 'primary' => true, 'auto_increment' => true],
    'name' => 'string',
    'email' => ['type' => 'string', 'nullable' => false],
]);
```

### `select()`

```php
$rows = $db->select('select * from users where email like ?', ['%@example.com']);
```

### `selectOne()`

```php
$user = $db->selectOne('select * from users where id = ?', [1]);
```

### `statement()`

```php
$db->statement('insert into users (name, email) values (?, ?)', ['Ava', 'ava@example.com']);
```

### `execute()`

```php
$affected = $db->execute('update users set name = ? where id = ?', ['Ava Dev', 1]);
```

### `store()`

```php
$store = $db->store();
$status = $store->status();
```

### `clear()`

```php
$db->clear();
```

### `snapshot()`

```php
$snapshot = $db->snapshot('checkpoint');
```

### `snapshots()`

```php
$snapshots = $db->snapshots();
```

### `restoreSnapshot()`

```php
$db->restoreSnapshot('checkpoint');
```

### `deleteSnapshot()`

```php
$db->deleteSnapshot('checkpoint');
```

### `writeManifest()`

```php
$manifest = $db->writeManifest();
```

### `hasChangesSinceManifest()`

```php
$changed = $db->hasChangesSinceManifest();
```

## Laravel-Compatible Connection

The lower-level connection class is:

```php
Pinoox\Component\Database\Connections\DevDbConnection
```

Example:

```php
use Pinoox\Component\Database\Connections\DevDbConnection;

$connection = new DevDbConnection(null, 'devdb', '', [
    'path' => __DIR__ . '/storage/devdb',
]);

$connection->getSchemaBuilder()->create('posts', function ($table) {
    $table->increments('id');
    $table->string('title');
});

$connection->insert(
    'insert into posts (title) values (?)',
    ['Hello'],
);

$posts = $connection->select('select * from posts');
```

## CLI Commands

The package includes command classes that host applications can register:

```text
Pinoox\Terminal\DevDB\DevDbStatusCommand
Pinoox\Terminal\DevDB\DevDbInspectCommand
Pinoox\Terminal\DevDB\DevDbExportCommand
Pinoox\Terminal\DevDB\DevDbClearCommand
Pinoox\Terminal\DevDB\DevDbSeedCommand
```

Available command names:

```bash
devdb:status
devdb:inspect
devdb:export
devdb:clear
devdb:seed
```

Command registration depends on the host application or framework.

## Limitations

DevDB is intentionally not a full SQL server.

Unsupported or limited features include:

- `UNION`
- subqueries
- recursive queries
- complex multi-condition join clauses
- vendor-specific SQL functions
- stored procedures
- triggers
- views
- isolation-level transaction behavior
- database locks
- production workloads

When a query is not supported, DevDB throws a clear exception. For complex SQL compatibility, use SQLite, MySQL, PostgreSQL, or another real database engine.

## Package Structure

```text
src/
  DevDatabase.php
  Component/Database/Connections/
    DevDbConnection.php
  Component/Database/DevDB/
    DevDbException.php
    DevDbQueryBuilder.php
    DevDbRuntime.php
    DevDbSchemaBuilder.php
    DevDbSqlTranslator.php
    DevDbStore.php
  Terminal/DevDB/
    Concerns/UsesDevDbStore.php
    DevDbClearCommand.php
    DevDbExportCommand.php
    DevDbInspectCommand.php
    DevDbSeedCommand.php
    DevDbStatusCommand.php
```

## Development

Validate the package:

```bash
composer validate --strict
```

Run the package test suite:

```bash
composer test
```

Run only one layer:

```bash
composer test:unit
composer test:feature
```

Lint source files:

```bash
php -l src/DevDatabase.php
```

Run the host project's DevDB test suite when integrating the package with a framework.

## License

MIT
