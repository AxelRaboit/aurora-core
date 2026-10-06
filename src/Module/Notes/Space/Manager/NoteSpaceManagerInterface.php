<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Space\Manager;

use Aurora\Module\Notes\Space\Dto\NoteSpaceInputInterface;
use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Notes\Space\Entity\NoteSpaceMemberInterface;
use Aurora\Module\Notes\Space\Enum\NoteSpaceRoleEnum;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;

interface NoteSpaceManagerInterface
{
    /** A shared space, owned by the person who creates it. */
    public function create(CoreUserInterface $owner, NoteSpaceInputInterface $input): NoteSpaceInterface;

    /**
     * A shared space configured by something else (`$managedBy`): without an
     * owner, open to its members only.
     */
    public function createManaged(string $name, string $managedBy): NoteSpaceInterface;

    /**
     * Resets the name and the members of a space configured elsewhere to the
     * given ones: whoever is no longer there is removed, whoever arrives is
     * added.
     *
     * @param list<array{user: CoreUserInterface, role: NoteSpaceRoleEnum}> $members
     */
    public function syncManaged(NoteSpaceInterface $space, string $name, array $members): void;

    /**
     * What configured the space is gone: it goes to the trash and becomes an
     * ordinary space again, which the administrators take over.
     */
    public function releaseManaged(NoteSpaceInterface $space): void;

    public function update(NoteSpaceInterface $space, NoteSpaceInputInterface $input): void;

    /**
     * Removes a shared space, with everything it holds, without destroying
     * anything: it disappears for everybody and can come back.
     */
    public function delete(NoteSpaceInterface $space): void;

    public function restore(NoteSpaceInterface $space): void;

    /**
     * Opens the space for reading on the web, at this address. The address
     * is already validated and free: the controller checked it.
     */
    public function publish(NoteSpaceInterface $space, string $slug, bool $indexable): void;

    /** Closes the space to the web; its address stays its own for next time. */
    public function unpublish(NoteSpaceInterface $space): void;

    /** Adds a person as a member, or changes their role if they already are one. */
    public function setMember(NoteSpaceInterface $space, CoreUserInterface $user, NoteSpaceRoleEnum $role): NoteSpaceMemberInterface;

    /** @return bool false when the person was not a member */
    public function removeMember(NoteSpaceInterface $space, CoreUserInterface $user): bool;
}
