<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Access;

use Aurora\Core\Storage\Access\UploadAccessDecider;
use Aurora\Core\Storage\Access\UploadAccessEnum;
use Aurora\Core\Storage\Access\UploadAccessGuardInterface;
use Aurora\Core\Storage\Enum\StorageAreaEnum;
use Aurora\Module\Notes\Markdown\Service\MarkdownNoteImageService;

use function str_starts_with;

/**
 * A note's images belong to the person who wrote the note.
 *
 * {@see MarkdownNoteImageService} says so
 * in its own docblock and files them per user, outside the public document
 * root, to be read through a controller that checks whose they are. The
 * catch-all at `/uploads/{path}` went round all of it: the prefix sits under
 * `app.upload_dir`, no guard claimed it, and
 * {@see UploadAccessDecider}'s documented default
 * left it anonymous. Measured on 2026-09-16, before this class existed: 200,
 * no session.
 *
 * **`Denied` and never `Restricted`**, for the reason the other guards give:
 * `/uploads/…` is handled by the front firewall, where no backend identity
 * exists to test, so `Restricted` would be a question whose answer is always
 * no. The owner reads their image through `backend_notes_markdown_images_serve`,
 * which is under the prefix the admin firewall covers and is where the
 * per-user check actually happens.
 */
final readonly class NotesImageUploadAccessGuard implements UploadAccessGuardInterface
{
    public function supports(string $key): bool
    {
        return str_starts_with($key, StorageAreaEnum::NotesMarkdown->value.'/');
    }

    public function decide(string $key): UploadAccessEnum
    {
        return UploadAccessEnum::Denied;
    }
}
