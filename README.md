# DevDB

DevDB is a development-only database component for PHP projects. It gives you a small local database with a raw SQL translator for common development queries, so you can build and test features without installing MySQL, PostgreSQL, or SQLite.

DevDB uses SQLite when available and automatically falls back to a zero-dependency JSON database when it is not.

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
- [PDO and mysqli-like Adapters](#pdo-and-mysqli-like-adapters)
- [Using DevDB in Pinoox](#using-devdb-in-pinoox)
- [Using DevDB in Laravel](#using-devdb-in-laravel)
- [Storage Format](#storage-format)
- [Compatibility Matrix](#compatibility-matrix)
- [Raw SQL Support](#raw-sql-support)
- [Schema SQL Support](#schema-sql-support)
- [Snapshots and Change Manifests](#snapshots-and-change-manifests)
- [Standalone API](#standalone-api)
- [Laravel-Compatible Connection](#laravel-compatible-connection)
- [CLI Commands](#cli-commands)
- [Troubleshooting](#troubleshooting)
- [Performance Expectations](#performance-expectations)
- [Version Roadmap](#version-roadmap)
- [Limitations](#limitations)
- [Package Structure](#package-structure)
- [Development](#development)
- [License](#license)

## Features

- JSON-backed local database storage.
- SQLite-first local storage with automatic JSON fallback.
- No external database service required.
- File-based schema, data, migrations, and sequence metadata.
- File locking for JSON writes.
- Auto-increment sequences.
- Named snapshots for quick save and restore during development.
- Change manifests for detecting external JSON file changes.
- Common CRUD query support.
- Raw SQL translator for common `SELECT`, `INSERT`, `UPDATE`, `DELETE`, and `TRUNCATE` statements.
- Multi-statement SQL dump execution for common MySQL exports.
- Lightweight `EXPLAIN` output for debugging translated queries.
- Strict development checks for `NOT NULL`, `ENUM`, `UNIQUE`, and simple foreign keys.
- PDO-like and mysqli-like adapters for plain PHP projects.
- SQL functions such as `DATE`, `LOWER`, `COALESCE`, `CONCAT`, `ROUND`, and more.
- A standalone PHP API through `Pinoox\DevDB\DevDatabase`.
- A Laravel-compatible connection class through `Pinoox\Component\Database\Connections\DevDbConnection`.
- Development-only by design.

## Installation

```bash
composer require --dev pinoox/devdb
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

## PDO and mysqli-like Adapters

DevDB cannot replace PHP's built-in `PDO` or `mysqli` extensions transparently. Those extensions talk to real database drivers. For plain PHP projects, DevDB provides lightweight compatibility adapters with familiar method names.

### PDO-like usage

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Pinoox\DevDB\Compat\DevPDO;

$pdo = DevPDO::open(__DIR__ . '/storage/devdb');

$pdo->exec('create table users (id integer primary key auto_increment, name varchar(80))');
$pdo->exec("insert into users (name) values ('Ava')");

$stmt = $pdo->prepare('select * from users where name = :name');
$stmt->execute(['name' => 'Ava']);

$users = $stmt->fetchAll(DevPDO::FETCH_ASSOC);
$id = $pdo->lastInsertId();
```

Supported common methods include `query()`, `exec()`, `prepare()`, `execute()`, `bindValue()`, `fetch()`, `fetchAll()`, `fetchColumn()`, `rowCount()`, `lastInsertId()`, `beginTransaction()`, `commit()`, and `rollBack()`.

### mysqli-like usage

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Pinoox\DevDB\Compat\DevMysqli;

$db = DevMysqli::open(__DIR__ . '/storage/devdb');

$db->query('create table posts (id integer primary key auto_increment, title varchar(120))');
$db->query("insert into posts (title) values ('Hello')");

$result = $db->query('select * from posts');
$post = $result->fetch_assoc();

$id = $db->insert_id;
```

Supported common methods and properties include `query()`, `fetch_assoc()`, `fetch_object()`, `fetch_array()`, `fetch_all()`, `num_rows`, `affected_rows`, `insert_id`, `real_escape_string()`, `begin_transaction()`, `commit()`, and `rollback()`.

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

## Compatibility Matrix

DevDB aims to cover the SQL and query behavior developers commonly hit while building local apps, tests, examples, and demos. It is intentionally conservative: unsupported features fail with a clear `DevDbException` instead of silently pretending to be a full database server.

| Area | Status | Notes |
| --- | --- | --- |
| CRUD query builder | Supported | `insert`, `update`, `delete`, `first`, `get`, `count`, `exists`, pagination-style limits |
| Raw `SELECT` | Supported | aliases, `WHERE`, `ORDER BY`, `GROUP BY`, `HAVING`, `LIMIT`, `OFFSET`, `DISTINCT` |
| Joins | Partial | inner and left joins with common `ON` conditions, including grouped `AND`/`OR` predicates |
| Aggregates | Supported | `COUNT`, `SUM`, `AVG`, `MIN`, `MAX` |
| SQL functions | Partial | common scalar/date/string/math functions used in development queries, plus helpers such as `IF`, `GREATEST`, `LEAST`, `LEFT`, `RIGHT`, and `DATE_FORMAT` |
| Schema SQL | Partial | common `CREATE`, `DROP`, `ALTER`, `SHOW`, `DESCRIBE`, and index statements |
| MySQL dump imports | Partial | common dump syntax, comments, `SET`, `AUTO_INCREMENT`, table options, and multi-statement execution |
| Constraints | Partial | strict checks for `NOT NULL`, `ENUM`, `UNIQUE`, primary keys, and simple foreign keys |
| Transactions | Development-safe | snapshot-backed rollback, not isolation-level database transactions |
| Locks | Compatibility no-op | `LOCK TABLES` and `UNLOCK TABLES` are accepted but do not provide real locking |
| Advanced SQL | Partial | `UNION`, `UNION ALL`, simple subqueries, scalar subqueries, and simple views are supported |

## Raw SQL Support

DevDB translates common raw SQL statements into JSON operations.

Supported data statements:

- `SELECT`
- `SELECT DISTINCT`
- `SELECT ... UNION SELECT ...`
- `SELECT ... UNION ALL SELECT ...`
- `INSERT`
- `INSERT INTO ... VALUES` with or without an explicit column list
- `INSERT INTO ... SELECT`
- `UPDATE`
- `DELETE`
- `TRUNCATE`
- `EXPLAIN SELECT ...`

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
- simple subqueries in `IN (...)`
- scalar subqueries in comparisons
- simple views created with `CREATE VIEW ... AS SELECT ...`

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
- scalar subquery comparisons

Supported development helpers:

- `executeDump()` for multi-statement SQL imports
- `explain()` for inspecting how DevDB understands a query
- strict constraint validation, enabled by default
- parenthesized boolean groups
- compatibility no-op handling for `LOCK TABLES` and `UNLOCK TABLES`

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
- SQL comments in dump files
- multiple semicolon-separated statements through `executeDump()`
- backtick-quoted identifiers
- `AUTO_INCREMENT`
- table options after `CREATE TABLE`, such as `ENGINE`, `AUTO_INCREMENT`, `CHARACTER SET`, `COLLATE`, and `ROW_FORMAT`
- `PRIMARY KEY (...) USING BTREE`
- `UNIQUE INDEX ... USING BTREE`
- `INDEX ... USING BTREE`
- `FOREIGN KEY ... REFERENCES ... ON DELETE ... ON UPDATE ...`
- `ENUM(...)`
- `CREATE VIEW ... AS SELECT ...`
- `CREATE OR REPLACE VIEW ... AS SELECT ...`
- `DROP VIEW`
- `DROP VIEW IF EXISTS`
- `LOCK TABLES ...`
- `UNLOCK TABLES`
- `CREATE DATABASE`, `DROP DATABASE`, and `USE` as local compatibility no-op statements
- `SHOW DATABASES`

In strict mode, DevDB validates common development constraints while inserting or updating rows:

- `NOT NULL`
- `ENUM(...)`
- primary keys
- unique indexes
- simple foreign keys where the referenced table exists

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

### `executeDump()`

```php
$db->executeDump(file_get_contents(__DIR__ . '/database/schema.sql'));
```

`executeDump()` splits and executes multi-statement SQL dumps. It ignores common SQL comments and accepts compatibility statements such as `SET NAMES utf8mb4`, `SET FOREIGN_KEY_CHECKS = 0`, `CREATE DATABASE`, `DROP DATABASE`, and `USE` as local no-op operations.

### `explain()`

```php
$plan = $db->explain('select p.id from posts as p where p.status = "published"');
```

`explain()` returns a small debug plan with the parsed table, alias, joins, filters, ordering, and limits. It is useful when a raw query does not behave the way you expected.

### `strict()`

```php
$db->strict(false)->executeDump($legacyDump);
```

Strict mode is enabled by default. It validates `NOT NULL`, `ENUM`, `UNIQUE`, primary key, and simple foreign key constraints. Disable it only for loose development imports where you prefer loading imperfect fixture data over failing fast.

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

The package includes Symfony Console command classes that host applications can register. In Pinoox or Pinx these commands may be exposed directly by the host CLI. In another framework, register the command classes in your console kernel or command registry.

```text
Pinoox\Terminal\DevDB\DevDbStatusCommand
Pinoox\Terminal\DevDB\DevDbInspectCommand
Pinoox\Terminal\DevDB\DevDbExportCommand
Pinoox\Terminal\DevDB\DevDbExportMySqlCommand
Pinoox\Terminal\DevDB\DevDbImportMySqlCommand
Pinoox\Terminal\DevDB\DevDbSyncMySqlCommand
Pinoox\Terminal\DevDB\DevDbClearCommand
Pinoox\Terminal\DevDB\DevDbDoctorCommand
Pinoox\Terminal\DevDB\DevDbRepairCommand
Pinoox\Terminal\DevDB\DevDbSnapshotCommand
Pinoox\Terminal\DevDB\DevDbSeedCommand
```

Available command names and what they do:

| Command | Purpose |
| --- | --- |
| `devdb:status` | Show the active DevDB engine, storage path, tables, row counts, and migration count. |
| `devdb:inspect <table>` | Inspect one table, including columns, indexes, primary key, row count, and sample rows. |
| `devdb:export [file]` | Export the full DevDB payload as JSON. |
| `devdb:export:mysql [file]` | Export DevDB as a MySQL-compatible SQL dump for phpMyAdmin or MySQL imports. |
| `devdb:import:mysql <file>` | Import a MySQL/phpMyAdmin SQL dump into DevDB. |
| `devdb:sync:mysql` | Sync DevDB directly into a local MySQL database through `pdo_mysql`. |
| `devdb:doctor` | Check DevDB storage health and report common issues. |
| `devdb:repair` | Repair common metadata issues such as stale sequences. |
| `devdb:snapshot [action] [name]` | Create, list, restore, or delete snapshots. |
| `devdb:clear` | Clear DevDB storage. Requires confirmation unless `--force` is used. |
| `devdb:seed [package]` | Run app seeders against DevDB in a Pinoox host application. |

### Check Status

Use `devdb:status` to see where DevDB stores data, which engine is active, and which tables exist.

```bash
devdb:status
```

JSON output is useful for tools and automation:

```bash
devdb:status --json
```

### Inspect a Table

Use `devdb:inspect` when you want to quickly check a table structure and a few rows:

```bash
devdb:inspect users
```

Limit the number of previewed rows:

```bash
devdb:inspect users --limit=50
```

Return JSON instead of a console table:

```bash
devdb:inspect users --json
```

### Export as JSON

Use `devdb:export` to create a portable DevDB backup or debug artifact:

```bash
devdb:export storage/devdb/export.json
```

If no file is provided, the JSON is printed to the console:

```bash
devdb:export
```

### Export for phpMyAdmin or MySQL

Use `devdb:export:mysql` when you want to inspect DevDB data in phpMyAdmin. The command generates a MySQL-compatible SQL file:

```bash
devdb:export:mysql storage/devdb/devdb.sql
```

Then import `storage/devdb/devdb.sql` into phpMyAdmin.

By default the dump includes `DROP TABLE IF EXISTS` statements so repeated imports are easier during development. Disable that with:

```bash
devdb:export:mysql storage/devdb/devdb.sql --no-drop
```

Export only schema or only data:

```bash
devdb:export:mysql storage/devdb/schema.sql --schema-only
devdb:export:mysql storage/devdb/data.sql --data-only
```

Export specific tables:

```bash
devdb:export:mysql storage/devdb/users.sql --tables=users,profiles
```

### Import from MySQL or phpMyAdmin

Use `devdb:import:mysql` when you have a MySQL dump and want to load it into DevDB:

```bash
devdb:import:mysql storage/devdb/devdb.sql
```

For loose fixture imports where you do not want strict constraints to block loading:

```bash
devdb:import:mysql storage/devdb/devdb.sql --loose
```

### Sync Directly to MySQL

Use `devdb:sync:mysql` when you have a local MySQL or MariaDB server and want DevDB copied into it automatically:

```bash
devdb:sync:mysql --database=app_dev --username=root --password=
```

You can also pass a full PDO DSN:

```bash
devdb:sync:mysql --dsn="mysql:host=127.0.0.1;port=3306;dbname=app_dev;charset=utf8mb4" --username=root --password=
```

Available MySQL options:

| Option | Description |
| --- | --- |
| `--dsn` | Full PDO MySQL DSN. Overrides `--host`, `--port`, and `--database`. |
| `--host` | MySQL host. Defaults to `127.0.0.1` or `DEVDB_MYSQL_HOST`. |
| `--port` | MySQL port. Defaults to `3306` or `DEVDB_MYSQL_PORT`. |
| `--database` | Target MySQL database. Required unless `--dsn` is provided. |
| `--username` | MySQL username. Defaults to `root` or `DEVDB_MYSQL_USERNAME`. |
| `--password` | MySQL password. Defaults to `DEVDB_MYSQL_PASSWORD`. |
| `--no-drop` | Do not drop existing tables before syncing. |
| `--schema-only` | Sync schema without row data. |
| `--data-only` | Sync row data without schema. |
| `--tables` | Comma-separated table list to sync. |
| `--dry-run` | Show what would be synced without connecting to MySQL. |

Direct sync requires the `pdo_mysql` PHP extension. If it is not available, use `devdb:export:mysql` and import the SQL file manually through phpMyAdmin.

Preview a sync:

```bash
devdb:sync:mysql --database=app_dev --dry-run
```

### Check and Repair DevDB

Use `devdb:doctor` to inspect storage health:

```bash
devdb:doctor
devdb:doctor --json
```

Use `devdb:repair` to recreate missing data files, refresh metadata, update stale sequences, and write a fresh manifest:

```bash
devdb:repair
devdb:repair --json
```

### Snapshots

Snapshots are useful before running destructive experiments or imports:

```bash
devdb:snapshot create before-import
devdb:snapshot list
devdb:snapshot restore before-import
devdb:snapshot delete before-import
```

### Clear DevDB

Use `devdb:clear` to delete the local DevDB data and metadata:

```bash
devdb:clear
```

Skip the confirmation prompt in scripts:

```bash
devdb:clear --force
```

### Run Seeders

In a Pinoox host application, `devdb:seed` forces the database connection to DevDB and runs app seeders:

```bash
devdb:seed com_example_blog
```

Run only one seeder class:

```bash
devdb:seed com_example_blog --class="App\\Seeders\\PostSeeder"
```

Continue when a seeder fails:

```bash
devdb:seed com_example_blog --force
```

### Environment Variables

The commands read the normal DevDB environment values:

```dotenv
DEVDB_ENGINE=auto
DEVDB_PATH=storage/devdb
DEVDB_SQLITE_DATABASE=storage/devdb/devdb.sqlite
```

The MySQL sync command can also read:

```dotenv
DEVDB_MYSQL_HOST=127.0.0.1
DEVDB_MYSQL_PORT=3306
DEVDB_MYSQL_DATABASE=app_dev
DEVDB_MYSQL_USERNAME=root
DEVDB_MYSQL_PASSWORD=
```

Command registration depends on the host application or framework.

## Troubleshooting

### SQLite is not installed

DevDB uses SQLite when `pdo_sqlite` is available. If it is not available, DevDB automatically uses the JSON engine. No extra setup is required.

### MySQL sync fails

Direct sync requires `pdo_mysql` and a reachable MySQL or MariaDB database. If `pdo_mysql` is missing, run:

```bash
devdb:export:mysql storage/devdb/devdb.sql
```

Then import the file manually through phpMyAdmin.

### A query is not supported

DevDB throws a clear exception for unsupported SQL. Try simplifying the query, using the SQLite engine, or syncing to MySQL for exact server behavior.

### IDs look wrong after editing JSON files

Run:

```bash
devdb:doctor
devdb:repair
```

This refreshes sequence metadata from table data.

## Performance Expectations

DevDB is optimized for local development, small demos, tests, and package examples.

- SQLite engine is preferred when available.
- JSON fallback is zero-dependency and best for small to medium local datasets.
- Large tables, high concurrency, and production workloads should use SQLite/MySQL/PostgreSQL directly.
- JSON writes use file locking, but they are not a substitute for real database locks.
- Use `devdb:sync:mysql` or `devdb:export:mysql` when you need phpMyAdmin, MySQL tooling, or exact SQL-server behavior.

## Version Roadmap

Suggested release path:

| Version | Focus |
| --- | --- |
| `v0.1.0` | Initial standalone DevDB preview. |
| `v0.2.0` | SQL compatibility, MySQL export/sync, and plain PHP adapters. |
| `v0.3.0` | Engine abstraction, stronger SQLite integration, and import workflows. |
| `v0.4.0` | Broader SQL compatibility and performance improvements. |
| `v1.0.0` | Stable local-development API. |

## Limitations

DevDB is intentionally not a full SQL server.

Unsupported or limited features include:

- recursive CTE queries
- window functions
- correlated subqueries that reference the outer row
- full SQL optimizer behavior
- every vendor-specific SQL function
- full foreign key cascade behavior
- stored procedures
- triggers
- isolation-level transaction behavior
- real database locks
- production workloads

DevDB now supports common `UNION`, `UNION ALL`, simple subqueries, simple views, compatibility locks, and many MySQL-style functions. When a query is still not supported, DevDB throws a clear exception. For exact SQL-server behavior, use SQLite, MySQL, PostgreSQL, or another real database engine.

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
    DevDbMySqlExporter.php
  Terminal/DevDB/
    Concerns/UsesDevDbStore.php
    DevDbClearCommand.php
    DevDbExportCommand.php
    DevDbExportMySqlCommand.php
    DevDbInspectCommand.php
    DevDbSeedCommand.php
    DevDbSyncMySqlCommand.php
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
