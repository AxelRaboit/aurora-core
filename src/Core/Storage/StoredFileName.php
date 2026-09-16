<?php

declare(strict_types=1);

namespace Aurora\Core\Storage;

use function bin2hex;
use function random_bytes;
use function sprintf;

/**
 * The name a file is stored under, which is not the name it arrived with.
 *
 * **Unguessable, because for most of what Aurora stores the address is the
 * only lock.** `UploadAccessDecider` leaves an unclaimed prefix anonymous and
 * the GED serves every published document to anybody, so a reader who knows
 * the URL reads the file. That arrangement is defensible only while the URL
 * cannot be worked out, and until 2026-09-16 it could be: names were built as
 * `slug(original name) + uniqid()`, where the first half is often guessable -
 * `logo.png`, `cv.pdf`, `contrat.pdf` - and the second is not random at all
 * but the microsecond of the upload. Given a rough idea of when something was
 * filed, the search space was small.
 *
 * Sixteen bytes from `random_bytes()`, which is the CSPRNG, and nothing else.
 * No date, no counter, no fragment of what the file was called.
 *
 * **The original name is not lost, it is stored instead of shown.** Documents
 * keep `originalName` in their own column and hand it back through
 * `Content-Disposition` on download, which is where a human-readable name is
 * actually of use. On disk it only ever leaked information.
 */
final readonly class StoredFileName
{
    /** Bytes of entropy. 128 bits: not a number anybody enumerates. */
    public const int ENTROPY_BYTES = 16;

    public static function withExtension(string $extension): string
    {
        return sprintf('%s.%s', self::bare(), $extension);
    }

    public static function bare(): string
    {
        return bin2hex(random_bytes(self::ENTROPY_BYTES));
    }
}
