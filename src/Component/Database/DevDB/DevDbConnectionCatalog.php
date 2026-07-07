<?php

namespace Pinoox\Component\Database\DevDB;

use Pinoox\Component\Database\AppDatabaseResolver;
use Pinoox\Component\Database\DatabaseConfig;
use Pinoox\Support\SystemConfig;

final class DevDbConnectionCatalog
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        $entries = [];
        $seenPaths = [];

        foreach (self::platformEntries() as $entry) {
            $entries[] = $entry;
            $seenPaths[self::pathKey($entry)] = true;
        }

        foreach (self::appEntries() as $entry) {
            $pathKey = self::pathKey($entry);
            if (isset($seenPaths[$pathKey])) {
                $entry['shared_path'] = true;
            } else {
                $seenPaths[$pathKey] = true;
            }

            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $name): ?array
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        foreach (self::all() as $entry) {
            if (in_array($name, $entry['aliases'], true)) {
                return $entry;
            }
        }

        return null;
    }

    public static function defaultEntry(): ?array
    {
        try {
            $name = DatabaseConfig::connectionName();
        } catch (\Throwable) {
            $name = DatabaseConfig::DEVDB_CONNECTION;
        }

        return self::find($name) ?? self::find(DatabaseConfig::DEVDB_CONNECTION) ?? self::all()[0] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function platformEntries(): array
    {
        $root = SystemConfig::get('database');
        if (!is_array($root)) {
            return [];
        }

        $root = DatabaseConfig::normalize($root);
        $entries = [];

        foreach ($root['connections'] ?? [] as $name => $config) {
            if (!is_string($name) || $name === '' || !is_array($config)) {
                continue;
            }

            $entry = self::entryFromConfig($name, $config, 'platform');
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function appEntries(): array
    {
        $appsRoot = SystemConfig::resolvePath((string) (SystemConfig::env('PINOOX_APPS_PATH', 'apps')));
        if (!is_dir($appsRoot)) {
            return [];
        }

        $platformDatabase = SystemConfig::get('database');
        $platformDatabase = is_array($platformDatabase) ? $platformDatabase : null;
        $entries = [];

        foreach (glob($appsRoot . '/*/app.php') ?: [] as $appFile) {
            if (!is_file($appFile)) {
                continue;
            }

            $manifest = include $appFile;
            if (!is_array($manifest)) {
                continue;
            }

            $package = (string) ($manifest['package'] ?? basename(dirname($appFile)));
            $database = $manifest['database'] ?? null;
            if (!is_array($database) && $database !== null) {
                continue;
            }

            $connections = AppDatabaseResolver::resolve(
                is_array($database) ? $database : null,
                is_array($manifest['table'] ?? null) ? $manifest['table'] : null,
                $platformDatabase,
            );

            foreach ($connections as $connectionName => $config) {
                if (!is_string($connectionName) || $connectionName === '' || !is_array($config)) {
                    continue;
                }

                $internalName = self::appConnectionName($package, $connectionName);
                $entry = self::entryFromConfig(
                    $internalName,
                    $config,
                    'app',
                    $package,
                    $connectionName,
                );

                if ($entry !== null) {
                    $entries[] = $entry;
                }
            }
        }

        usort($entries, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return $entries;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>|null
     */
    private static function entryFromConfig(
        string $name,
        array $config,
        string $source,
        ?string $package = null,
        ?string $appConnection = null,
    ): ?array {
        if (!self::isDevDbConnection($config)) {
            return null;
        }

        $runtimeConfig = self::normalizeForRuntime($config);
        if ($runtimeConfig === null) {
            return null;
        }

        $path = (string) ($runtimeConfig['devdb_path'] ?? $runtimeConfig['path'] ?? '');
        if ($path === '') {
            return null;
        }

        $aliases = [$name];
        if ($source === 'app' && is_string($package) && $package !== '') {
            $aliases[] = 'app:' . $package;
            if (is_string($appConnection) && $appConnection !== '' && $appConnection !== 'default') {
                $aliases[] = 'app:' . $package . ':' . $appConnection;
            }
        }

        $label = $source === 'app' && is_string($package)
            ? 'App ' . $package . ($appConnection && $appConnection !== 'default' ? ' (' . $appConnection . ')' : '')
            : 'Platform (' . $name . ')';

        return [
            'name' => $name,
            'label' => $label,
            'aliases' => array_values(array_unique($aliases)),
            'path' => SystemConfig::resolvePath($path),
            'engine' => (string) ($runtimeConfig['devdb_engine'] ?? $runtimeConfig['engine'] ?? 'auto'),
            'sqlite_database' => self::sqliteDatabaseFromConfig($runtimeConfig, $path),
            'prefix' => (string) ($runtimeConfig['prefix'] ?? ''),
            'source' => $source,
            'package' => $package,
            'connection' => $appConnection,
            'config' => $runtimeConfig,
            'shared_path' => false,
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function isDevDbConnection(array $config): bool
    {
        return ($config['driver'] ?? '') === 'devdb'
            || !empty($config['devdb'])
            || !empty($config['devdb_path']);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>|null
     */
    private static function normalizeForRuntime(array $config): ?array
    {
        if (($config['driver'] ?? '') !== 'devdb') {
            if (!empty($config['devdb_path']) || !empty($config['devdb'])) {
                return $config;
            }

            return null;
        }

        try {
            return DatabaseConfig::normalizeDevDbConnection($config);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function sqliteDatabaseFromConfig(array $config, string $path): string
    {
        $database = (string) ($config['database'] ?? '');
        if (($config['devdb_engine'] ?? '') === 'sqlite' && $database !== '') {
            return SystemConfig::resolvePath($database);
        }

        $sqliteDatabase = (string) ($config['sqlite_database'] ?? '');
        if ($sqliteDatabase !== '') {
            return SystemConfig::resolvePath($sqliteDatabase);
        }

        return SystemConfig::resolvePath($path) . '/devdb.sqlite';
    }

    private static function appConnectionName(string $package, string $name): string
    {
        $package = preg_replace('/[^A-Za-z0-9_]+/', '_', $package) ?? $package;
        $name = preg_replace('/[^A-Za-z0-9_]+/', '_', $name) ?? $name;

        return 'app_' . $package . '_' . $name;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function pathKey(array $entry): string
    {
        return strtolower((string) ($entry['path'] ?? ''));
    }
}
