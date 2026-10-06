<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Favorite\Manager;

use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;

interface NoteFavoriteManagerInterface
{
    /**
     * Pins, or unpins what was pinned.
     *
     * @return bool true when the item is pinned on return
     */
    public function toggle(CoreUserInterface $user, MarkdownNoteInterface|NoteFolderInterface $item): bool;

    /** When this item was pinned by this person, or nothing. */
    public function favoritedAt(CoreUserInterface $user, MarkdownNoteInterface|NoteFolderInterface $item): ?string;

    /**
     * What a person has pinned, by id, with the time of the action.
     *
     * @return array{notes: array<int, string>, folders: array<int, string>}
     */
    public function mapFor(CoreUserInterface $user): array;
}
