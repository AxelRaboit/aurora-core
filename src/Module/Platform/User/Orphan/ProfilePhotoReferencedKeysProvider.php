<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\User\Orphan;

use Aurora\Core\Storage\Enum\StorageAreaEnum;
use Aurora\Core\Storage\Orphan\ReferencedKeysProviderInterface;
use Aurora\Module\Platform\User\Repository\UserRepository;

use function sprintf;

/**
 * The photo each account still points at.
 *
 * Replacing a photo removes the file it replaces, so in a healthy install this
 * area holds nothing extra. What it does hold is what a failed write or a
 * deleted account left behind, which nothing counted until this existed.
 *
 * The column stores a bare filename, not a key, so the area prefix is put back
 * here - the sweep compares against what the backend lists, which is prefixed.
 */
final readonly class ProfilePhotoReferencedKeysProvider implements ReferencedKeysProviderInterface
{
    public function __construct(private UserRepository $userRepository) {}

    public function area(): StorageAreaEnum
    {
        return StorageAreaEnum::ProfilePhotos;
    }

    public function referencedKeys(): iterable
    {
        /** @var list<array{profilePhotoPath: string|null}> $rows */
        $rows = $this->userRepository->createQueryBuilder('u')
            ->select('u.profilePhotoPath')
            ->where('u.profilePhotoPath IS NOT NULL')
            ->getQuery()
            ->getResult();

        foreach ($rows as $row) {
            if (null !== $row['profilePhotoPath'] && '' !== $row['profilePhotoPath']) {
                yield sprintf('%s/%s', StorageAreaEnum::ProfilePhotos->value, $row['profilePhotoPath']);
            }
        }
    }
}
