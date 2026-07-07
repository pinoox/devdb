<?php

namespace Pinoox\Terminal\DevDB\Support;

use Symfony\Component\Console\Style\SymfonyStyle;

final class DevDbCliTheme
{
    public static function banner(SymfonyStyle $io, string $title, ?string $subtitle = null): void
    {
        $io->writeln('');
        $io->writeln('  <fg=cyan;options=bold>◆ ' . self::escape($title) . '</>');
        if (is_string($subtitle) && $subtitle !== '') {
            $io->writeln('  <fg=gray>' . self::escape($subtitle) . '</>');
        }
        $io->writeln('  <fg=gray>' . str_repeat('─', min(72, max(24, strlen($title) + 6))) . '</>');
        $io->writeln('');
    }

    public static function statLine(SymfonyStyle $io, array $items): void
    {
        $parts = [];
        foreach ($items as $label => $value) {
            $parts[] = '<fg=green>' . self::escape((string) $value) . '</> ' . self::escape((string) $label);
        }

        if ($parts !== []) {
            $io->writeln('  ' . implode('  ·  ', $parts));
            $io->writeln('');
        }
    }

    public static function badge(string $text, string $color = 'cyan'): string
    {
        return '<fg=' . $color . ';options=bold>' . self::escape($text) . '</>';
    }

    public static function truncate(mixed $value, int $max = 48): string
    {
        $text = self::scalar($value);

        if (strlen($text) <= $max) {
            return $text;
        }

        return substr($text, 0, max(1, $max - 1)) . '…';
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / (1024 * 1024), 2) . ' MB';
    }

    public static function formatNumber(int|float $value): string
    {
        return number_format((float) $value, 0, '.', ',');
    }

    public static function scalar(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }

    private static function escape(string $text): string
    {
        return str_replace(['<', '>'], ['\\<', '\\>'], $text);
    }
}
