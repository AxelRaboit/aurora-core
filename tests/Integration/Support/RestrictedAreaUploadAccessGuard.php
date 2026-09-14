<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Support;

use Aurora\Core\Storage\Access\UploadAccessEnum;
use Aurora\Core\Storage\Access\UploadAccessGuardInterface;

use function str_starts_with;

/**
 * The restricted answer, which no shipped guard produces.
 *
 * `UploadAccessEnum::Restricted` is the vocabulary the interface offers a
 * client's own area: served, but through the application and to this visitor
 * only. Both guards aurora-core ships answer `Anonymous` or `Denied` - the GED
 * because its private files are read under `/backend`, the contracts module
 * because nothing of its area is served here at all - so the branch that
 * honours the third answer was never executed by anything.
 *
 * That is the kind of untested code that matters: the day a client writes the
 * guard the interface invites, this branch is what keeps their file out of a
 * shared cache. So the suite registers a producer of its own rather than
 * waiting for one.
 *
 * Registered under `when@test` in `config/services.yaml`, on a prefix no
 * `StorageAreaEnum` case can produce, so it claims nothing a real guard or a
 * real upload would.
 */
final readonly class RestrictedAreaUploadAccessGuard implements UploadAccessGuardInterface
{
    /** The area this guard owns, and the only one it answers for. */
    public const string PREFIX = 'restricted-test';

    public function supports(string $key): bool
    {
        return str_starts_with($key, self::PREFIX.'/');
    }

    public function decide(string $key): UploadAccessEnum
    {
        return UploadAccessEnum::Restricted;
    }
}
