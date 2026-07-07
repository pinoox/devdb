<?php

namespace Pinoox\Component\Database\DevDB;

final class DevDbPagination
{
    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    public static function normalizePerPage(int $perPage): int
    {
        if ($perPage < 1) {
            return self::DEFAULT_PER_PAGE;
        }

        return min($perPage, self::MAX_PER_PAGE);
    }

    public static function offsetFromPage(int $page, int $perPage): int
    {
        return max(0, (max(1, $page) - 1) * self::normalizePerPage($perPage));
    }

    /**
     * @return array{offset: int, limit: int, page: int, total: int, total_pages: int, from: int, to: int, has_prev: bool, has_next: bool}
     */
    public static function meta(int $offset, int $limit, int $total): array
    {
        $limit = self::normalizePerPage($limit);
        $total = max(0, $total);
        $totalPages = $total === 0 ? 0 : (int) ceil($total / $limit);
        $page = $totalPages === 0 ? 0 : (int) floor($offset / $limit) + 1;
        $from = $total === 0 ? 0 : min($total, $offset + 1);
        $to = $total === 0 ? 0 : min($total, $offset + $limit);

        return [
            'offset' => max(0, $offset),
            'limit' => $limit,
            'page' => $page,
            'total' => $total,
            'total_pages' => $totalPages,
            'from' => $from,
            'to' => $to,
            'has_prev' => $offset > 0,
            'has_next' => $to < $total,
        ];
    }

    public static function label(array $meta): string
    {
        if (($meta['total'] ?? 0) === 0) {
            return 'No rows';
        }

        return sprintf(
            'Rows %d–%d of %d · page %d/%d',
            (int) ($meta['from'] ?? 0),
            (int) ($meta['to'] ?? 0),
            (int) ($meta['total'] ?? 0),
            (int) ($meta['page'] ?? 0),
            (int) ($meta['total_pages'] ?? 0),
        );
    }
}
