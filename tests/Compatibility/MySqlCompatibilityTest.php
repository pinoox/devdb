<?php

use Pinoox\DevDB\DevDatabase;

it('imports a phpMyAdmin-like dump and supports common readback queries', function () {
    $db = DevDatabase::open(devdb_test_path('compat_phpmyadmin_dump'));

    $db->executeDump(<<<'SQL'
-- phpMyAdmin SQL Dump
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

DROP TABLE IF EXISTS `products`;
CREATE TABLE `products` (
  `id` int NOT NULL AUTO_INCREMENT,
  `sku` varchar(60) NOT NULL,
  `title` varchar(120) NOT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0,
  `status` enum('draft','published') NOT NULL DEFAULT 'draft',
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `products_sku_unique` (`sku`) USING BTREE,
  INDEX `products_status_index` (`status`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 20 DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

INSERT INTO `products` (`sku`, `title`, `price`, `status`, `created_at`) VALUES
('SKU-1', 'First', 120.50, 'published', '2026-07-01 10:00:00'),
('SKU-2', 'Second', 40, 'draft', '2026-07-02 11:00:00');
SQL);

    $rows = $db->select("select sku, case when price >= 100 then 'premium' else 'standard' end as tier from products where status in (select status from products where sku = 'SKU-1') order by id");
    $count = $db->selectOne("select count(*) as total from products where date(created_at) >= '2026-07-01'");

    expect(array_map(fn ($row) => $row->sku, $rows))->toBe(['SKU-1'])
        ->and($rows[0]->tier)->toBe('premium')
        ->and($count->total)->toBe(2);
});

it('round-trips DevDB data through the MySQL dump exporter', function () {
    $source = DevDatabase::open(devdb_test_path('compat_roundtrip_source'));
    $target = DevDatabase::open(devdb_test_path('compat_roundtrip_target'));

    $source->executeDump(<<<'SQL'
CREATE TABLE users (id integer primary key auto_increment, email varchar(120) not null, UNIQUE INDEX users_email_unique (email));
INSERT INTO users (email) VALUES ('ava@example.com'), ('noah@example.com');
SQL);

    $dump = (new \Pinoox\Component\Database\DevDB\DevDbMySqlExporter())->toSql($source->store()->export());
    $target->executeDump($dump);

    expect($target->selectOne('select count(*) as total from users')->total)->toBe(2)
        ->and($target->selectOne("select email from users where id = 2")->email)->toBe('noah@example.com');
});
