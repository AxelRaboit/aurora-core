<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\User\Manager;

use Aurora\Core\Storage\Enum\StorageAreaEnum;
use Aurora\Core\Storage\Enum\StorageDiskEnum;
use Aurora\Core\Storage\StorageManager;
use Aurora\Core\Storage\StoredFileName;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Service\UserProfilePhotoUrlGenerator;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\HttpFoundation\File\UploadedFile;

#[AsAlias(UserProfilePhotoManagerInterface::class)]
class UserProfilePhotoManager implements UserProfilePhotoManagerInterface
{
    private const int MAX_SIZE_BYTES = 5 * 1024 * 1024;

    private const array ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/webp',
    ];

    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly AuditLogger $auditLogger,
        protected readonly StorageManager $storageManager,
    ) {}

    /**
     * The stored value is the bare filename, and has always been.
     *
     * The prefix lives here and in {@see UserProfilePhotoUrlGenerator},
     * never in the column. Writing the full key into the database would be
     * tidier and would also make every photo taken before that change
     * unreachable, so the split stays.
     */
    protected function keyFor(string $filename): string
    {
        return sprintf('%s/%s', StorageAreaEnum::ProfilePhotos->value, $filename);
    }

    public function upload(User $user, UploadedFile $file): void
    {
        $size = $file->getSize();
        if (false !== $size && $size > self::MAX_SIZE_BYTES) {
            throw new InvalidArgumentException('backend.users.photo.errors.too_large');
        }

        $mimeType = $file->getMimeType();
        if (null === $mimeType || !in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new InvalidArgumentException('backend.users.photo.errors.invalid_type');
        }

        $this->removeFile($user->getProfilePhotoPath());

        $extension = $file->guessExtension() ?? $file->getClientOriginalExtension();
        // Random, and carrying no account id. `profile-photos/` is served
        // anonymously - no guard claims it - so the address is the only lock,
        // and `<user id>-<uniqid>.jpg` locked nothing: the first half is an
        // integer that counts up and the second is the microsecond of the
        // upload. See {@see StoredFileName}.
        $newFilename = StoredFileName::withExtension($extension);

        // PHP already put the upload somewhere on this machine; handing that
        // path over rather than moving it first means one copy instead of two.
        $this->storageManager->active()->writeFromLocalFile(
            $this->keyFor($newFilename),
            $file->getPathname(),
        );

        $user->setProfilePhotoPath($newFilename);
        $this->entityManager->flush();

        $this->auditLogger->log('users', 'photo_uploaded', 'User', $user->getId(), ['filename' => $newFilename]);
    }

    public function delete(User $user): void
    {
        $path = $user->getProfilePhotoPath();
        if (null === $path) {
            return;
        }

        $this->removeFile($path);
        $user->setProfilePhotoPath(null);
        $this->entityManager->flush();

        $this->auditLogger->log('users', 'photo_removed', 'User', $user->getId(), ['filename' => $path]);
    }

    /**
     * Removed from wherever it is rather than from wherever new files go.
     *
     * A photo taken before the storage was switched still sits on the old
     * backend, and asking the active one to delete it would quietly do
     * nothing. Both are asked, which costs a call and cannot leave a file
     * behind.
     */
    private function removeFile(?string $filename): void
    {
        if (null === $filename) {
            return;
        }

        $key = $this->keyFor($filename);

        foreach (StorageDiskEnum::cases() as $disk) {
            $this->storageManager->forDisk($disk)->delete($key);
        }
    }
}
