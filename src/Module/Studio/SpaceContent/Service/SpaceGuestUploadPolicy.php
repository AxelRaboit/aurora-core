<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

use function in_array;

use const UPLOAD_ERR_FORM_SIZE;
use const UPLOAD_ERR_INI_SIZE;

/**
 * What a client holding a link is allowed to send.
 *
 * The staff uploader has no such policy and does not need one: reaching it
 * costs `ged.documents.create`, which is an account somebody granted. This one
 * is reachable by anybody holding a secret address, so the question changes
 * from "is this a sensible file" to "what happens if this file is hostile".
 *
 * **Sniffed, never declared.** `UploadedFile::getMimeType()` reads the bytes
 * through Symfony's guesser; `getClientMimeType()` repeats what the browser
 * said. Only the first is checked here, because the second is written by
 * whoever is uploading.
 *
 * **SVG is refused, and it is the reason this class exists.** An SVG is not a
 * picture, it is a document that may carry script, and GED serves files with
 * the type they claim and no `Content-Disposition: attachment` - so an SVG
 * accepted here would run on the application's own origin. Everything else in
 * the list is inert when served: raster images, PDF, and the two video
 * containers GED already knows how to make a poster for.
 *
 * **An allow-list and not a deny-list**, because a deny-list is a promise to
 * have thought of everything, and the interesting formats are the ones nobody
 * listed.
 *
 * The ceiling is a constant rather than a setting on purpose: a number a
 * client project can raise is a number somebody raises to make one upload work
 * and never lowers. Twenty-five megabytes takes a phone photo, a short video
 * and a press kit, which is what this surface is for.
 *
 * **It is only the second ceiling.** PHP's own `upload_max_filesize` and
 * `post_max_size` refuse first, and a default install caps them at 2M and 8M -
 * below a photo from a phone. A deployment that wants the number above to mean
 * anything has to raise those to match; until it does, this class never sees
 * the file and the error code is all there is to go on. Which is why the two
 * size errors are folded into one message below: the reader needs "that file
 * is too big", not a lesson in which limit bit first.
 */
final readonly class SpaceGuestUploadPolicy
{
    public const int MAX_BYTES = 25 * 1024 * 1024;

    /**
     * @var list<string>
     */
    public const array ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/heic',
        'image/heif',
        'application/pdf',
        'video/mp4',
        'video/quicktime',
    ];

    /**
     * Null when the file may be filed, otherwise the translation key saying
     * why not.
     *
     * A key rather than a boolean because the two refusals need different
     * sentences: "that kind of file is not accepted" and "that file is too
     * big" send somebody to two different fixes, and a single "upload failed"
     * sends them to neither.
     */
    public function refusalFor(UploadedFile $file): ?string
    {
        // Checked before `isValid()`, because these two *are* an invalid file
        // and answering "the upload did not complete" would send somebody to
        // retry the same file forever.
        if (in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return 'studio.public.space.errors.upload_too_large';
        }

        if (!$file->isValid()) {
            return 'studio.public.space.errors.upload_failed';
        }

        if ($file->getSize() > self::MAX_BYTES) {
            return 'studio.public.space.errors.upload_too_large';
        }

        if (!in_array((string) $file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
            return 'studio.public.space.errors.upload_type_refused';
        }

        return null;
    }
}
