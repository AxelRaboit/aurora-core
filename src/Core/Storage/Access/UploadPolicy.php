<?php

declare(strict_types=1);

namespace Aurora\Core\Storage\Access;

use Aurora\Core\Storage\BinaryFileServer;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function in_array;
use function min;

use const UPLOAD_ERR_FORM_SIZE;
use const UPLOAD_ERR_INI_SIZE;

/**
 * What a given surface accepts, as a value rather than a branch.
 *
 * **Sniffed, never declared.** `UploadedFile::getMimeType()` reads the bytes
 * through Symfony's guesser; `getClientMimeType()` repeats what the browser
 * said, and the browser is whoever is uploading. Only the first is consulted.
 *
 * **The allow-list is optional, and the two profiles below differ on purpose.**
 * A list is a promise to have thought of every dangerous format, which is a
 * promise worth making where the caller holds nothing but a secret address,
 * and a promise that breaks a document library where it is the staff's own
 * filing cabinet. A library that refused file types would be a library people
 * work around by renaming things, which is worse than one that accepts them.
 *
 * What makes accepting them safe is at the other end rather than here:
 * {@see BinaryFileServer} sends `nosniff` on every
 * response and hands the browser a download for the handful of types it would
 * otherwise run as a document. The staff profile therefore caps size and
 * nothing else; it is not an oversight, it is where the wall actually is.
 */
final readonly class UploadPolicy
{
    /**
     * @param list<string>|null $allowedMimeTypes null accepts any type
     */
    private function __construct(
        public ?array $allowedMimeTypes,
        public int $maxBytes,
    ) {}

    /**
     * What a client holding a space link may send.
     *
     * Inert formats only - raster images, PDF, the two video containers GED
     * knows how to draw a poster for. SVG is absent by name: it is not a
     * picture, it is a document that can carry script.
     *
     * Twenty-five megabytes takes a phone photo, a short video and a press
     * kit, which is what the surface is for. **It is a cap, not a setting**:
     * `$ceilingBytes` is what the administrator allows for the install as a
     * whole, and a guest gets the lower of the two. Raising the admin number
     * never raises this one, because the person on the other end holds a
     * secret address rather than an account, and how much of the disk they may
     * fill is not a preference.
     *
     * **It is only the third ceiling** anyway: PHP's `upload_max_filesize` and
     * `post_max_size` refuse first, and a default install caps them at 2M and
     * 8M, below a photo from a phone. A deployment that wants any of these
     * numbers to mean something has to raise those to match.
     */
    public const int GUEST_CAP_BYTES = 25 * 1024 * 1024;

    public static function forSpaceGuests(int $ceilingBytes): self
    {
        return new self(
            [
                'image/jpeg',
                'image/png',
                'image/webp',
                'image/gif',
                'image/heic',
                'image/heif',
                'application/pdf',
                'video/mp4',
                'video/quicktime',
            ],
            min($ceilingBytes, self::GUEST_CAP_BYTES),
        );
    }

    /**
     * What the document library accepts from somebody holding
     * `ged.documents.create`.
     *
     * Any type, and a ceiling. The account is one a person granted, the
     * execution vector is closed when the file is served, and the thing left
     * to protect is the disk - which until 2026-09-16 nothing protected at
     * all: neither endpoint checked a size.
     *
     * The ceiling is the administrator's, read from `max_upload_size_mb`.
     * Until 2026-09-16 it was 100MB hard-coded here while that setting sat in
     * the panel being read by nobody, which meant the number somebody typed
     * was not the number that applied.
     */
    public static function forStaffDocuments(int $ceilingBytes): self
    {
        return new self(null, $ceilingBytes);
    }

    /**
     * Null when the file may be filed, otherwise why not.
     */
    public function refusalFor(UploadedFile $file): ?UploadRefusalEnum
    {
        // Checked before `isValid()`, because these two *are* an invalid file
        // and answering "it did not complete" would send somebody to retry the
        // same one forever. Symfony's own test browser sets them too, so the
        // answer is the same whether PHP's ceiling or ours bit first.
        if (in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return UploadRefusalEnum::TooLarge;
        }

        if (!$file->isValid()) {
            return UploadRefusalEnum::Broken;
        }

        if ($file->getSize() > $this->maxBytes) {
            return UploadRefusalEnum::TooLarge;
        }

        if (null !== $this->allowedMimeTypes
            && !in_array((string) $file->getMimeType(), $this->allowedMimeTypes, true)
        ) {
            return UploadRefusalEnum::TypeRefused;
        }

        return null;
    }
}
