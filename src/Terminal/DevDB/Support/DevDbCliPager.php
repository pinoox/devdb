<?php

namespace Pinoox\Terminal\DevDB\Support;

use Pinoox\Component\Database\DevDB\DevDbPagination;

final class DevDbCliPager
{
    public const DEFAULT_PER_PAGE = DevDbPagination::DEFAULT_PER_PAGE;

    public const MAX_PER_PAGE = DevDbPagination::MAX_PER_PAGE;

    public static function normalizePerPage(int $perPage): int
    {
        return DevDbPagination::normalizePerPage($perPage);
    }

    public static function offsetFromPage(int $page, int $perPage): int
    {
        return DevDbPagination::offsetFromPage($page, $perPage);
    }

    /**
     * @return array{offset: int, limit: int, page: int, total: int, total_pages: int, from: int, to: int, has_prev: bool, has_next: bool}
     */
    public static function meta(int $offset, int $limit, int $total): array
    {
        return DevDbPagination::meta($offset, $limit, $total);
    }

    public static function label(array $meta): string
    {
        return DevDbPagination::label($meta);
    }
}
