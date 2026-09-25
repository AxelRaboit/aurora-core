<?php

declare(strict_types=1);

namespace Aurora\Core\Storage;

use Aurora\Core\Storage\Enum\StorageDiskEnum;

/**
 * Tells the file locator which disk holds a key, when a module already knows.
 *
 * Without a hint, a key that is not on the local disk is looked for on every
 * other disk with an existence check, and on a remote disk that check is a
 * network round trip - on every request, for every picture a page draws.
 * Measured on 2026-09-25 against production: about 0.3 seconds of waiting
 * per image, against 0.05 for any other route. The media library records the
 * disk of each document when it stores it, so asking is pure waste there.
 *
 * Implementations are registered with the `aurora.stored_disk_hint` tag. The
 * first one that supports a key answers for it; null means "no idea", and the
 * locator falls back to looking.
 */
interface StoredDiskHintInterface
{
    public function supports(string $key): bool;

    public function diskFor(string $key): ?StorageDiskEnum;
}
