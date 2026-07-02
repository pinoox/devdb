<?php

use Pinoox\Component\Database\DevDB\DevDbMySqlExporter;
use Pinoox\DevDB\DevDatabase;

it('exports DevDB schema and rows as a MySQL dump', function () {
    $db = DevDatabase::open(devdb_test_path('mysql_export'));
    $db->executeDump(<<<'SQL'
CREATE TABLE users (
  id integer NOT NULL AUTO_INCREMENT,
  email varchar(120) NOT NULL,
  status enum('active','disabled') NOT NULL DEFAULT 'active',
  PRIMARY KEY (id),
  UNIQUE INDEX users_email_unique (email)
);
INSERT INTO users (email, status) VALUES ('ava@example.com', 'active'), ('noah@example.com', 'disabled');
SQL);

    $sql = (new DevDbMySqlExporter())->toSql($db->store()->export());

    expect($sql)->toContain('SET NAMES utf8mb4;')
        ->and($sql)->toContain('DROP TABLE IF EXISTS `users`;')
        ->and($sql)->toContain('CREATE TABLE `users`')
        ->and($sql)->toContain('`id` INT NOT NULL AUTO_INCREMENT')
        ->and($sql)->toContain("`status` ENUM('active', 'disabled') NOT NULL DEFAULT 'active'")
        ->and($sql)->toContain('UNIQUE KEY `users_email_unique` (`email`)')
        ->and($sql)->toContain("INSERT INTO `users` (`email`, `status`, `id`) VALUES")
        ->and($sql)->toContain("('ava@example.com', 'active', 1)")
        ->and($sql)->toContain('SET FOREIGN_KEY_CHECKS=1;');
});

it('can export without drop statements', function () {
    $db = DevDatabase::open(devdb_test_path('mysql_export_no_drop'));
    $db->statement('create table notes (id integer primary key auto_increment, body varchar(120))');

    $sql = (new DevDbMySqlExporter())->toSql($db->store()->export(), dropTables: false);

    expect($sql)->not->toContain('DROP TABLE')
        ->and($sql)->toContain('CREATE TABLE `notes`');
});
