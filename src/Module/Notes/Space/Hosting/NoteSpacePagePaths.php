<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Space\Hosting;

use function str_replace;

/**
 * The addresses of a hosted space's pages, as its host draws them.
 *
 * Page addresses, not API routes: what the address bar shows while somebody
 * reads a note, what a notification or a search result links to. The API
 * stays the engine's own (`suite_notes_*`), called with the host's parameter
 * ({@see NoteSpaceScope::HOST_PARAMETER}).
 *
 * `note` and `folder` carry `__id__`, like every path template of the suite.
 */
final readonly class NoteSpacePagePaths
{
    public const string PLACEHOLDER = '__id__';

    public function __construct(
        public string $library,
        public string $note,
        public string $folder,
    ) {}

    public function noteUrl(int $noteId): string
    {
        return str_replace(self::PLACEHOLDER, (string) $noteId, $this->note);
    }

    public function folderUrl(int $folderId): string
    {
        return str_replace(self::PLACEHOLDER, (string) $folderId, $this->folder);
    }

    /** @return array{library: string, note: string, folder: string} */
    public function toArray(): array
    {
        return ['library' => $this->library, 'note' => $this->note, 'folder' => $this->folder];
    }
}
