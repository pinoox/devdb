<?php

namespace Pinoox\Terminal\DevDB\Concerns;

use Pinoox\Component\Database\DevDB\DevDbConnectionCatalog;
use Pinoox\Component\Database\DevDB\DevDbException;
use Pinoox\Component\Database\DevDB\DevDbRuntime;
use Pinoox\Component\Database\DevDB\DevDbStore;
use Pinoox\Support\SystemConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

trait UsesDevDbStore
{
    private ?DevDbRuntime $resolvedRuntime = null;

    protected function configureConnectionOptions(Command $command): Command
    {
        return $command
            ->addOption('connection', null, InputOption::VALUE_REQUIRED, 'DevDB connection name (e.g. devdb, app:com_my_shop, app_com_my_shop_default)')
            ->addOption('path', 'p', InputOption::VALUE_REQUIRED, 'Direct DevDB storage path (overrides --connection)')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Show all configured DevDB connections');
    }

    protected function bootstrapRuntime(InputInterface $input, SymfonyStyle $io): bool
    {
        try {
            $this->resolvedRuntime = $this->resolveRuntime($input, $io);

            return true;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return false;
        }
    }

    protected function resetRuntime(): void
    {
        $this->resolvedRuntime = null;
    }

    protected function store(): DevDbStore
    {
        return $this->runtime()->store();
    }

    protected function runtime(?InputInterface $input = null, ?SymfonyStyle $io = null): DevDbRuntime
    {
        if ($this->resolvedRuntime !== null) {
            return $this->resolvedRuntime;
        }

        if ($input !== null && $io !== null) {
            $this->resolvedRuntime = $this->resolveRuntime($input, $io);

            return $this->resolvedRuntime;
        }

        return DevDbRuntime::create();
    }

    protected function resolveRuntime(InputInterface $input, SymfonyStyle $io): DevDbRuntime
    {
        $path = trim((string) $input->getOption('path'));
        if ($path !== '') {
            return DevDbRuntime::forPath($path);
        }

        $connection = trim((string) $input->getOption('connection'));
        if ($connection !== '') {
            $entry = DevDbConnectionCatalog::find($connection);
            if ($entry === null) {
                throw new DevDbException('DevDB connection "' . $connection . '" was not found. Run `php pinoox devdb:connections`.');
            }

            return DevDbRuntime::fromCatalogEntry($entry);
        }

        $interactive = $input->isInteractive() && !$input->getOption('no-interaction');
        $entries = DevDbConnectionCatalog::all();

        if ($entries === []) {
            return DevDbRuntime::create();
        }

        if (count($entries) === 1) {
            return DevDbRuntime::fromCatalogEntry($entries[0]);
        }

        if (!$interactive) {
            $default = DevDbConnectionCatalog::defaultEntry();

            return $default !== null
                ? DevDbRuntime::fromCatalogEntry($default)
                : DevDbRuntime::fromCatalogEntry($entries[0]);
        }

        if (!method_exists($this, 'selectConnection')) {
            $default = DevDbConnectionCatalog::defaultEntry();

            return $default !== null
                ? DevDbRuntime::fromCatalogEntry($default)
                : DevDbRuntime::fromCatalogEntry($entries[0]);
        }

        $entry = $this->selectConnection($io, $entries);
        if ($entry === null) {
            throw new DevDbException('No DevDB connection selected.');
        }

        return DevDbRuntime::fromCatalogEntry($entry);
    }

    protected function forceDevDbConnection(): void
    {
        $_ENV['DB_CONNECTION'] = 'devdb';
        $_SERVER['DB_CONNECTION'] = 'devdb';
        putenv('DB_CONNECTION=devdb');
        SystemConfig::clearCache();
    }

    protected function forceConnectionFromInput(InputInterface $input): void
    {
        $connection = trim((string) $input->getOption('connection'));
        if ($connection === '') {
            $this->forceDevDbConnection();

            return;
        }

        $entry = DevDbConnectionCatalog::find($connection);
        $name = is_array($entry) ? (string) ($entry['name'] ?? $connection) : $connection;

        $_ENV['DB_CONNECTION'] = $name;
        $_SERVER['DB_CONNECTION'] = $name;
        putenv('DB_CONNECTION=' . $name);
        SystemConfig::clearCache();
    }
}
