<?php

namespace Pinoox\Terminal\DevDB;

use Pinoox\Component\Terminal;
use Pinoox\Terminal\DevDB\Concerns\InteractsWithDevDbCli;
use Pinoox\Terminal\DevDB\Concerns\UsesDevDbStore;
use Pinoox\Terminal\DevDB\Support\DevDbCliPager;
use Pinoox\Terminal\DevDB\Support\DevDbCliPresenter;
use Pinoox\Terminal\DevDB\Support\DevDbCliTheme;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'devdb:explore',
    description: 'Explore Pinoox DevDB tables, structure, relations, and data interactively',
)]
class DevDbExploreCommand extends Terminal
{
    use InteractsWithDevDbCli;
    use UsesDevDbStore;

    protected function configure(): void
    {
        $this
            ->configureConnectionOptions($this)
            ->configurePaginationOptions($this)
            ->addOption('table', 't', InputOption::VALUE_REQUIRED, 'Open a table directly')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Rows per page (alias of --per-page)', DevDbCliPager::DEFAULT_PER_PAGE)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON for direct table inspect')
            ->addOption('no-interaction', 'n', InputOption::VALUE_NONE, 'Disable prompts');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);
        $interactive = $input->isInteractive() && !$input->getOption('no-interaction');

        $directTable = trim((string) $input->getOption('table'));
        if ($directTable !== '') {
            if (!$this->bootstrapRuntime($input, $io)) {
                return Command::FAILURE;
            }

            [$offset, $perPage] = $this->resolvePagination($input);

            return $this->renderDirectInspect($io, $this->runtime(), $directTable, $perPage, $offset, (bool) $input->getOption('json'));
        }

        if (!$interactive) {
            if (!$this->bootstrapRuntime($input, $io)) {
                return Command::FAILURE;
            }

            $runtime = $this->runtime();
            $status = $runtime->status();
            DevDbCliPresenter::renderStatusHeader($io, $status, $runtime->connectionName());
            DevDbCliPresenter::renderTables($io, $status, false);
            $io->note('Run without --no-interaction to use the guided explorer, or pass --table=<name>.');

            return Command::SUCCESS;
        }

        if (!$this->bootstrapRuntime($input, $io)) {
            return Command::FAILURE;
        }

        DevDbCliTheme::banner($io, 'DevDB Explorer', 'Interactive local database browser');
        $io->listing([
            'Browse tables with pagination for large datasets',
            'Use --connection or --path to target another DevDB store',
            'Press Ctrl+C any time to exit',
        ]);

        $defaultPerPage = DevDbCliPager::normalizePerPage((int) $input->getOption('limit'));

        while (true) {
            $runtime = $this->runtime();
            $action = $this->askExploreAction($io);

            if ($action === 'Exit') {
                $io->success('Done exploring DevDB.');

                return Command::SUCCESS;
            }

            if ($action === 'Switch DevDB connection') {
                $this->resetRuntime();
                if (!$this->bootstrapRuntime($input, $io)) {
                    continue;
                }

                $runtime = $this->runtime();
                $io->success('Switched to ' . ($runtime->connectionName() ?? 'DevDB') . ' (' . $runtime->path() . ').');
                continue;
            }

            if ($action === 'Database status') {
                $status = $runtime->status();
                DevDbCliPresenter::renderStatusHeader($io, $status, $runtime->connectionName());
                DevDbCliPresenter::renderTables($io, $status, false);
                $io->newLine();
                continue;
            }

            if ($action === 'List tables') {
                DevDbCliPresenter::renderTables($io, $runtime->status(), false);
                $io->newLine();
                continue;
            }

            $table = $this->selectTable($io, $runtime);
            if ($table === null) {
                continue;
            }

            if ($action === 'Browse table rows (paginated)') {
                $perPage = $this->askRowLimit($io, $defaultPerPage);
                $this->browseTableData($io, $runtime, $table, $perPage);
                $io->newLine();
                continue;
            }

            $perPage = in_array($action, ['Full table inspect'], true)
                ? $this->askRowLimit($io, $defaultPerPage)
                : DevDbCliPager::DEFAULT_PER_PAGE;

            try {
                $inspect = $this->describeTable($runtime, $table, $perPage, 0);
            } catch (\Throwable $e) {
                $io->error($e->getMessage());
                continue;
            }

            $view = match ($action) {
                'Table structure' => 'structure',
                'Foreign keys and indexes' => 'relations',
                default => 'all',
            };

            DevDbCliPresenter::renderTableOverview($io, $inspect, $view);
            $io->newLine();
        }
    }

    private function renderDirectInspect(SymfonyStyle $io, $runtime, string $table, int $limit, int $offset, bool $asJson): int
    {
        try {
            $inspect = $this->describeTable($runtime, $table, $limit, $offset);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($asJson) {
            $io->writeln(json_encode($inspect, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return Command::SUCCESS;
        }

        if ($runtime->connectionName() !== null) {
            $io->note('Connection: ' . $runtime->connectionName());
        }

        DevDbCliPresenter::renderTableOverview($io, $inspect, 'all');

        return Command::SUCCESS;
    }
}
