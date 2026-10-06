<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceNote\Service;

use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Notes\Space\Enum\NoteSpaceRoleEnum;
use Aurora\Module\Notes\Space\Manager\NoteSpaceManagerInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Enum\CustomerSpaceMemberRoleEnum;
use DateTimeImmutable;

/**
 * Keeps a client space's notes space in step with its team.
 *
 * **The client space's team is the reference.** Whoever is on the space
 * writes in its notes; whoever leaves it no longer gets in. The lead manages
 * the notes space (they empty its trash), a member writes in it. Nobody else
 * is enrolled, and the notes screen refuses enrolling someone there by hand:
 * an enrolment made over there would be undone at the next save from here.
 *
 * **No privilege is granted.** A team member who does not have the right to
 * use notes (`notes.markdown.use`) is enrolled anyway, and does not see the
 * tab: opening the module to them would be a decision belonging to whoever
 * sets the rights, not a side effect of how a team is made up.
 *
 * Nothing happens as long as the notes space does not exist: it is opened the
 * first time someone needs it, by {@see SpaceNoteSpaceProvider}, which syncs
 * it at that moment.
 */
final readonly class SpaceNoteSpaceSync
{
    public function __construct(
        private NoteSpaceManagerInterface $noteSpaces,
    ) {}

    public function sync(CustomerSpaceInterface $space): void
    {
        $noteSpace = $space->getNoteSpace();

        if (!$noteSpace instanceof NoteSpaceInterface || !$noteSpace->isManaged()) {
            return;
        }

        $members = [];
        foreach ($space->getMembers() as $member) {
            $members[] = [
                'user' => $member->getUser(),
                'role' => self::roleFor($member->getRole()),
            ];
        }

        $this->noteSpaces->syncManaged($noteSpace, $space->getName(), $members);
    }

    /**
     * The client space goes to the trash: its notes space follows it there,
     * still governed by it. Nobody manages or restores it from the notes
     * screen; it comes back when the client space comes back.
     */
    public function trash(CustomerSpaceInterface $space): void
    {
        $noteSpace = $space->getNoteSpace();

        if (!$noteSpace instanceof NoteSpaceInterface || !$noteSpace->isManaged() || $noteSpace->getDeletedAt() instanceof DateTimeImmutable) {
            return;
        }

        $this->noteSpaces->delete($noteSpace);
    }

    /**
     * The client space leaves the trash: its notes space leaves it too, if it
     * is still there and still its own, and gets its team back.
     */
    public function restore(CustomerSpaceInterface $space): void
    {
        $noteSpace = $space->getNoteSpace();

        if (!$noteSpace instanceof NoteSpaceInterface || !$noteSpace->isManaged() || !$noteSpace->getDeletedAt() instanceof DateTimeImmutable) {
            return;
        }

        $this->noteSpaces->restore($noteSpace);
        $this->sync($space);
    }

    /**
     * The client space is destroyed for good: its notes space stays in the
     * trash (or goes there), back to being an ordinary space that
     * administrators can bring back. Notes do not leave with a client.
     */
    public function release(CustomerSpaceInterface $space): void
    {
        $noteSpace = $space->getNoteSpace();

        if (!$noteSpace instanceof NoteSpaceInterface || !$noteSpace->isManaged()) {
            return;
        }

        $this->noteSpaces->releaseManaged($noteSpace);
    }

    /** The lead manages, a member writes. */
    public static function roleFor(CustomerSpaceMemberRoleEnum $role): NoteSpaceRoleEnum
    {
        return match ($role) {
            CustomerSpaceMemberRoleEnum::Lead => NoteSpaceRoleEnum::Manager,
            CustomerSpaceMemberRoleEnum::Member => NoteSpaceRoleEnum::Editor,
        };
    }
}
