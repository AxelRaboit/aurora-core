<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Space\Entity;

use Aurora\Module\Notes\Space\Enum\NoteSpaceRoleEnum;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Entity\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * A person who is a member of a space, with their role.
 *
 * Both sides cascade: without the space, the membership means nothing, and
 * without the account it would name somebody who no longer exists.
 */
#[ORM\MappedSuperclass]
#[ORM\UniqueConstraint(name: 'uniq_notes_space_member', columns: ['space_id', 'user_id'])]
abstract class AbstractNoteSpaceMember implements NoteSpaceMemberInterface
{
    #[ORM\ManyToOne(targetEntity: NoteSpaceInterface::class, inversedBy: 'members')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected NoteSpaceInterface $space;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected CoreUserInterface $user;

    #[ORM\Column(length: 16, enumType: NoteSpaceRoleEnum::class, options: ['default' => 'reader'])]
    protected NoteSpaceRoleEnum $role = NoteSpaceRoleEnum::Reader;

    public function getSpace(): NoteSpaceInterface
    {
        return $this->space;
    }

    public function setSpace(NoteSpaceInterface $space): static
    {
        $this->space = $space;

        return $this;
    }

    public function getUser(): CoreUserInterface
    {
        return $this->user;
    }

    public function setUser(CoreUserInterface $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getRole(): NoteSpaceRoleEnum
    {
        return $this->role;
    }

    public function setRole(NoteSpaceRoleEnum $role): static
    {
        $this->role = $role;

        return $this;
    }
}
