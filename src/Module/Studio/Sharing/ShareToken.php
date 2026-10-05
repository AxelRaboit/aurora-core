<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Sharing;

use function bin2hex;
use function hash;
use function preg_match;
use function random_bytes;

/**
 * The secret in a reading address, the same everywhere in Studio.
 *
 * 64 hexadecimal characters drawn from 32 random bytes. A link that has to be
 * shown again (a deliverable's address stays readable in its window) keeps the
 * token ENCRYPTED and is looked up by its HASH: the hash is a one-way
 * fingerprint, so a database dump or a SQL log no longer hands out a usable
 * address, while the screen can still display the one the application decrypts.
 *
 * Kept as plain static functions: the entities that draw a token in their
 * constructor have no container to ask.
 */
final class ShareToken
{
    /** What a route must accept: exactly what {@see self::generate()} produces. */
    public const string PATTERN = '[a-f0-9]{64}';

    private function __construct() {}

    public static function generate(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** SHA-256 of the token: the value to search by, never to display. */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function isWellFormed(string $token): bool
    {
        // `D`: without it `$` also matches before a trailing newline.
        return 1 === preg_match('/^'.self::PATTERN.'$/D', $token);
    }
}
