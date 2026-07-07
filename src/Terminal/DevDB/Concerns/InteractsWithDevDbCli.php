<?php

namespace Pinoox\Terminal\DevDB\Concerns;

use Pinoox\Component\Database\DevDB\DevDbRuntime;
use Pinoox\Component\Database\DevDB\DevDbTableDescriptor;
use Pinoox\Terminal\DevDB\Support\DevDbCliPager;
use Pinoox\Terminal\DevDB\Support\DevDbCliPresenter;
use Pinoox\Terminal\DevDB\Support\DevDbCliTheme;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

trait InteractsWithDevDbCli
{
    protected function resolveTableName(SymfonyStyle $io, DevDbRuntime $runtime, ?string $table, bool $interactive): ?string
    {
        $table = trim((string) $table);
        if ($table !== '') {
            return $table;
        }

        if (!$interactive) {
            $io->error('Table name is required in non-interactive mode.');

            return null;
        }

        return $this->selectTable($io, $runtime);
    }

    protected function selectTable(SymfonyStyle $io, DevDbRuntime $runtime, ?string $prompt = null): ?string
    {
        $status = $runtime->status();
        $choices = DevDbCliPresenter::buildTableChoices($status['tables'] ?? []);

        if ($choices === []) {
            $io->warning('No tables found in DevDB.');

            return null;
        }

        $filter = trim((string) $io->ask('Filter tables (optional)', ''));
        if ($filter !== '') {
            $needle = strtolower($filter);
            $choices = array_filter(
                $choices,
                static fn (string $label, string $name): bool => str_contains(strtolower($name), $needle)
                    || str_contains(strtolower($label), $needle),
                ARRAY_FILTER_USE_BOTH,
            );
        }

        if ($choices === []) {
            $io->warning('No tables matched your filter.');

            return null;
        }

        $labels = array_values($choices);
        $selected = $io->choice($prompt ?? 'Select a table', $labels);
        $table = array_search($selected, $choices, true);

        return is_string($table) ? $table : null;
    }

    protected function describeTable(DevDbRuntime $runtime, string $table, int $limit = 10, int $offset = 0): array
    {
        return DevDbTableDescriptor::describe($runtime, $table, $limit, $offset);
    }

    /**
     * @return array{0: int, 1: int}
     */
    protected function resolvePagination(InputInterface $input, int $defaultPerPage = DevDbCliPager::DEFAULT_PER_PAGE): array
    {
        $perPage = DevDbCliPager::normalizePerPage(
            (int) ($input->getOption('per-page') ?? $input->getOption('limit') ?? $defaultPerPage),
        );

        if ($input->hasOption('offset') && $input->getOption('offset') !== null && $input->getOption('offset') !== '') {
            return [max(0, (int) $input->getOption('offset')), $perPage];
        }

        $page = max(1, (int) ($input->getOption('page') ?? 1));

        return [DevDbCliPager::offsetFromPage($page, $perPage), $perPage];
    }

    protected function configurePaginationOptions(Command $command, int $defaultPerPage = DevDbCliPager::DEFAULT_PER_PAGE): Command
    {
        return $command
            ->addOption('page', null, InputOption::VALUE_REQUIRED, 'Page number (1-based)', 1)
            ->addOption('per-page', null, InputOption::VALUE_REQUIRED, 'Rows per page', $defaultPerPage)
            ->addOption('offset', null, InputOption::VALUE_REQUIRED, 'Row offset (overrides --page)');
    }

    protected function askRowLimit(SymfonyStyle $io, int $default = DevDbCliPager::DEFAULT_PER_PAGE): int
    {
        $value = trim((string) $io->ask('How many rows per page?', (string) $default));

        if ($value === '' || !ctype_digit($value)) {
            return DevDbCliPager::normalizePerPage($default);
        }

        return DevDbCliPager::normalizePerPage((int) $value);
    }

    protected function browseTableData(SymfonyStyle $io, DevDbRuntime $runtime, string $table, int $perPage = DevDbCliPager::DEFAULT_PER_PAGE): void
    {
        $perPage = DevDbCliPager::normalizePerPage($perPage);
        $offset = 0;

        while (true) {
            try {
                $inspect = $this->describeTable($runtime, $table, $perPage, $offset);
            } catch (\Throwable $e) {
                $io->error($e->getMessage());

                return;
            }

            DevDbCliPresenter::renderTableOverview($io, $inspect, 'data');

            $meta = DevDbCliPager::meta($offset, $perPage, (int) ($inspect['row_count'] ?? 0));
            if (($meta['total'] ?? 0) === 0) {
                return;
            }

            if (!($meta['has_prev'] ?? false) && !($meta['has_next'] ?? false)) {
                return;
            }

            $actions = ['Back to menu'];
            if ($meta['has_prev']) {
                $actions[] = 'Previous page';
            }
            if ($meta['has_next']) {
                $actions[] = 'Next page';
            }
            $actions[] = 'Jump to page';
            $actions[] = 'Change page size';

            $default = 'Back to menu';
            if ($meta['has_next']) {
                $default = 'Next page';
            } elseif ($meta['has_prev']) {
                $default = 'Previous page';
            }

            $choice = $io->choice('Browse rows', $actions, $default);

            if ($choice === 'Back to menu') {
                return;
            }

            if ($choice === 'Previous page') {
                $offset = max(0, $offset - $perPage);
                continue;
            }

            if ($choice === 'Next page') {
                $offset += $perPage;
                continue;
            }

            if ($choice === 'Change page size') {
                $perPage = $this->askRowLimit($io, $perPage);
                $offset = DevDbCliPager::offsetFromPage((int) ($meta['page'] ?? 1), $perPage);
                continue;
            }

            $pageValue = trim((string) $io->ask(
                'Go to page',
                (string) ($meta['page'] ?? 1),
            ));

            if ($pageValue !== '' && ctype_digit($pageValue)) {
                $offset = DevDbCliPager::offsetFromPage((int) $pageValue, $perPage);
            }
        }
    }

    protected function askExploreAction(SymfonyStyle $io): string
    {
        DevDbCliTheme::banner($io, 'Explorer menu', 'Choose what to inspect next');

        return $io->choice('Action', [
            'Browse table rows (paginated)',
            'List tables',
            'Table structure',
            'Foreign keys and indexes',
            'Full table inspect',
            'Database status',
            'Switch DevDB connection',
            'Exit',
        ], 'Browse table rows (paginated)');
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return array<string, mixed>|null
     */
    protected function selectConnection(SymfonyStyle $io, array $entries, ?string $prompt = null): ?array
    {
        if ($entries === []) {
            $io->warning('No DevDB connections were found in platform or app config.');

            return null;
        }

        $choices = [];
        foreach ($entries as $entry) {
            $label = DevDbCliPresenter::connectionChoiceLabel($entry);
            $choices[$label] = $entry;
        }

        $selected = $io->choice($prompt ?? 'Select a DevDB connection', array_keys($choices));

        return $choices[$selected] ?? null;
    }
}
