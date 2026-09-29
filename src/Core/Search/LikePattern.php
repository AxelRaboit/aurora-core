<?php

declare(strict_types=1);

namespace Aurora\Core\Search;

use function addcslashes;
use function mb_strtolower;
use function mb_trim;

/**
 * The bound value of a `LOWER(column) LIKE :term` that means "contains".
 *
 * Lower-cased here so the SQL side only has to lower the column, and with the
 * three characters LIKE gives a meaning escaped: somebody searching for
 * `100%` or `mon_client` wants those characters, not "anything". Backslash is
 * the default escape character of both PostgreSQL and MySQL, so the query needs
 * no `ESCAPE` clause.
 */
final class LikePattern
{
    public static function contains(string $term): string
    {
        return '%'.addcslashes(mb_strtolower(mb_trim($term)), '%_\\').'%';
    }
}
