<?php

declare(strict_types=1);

namespace Aurora\Core\Storage\Enum;

use Aurora\Core\Storage\Access\UploadAccessDecider;

/**
 * The top-level folders Aurora stores things under.
 *
 * A case's value is a path prefix, so it is part of where every existing file
 * lives: renaming one strands everything written before the rename. Add and
 * remove, never rename.
 *
 * Four cases were removed in 0.9.132 - `media`, `ocr`, `photo` and `users` -
 * because nothing named them any more. The first three belonged to modules
 * that are gone; `users` never matched reality, since profile photos have
 * always gone to `profile-photos`. Only their own test still asserted their
 * values, which is the shape of a constant nobody uses.
 */
enum StorageAreaEnum: string
{
    case Ged = 'ged';

    case ProfilePhotos = 'profile-photos';

    case Contracts = 'contracts';

    /**
     * Images pasted into a markdown note.
     *
     * Added in 0.9.188 with the value the files already had on disk, so
     * nothing written before it is stranded. It exists so the area can be
     * claimed: `MarkdownNoteImageService` keeps its files outside the public
     * document root on purpose - they are per-user and read through a
     * controller that checks whose they are - but `/uploads/{path}` reached
     * them anyway through the catch-all, and with no guard to say otherwise
     * {@see UploadAccessDecider} left them
     * anonymous. Measured on 2026-09-16: a file under this prefix answered 200
     * with no session at all.
     */
    case NotesMarkdown = 'notes-markdown';
}
