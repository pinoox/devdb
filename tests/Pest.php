<?php

uses()->in('Unit', 'Feature');

function devdb_test_path(string $name = 'case'): string
{
    return sys_get_temp_dir() . '/pinoox_devdb_package_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $name) . '_' . uniqid();
}

function devdb_remove_path(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $items = scandir($path);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $child = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($child)) {
            devdb_remove_path($child);
            @rmdir($child);
            continue;
        }

        @unlink($child);
    }
}
