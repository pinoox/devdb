<?php

namespace Pinoox\Terminal\DevDB;

use PDO;
use PDOException;
use Pinoox\Component\Database\DevDB\DevDbMySqlExporter;
use Pinoox\Component\Terminal;
use Pinoox\Support\SystemConfig;
use Pinoox\Terminal\DevDB\Concerns\UsesDevDbStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'devdb:sync:mysql', description: 'Sync Pinoox DevDB into a local MySQL database')]
class DevDbSyncMySqlCommand extends Terminal
{
    use UsesDevDbStore;

    protected function configure(): void
    {
        $this
            ->configureConnectionOptions($this)
            ->addOption('dsn', null, InputOption::VALUE_REQUIRED, 'PDO MySQL DSN, for example mysql:host=127.0.0.1;port=3306;dbname=app')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'MySQL host', (string) SystemConfig::env('DEVDB_MYSQL_HOST', '127.0.0.1'))
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'MySQL port', (string) SystemConfig::env('DEVDB_MYSQL_PORT', '3306'))
            ->addOption('database', null, InputOption::VALUE_REQUIRED, 'MySQL database name', (string) SystemConfig::env('DEVDB_MYSQL_DATABASE', ''))
            ->addOption('username', null, InputOption::VALUE_REQUIRED, 'MySQL username', (string) SystemConfig::env('DEVDB_MYSQL_USERNAME', 'root'))
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'MySQL password', (string) SystemConfig::env('DEVDB_MYSQL_PASSWORD', ''))
            ->addOption('no-drop', null, InputOption::VALUE_NONE, 'Do not drop existing tables before sync')
            ->addOption('schema-only', null, InputOption::VALUE_NONE, 'Sync schema without row data')
            ->addOption('data-only', null, InputOption::VALUE_NONE, 'Sync row data without schema')
            ->addOption('tables', null, InputOption::VALUE_REQUIRED, 'Comma-separated table names to sync')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be synced without connecting to MySQL');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);

        if (!$this->bootstrapRuntime($input, $io)) {
            return Command::FAILURE;
        }

        $exporter = new DevDbMySqlExporter();
        $mode = $input->getOption('schema-only') ? 'schema' : ($input->getOption('data-only') ? 'data' : 'all');
        $tables = $this->tableOption((string) ($input->getOption('tables') ?? ''));
        $summary = $exporter->summary($this->runtime()->export(), $tables);

        if ($input->getOption('dry-run')) {
            $io->success(sprintf(
                'Dry run: %d table(s), %d row(s), mode: %s.',
                $summary['tables'],
                $summary['rows'],
                $mode,
            ));
            if ($summary['table_names'] !== []) {
                $io->listing($summary['table_names']);
            }

            return Command::SUCCESS;
        }

        if (!extension_loaded('pdo_mysql')) {
            $io->error('PDO MySQL extension is not available. Enable pdo_mysql or use devdb:export:mysql and import the SQL manually.');

            return Command::FAILURE;
        }

        $database = (string) $input->getOption('database');
        $dsn = (string) ($input->getOption('dsn') ?: '');
        if ($dsn === '') {
            if ($database === '') {
                $io->error('Missing MySQL database name. Pass --database=app_dev or --dsn="mysql:host=127.0.0.1;dbname=app_dev".');

                return Command::FAILURE;
            }

            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                (string) $input->getOption('host'),
                (string) $input->getOption('port'),
                $database,
            );
        }

        try {
            $pdo = new PDO($dsn, (string) $input->getOption('username'), (string) $input->getOption('password'), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            $exporter->sync($pdo, $this->runtime()->export(), !$input->getOption('no-drop'), $tables, $mode);
        } catch (PDOException $exception) {
            $io->error('MySQL sync failed: ' . $exception->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('DevDB synced to MySQL. Tables: %d, rows: %d.', $summary['tables'], $summary['rows']));

        return Command::SUCCESS;
    }

    private function tableOption(string $tables): ?array
    {
        if (trim($tables) === '') {
            return null;
        }

        return array_values(array_filter(array_map('trim', explode(',', $tables))));
    }
}
