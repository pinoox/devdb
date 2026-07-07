<?php

namespace Pinoox\Terminal\DevDB;

use Pinoox\Component\Terminal;
use Pinoox\Terminal\DevDB\Concerns\InteractsWithDevDbCli;
use Pinoox\Terminal\DevDB\Concerns\UsesDevDbStore;
use Pinoox\Terminal\DevDB\Support\DevDbCliPresenter;
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
            ->addOption('table', 't', InputOption::VALUE_REQUIRED, 'Open a table directly')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Rows to show when inspecting data', 10)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON for direct table inspect')
            ->addOption('no-interaction', 'n', InputOption::VALUE_NONE, 'Disable prompts');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);
        $runtime = $this->runtime();
        $interactive = $input->isInteractive() && !$input->getOption('no-interaction');

        $directTable = trim((string) $input->getOption('table'));
        if ($directTable !== '') {
            return $this->renderDirectInspect($io, $runtime, $directTable, (int) $input->getOption('limit'), (bool) $input->getOption('json'));
        }

        if (!$interactive) {
            $status = $runtime->status();
            DevDbCliPresenter::renderStatusHeader($io, $status);
            DevDbCliPresenter::renderTables($io, $status, false);
            $io->note('Run without --no-interaction to use the guided explorer, or pass --table=<name>.');

            return Command::SUCCESS;
        }

        $io->title('DevDB Explorer');
        $io->text([
            'Browse your local development database with guided prompts.',
            'Press Ctrl+C any time to exit.',
        ]);

        while (true) {
            $action = $this->askExploreAction($io);

            if ($action === 'Exit') {
                $io->success('Done exploring DevDB.');

                return Command::SUCCESS;
            }

            if ($action === 'Database status') {
                $status = $runtime->status();
                DevDbCliPresenter::renderStatusHeader($io, $status);
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

            $limit = in_array($action, ['Table data', 'Full table inspect'], true)
                ? $this->askRowLimit($io, (int) $input->getOption('limit'))
                : 0;

            try {
                $inspect = $this->describeTable($runtime, $table, $limit);
            } catch (\Throwable $e) {
                $io->error($e->getMessage());
                continue;
            }

            $view = match ($action) {
                'Table structure' => 'structure',
                'Table data' => 'data',
                'Foreign keys and indexes' => 'relations',
                default => 'all',
            };

            DevDbCliPresenter::renderTableOverview($io, $inspect, $view);
            $io->newLine();
        }
    }

    private function renderDirectInspect(SymfonyStyle $io, $runtime, string $table, int $limit, bool $asJson): int
    {
        try {
            $inspect = $this->describeTable($runtime, $table, $limit);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($asJson) {
            $io->writeln(json_encode($inspect, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return Command::SUCCESS;
        }

        DevDbCliPresenter::renderTableOverview($io, $inspect, 'all');

        return Command::SUCCESS;
    }
}
