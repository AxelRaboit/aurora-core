<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Share\Manager;

use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Share\Entity\MarkdownNoteMemberInterface;
use Aurora\Module\Notes\Share\Enum\NoteMemberRoleEnum;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;

interface MarkdownNoteMemberManagerInterface
{
    /**
     * Hands a note to somebody, or changes the role they already had.
     *
     * One call for both because the screen has one gesture: you pick a person
     * and a role. Whether that person was already on the list is the
     * database's business, not the caller's.
     */
    public function setMember(MarkdownNoteInterface $note, CoreUserInterface $user, NoteMemberRoleEnum $role): MarkdownNoteMemberInterface;

    /** Takes the note back. False when they were not on the list. */
    public function removeMember(MarkdownNoteInterface $note, CoreUserInterface $user): bool;
}
