<?php

use Pinoox\Component\Database\DevDB\Engines\DevDbEngineFactory;
use Pinoox\Component\Database\DevDB\Engines\JsonDevDbEngine;
use Pinoox\Component\Database\DevDB\Engines\SqliteDevDbEngine;

it('selects json engine explicitly', function () {
    $engine = (new DevDbEngineFactory())->make('json', devdb_test_path('engine_json'), devdb_test_path('engine_json') . '/devdb.sqlite');

    expect($engine)->toBeInstanceOf(JsonDevDbEngine::class)
        ->and($engine->name())->toBe('json');
});

it('selects sqlite engine when requested and available', function () {
    $engine = (new DevDbEngineFactory())->make('sqlite', devdb_test_path('engine_sqlite'), devdb_test_path('engine_sqlite') . '/devdb.sqlite');

    expect($engine)->toBeInstanceOf(SqliteDevDbEngine::class)
        ->and($engine->name())->toBe('sqlite');
})->skip(!extension_loaded('pdo_sqlite'), 'pdo_sqlite extension is not available.');

it('auto mode prefers sqlite when available otherwise json', function () {
    $engine = (new DevDbEngineFactory())->make('auto', devdb_test_path('engine_auto'), devdb_test_path('engine_auto') . '/devdb.sqlite');

    if (extension_loaded('pdo_sqlite')) {
        expect($engine)->toBeInstanceOf(SqliteDevDbEngine::class);
    } else {
        expect($engine)->toBeInstanceOf(JsonDevDbEngine::class);
    }
});
