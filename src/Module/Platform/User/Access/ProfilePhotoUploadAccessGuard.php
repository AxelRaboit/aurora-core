<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\User\Access;

use Aurora\Core\Storage\Access\UploadAccessDecider;
use Aurora\Core\Storage\Access\UploadAccessEnum;
use Aurora\Core\Storage\Access\UploadAccessGuardInterface;
use Aurora\Core\Storage\Enum\StorageAreaEnum;

use function str_starts_with;

/**
 * Profile photos are not public.
 *
 * The area had no guard, and {@see UploadAccessDecider}
 * leaves an unclaimed prefix anonymous - deliberately, so that a client
 * storing things under a prefix aurora-core has never heard of does not break.
 * The default is sound; what was missing is this class, because
 * `profile-photos/` is exactly a prefix that holds private files and is known.
 *
 * **`Denied` and never `Restricted`.** The same reasoning the GED's guard
 * spells out: `/uploads/{path}` is not matched by the admin firewall's
 * pattern, so no backend identity is restored on such a request and asking
 * whether the visitor is signed in would be asking a question whose answer is
 * always no. Staff read a photo through `backend_platform_profile_photos_serve`
 * instead, which is under the prefix the firewall does cover.
 *
 * A photo of a person's face is not something to hand to whoever guesses an
 * address, and until 2026-09-16 the address was guessable: the filename was
 * the account id followed by the microsecond of the upload.
 */
final readonly class ProfilePhotoUploadAccessGuard implements UploadAccessGuardInterface
{
    public function supports(string $key): bool
    {
        return str_starts_with($key, StorageAreaEnum::ProfilePhotos->value.'/');
    }

    public function decide(string $key): UploadAccessEnum
    {
        return UploadAccessEnum::Denied;
    }
}
