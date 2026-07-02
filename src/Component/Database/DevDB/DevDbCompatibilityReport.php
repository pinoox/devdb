<?php

namespace Pinoox\Component\Database\DevDB;

final class DevDbCompatibilityReport
{
    public function mysql(): array
    {
        $features = [
            ['area' => 'CRUD', 'feature' => 'INSERT/SELECT/UPDATE/DELETE', 'status' => 'supported'],
            ['area' => 'Queries', 'feature' => 'WHERE, IN, NULL, LIKE, BETWEEN, ORDER, LIMIT, OFFSET', 'status' => 'supported'],
            ['area' => 'Queries', 'feature' => 'JOIN, GROUP BY, HAVING, UNION, scalar subqueries', 'status' => 'partial'],
            ['area' => 'Expressions', 'feature' => 'CASE, common date/string functions, arithmetic expressions', 'status' => 'partial'],
            ['area' => 'Schema', 'feature' => 'CREATE/DROP/ALTER TABLE, indexes, defaults', 'status' => 'supported'],
            ['area' => 'Schema', 'feature' => 'MODIFY COLUMN, CHANGE COLUMN, rename/drop column', 'status' => 'supported'],
            ['area' => 'Constraints', 'feature' => 'Primary, unique, enum, not-null, foreign keys', 'status' => 'partial'],
            ['area' => 'Transactions', 'feature' => 'Transactions and named savepoints', 'status' => 'development-snapshot'],
            ['area' => 'Routines', 'feature' => 'Triggers, procedures, functions', 'status' => 'metadata-only'],
            ['area' => 'Import/Export', 'feature' => 'phpMyAdmin-style dumps and MySQL sync/export', 'status' => 'partial'],
            ['area' => 'Unsupported', 'feature' => 'Stored routine execution, real locks, isolation levels', 'status' => 'unsupported'],
        ];

        return [
            'target' => 'mysql',
            'summary' => [
                'supported' => count(array_filter($features, fn (array $feature): bool => $feature['status'] === 'supported')),
                'partial' => count(array_filter($features, fn (array $feature): bool => $feature['status'] === 'partial')),
                'metadata_only' => count(array_filter($features, fn (array $feature): bool => $feature['status'] === 'metadata-only')),
                'unsupported' => count(array_filter($features, fn (array $feature): bool => $feature['status'] === 'unsupported')),
            ],
            'features' => $features,
            'recommendation' => 'Use DevDB for local development and migration prototyping. Use MySQL/SQLite/PostgreSQL for production and high-fidelity database behavior.',
        ];
    }
}
