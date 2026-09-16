<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\Entity;

use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\CustomerSpace\Enum\CustomerSpaceMemberRoleEnum;
use Doctrine\ORM\Mapping as ORM;

/**
 * Somebody from the studio, on one space.
 *
 * Deliberately not a privilege. Who *may* open a space is answered by
 * `studio.spaces.view` on the account, like every other screen in the backend;
 * this row answers who is *on* it, which is what a client is told and what a
 * list of ten spaces is grouped by. Two sources of truth for access would
 * disagree on the first edge case, and the privilege system is the one that
 * already exists.
 *
 * Both sides cascade on delete, for different reasons. A deleted space takes
 * its membership rows with it because they mean nothing without it. A deleted
 * account does too: the row would otherwise name a person who no longer exists,
 * which is worse than the space losing a name it can be given again.
 */
#[ORM\MappedSuperclass]
#[ORM\UniqueConstraint(name: 'uniq_studio_space_member', columns: ['space_id', 'user_id'])]
abstract class AbstractCustomerSpaceMember implements CustomerSpaceMemberInterface
{
    #[ORM\ManyToOne(targetEntity: CustomerSpaceInterface::class, inversedBy: 'members')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected CustomerSpaceInterface $space;

    #[ORM\ManyToOne(targetEntity: CoreUserInterface::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected CoreUserInterface $user;

    #[ORM\Column(length: 20, enumType: CustomerSpaceMemberRoleEnum::class, options: ['default' => 'member'])]
    protected CustomerSpaceMemberRoleEnum $role = CustomerSpaceMemberRoleEnum::Member;

    abstract public function getId(): ?int;

    public function getSpace(): CustomerSpaceInterface
    {
        return $this->space;
    }

    public function setSpace(CustomerSpaceInterface $space): static
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

    public function getRole(): CustomerSpaceMemberRoleEnum
    {
        return $this->role;
    }

    public function setRole(CustomerSpaceMemberRoleEnum $role): static
    {
        $this->role = $role;

        return $this;
    }
}
