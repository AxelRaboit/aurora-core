<?php

declare(strict_types=1);

namespace Aurora\Core\Http;

/**
 * Universal JSON error codes used across endpoints. Domain-specific codes
 * (e.g. `finalized`, `identity_required`, `max_picks_reached`) stay as raw
 * strings in their respective controllers - they belong to their feature.
 */
enum JsonErrorCode: string
{
    case NotFound = 'not_found';
    case Forbidden = 'forbidden';
    case Unauthorized = 'unauthorized';
    case Conflict = 'conflict';
    case BadRequest = 'bad_request';
}
