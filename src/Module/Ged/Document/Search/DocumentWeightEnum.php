<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Search;

/**
 * File size in three buckets, the way one looks for "the heavy ones" before
 * a clean-up or "something light" for an email, rather than in exact bytes.
 */
enum DocumentWeightEnum: string
{
    case Light = 'light';
    case Medium = 'medium';
    case Heavy = 'heavy';

    public const int LIGHT_MAX = 500 * 1024;

    public const int HEAVY_MIN = 5 * 1024 * 1024;
}
