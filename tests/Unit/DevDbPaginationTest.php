<?php

use Pinoox\Component\Database\DevDB\DevDbPagination;
use Pinoox\Component\Database\DevDB\DevDbStore;

it('builds pagination metadata', function () {
    $meta = DevDbPagination::meta(30, 15, 100);

    expect($meta['page'])->toBe(3)
        ->and($meta['from'])->toBe(31)
        ->and($meta['to'])->toBe(45)
        ->and($meta['has_prev'])->toBeTrue()
        ->and($meta['has_next'])->toBeTrue()
        ->and(DevDbPagination::label($meta))->toContain('page 3/7');
});

it('paginates DevDB table rows by offset', function () {
    $path = devdb_test_path('pagination');
    $store = new DevDbStore($path);
    $store->createTable('items', [
        'id' => ['type' => 'integer', 'primary' => true, 'auto_increment' => true],
        'label' => ['type' => 'string'],
    ]);

    $rows = [];
    for ($i = 1; $i <= 25; $i++) {
        $rows[] = ['id' => $i, 'label' => 'Item ' . $i];
    }
    $store->replaceTable('items', $rows);

    $page1 = $store->inspectTable('items', 10, 0);
    $page2 = $store->inspectTable('items', 10, 10);

    expect($page1['row_count'])->toBe(25)
        ->and($page1['page'])->toBe(1)
        ->and($page1['total_pages'])->toBe(3)
        ->and($page1['rows'][0]['label'])->toBe('Item 1')
        ->and($page2['page'])->toBe(2)
        ->and($page2['rows'][0]['label'])->toBe('Item 11');

    devdb_remove_path($path);
});
