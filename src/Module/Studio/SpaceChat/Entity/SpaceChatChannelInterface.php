<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceChat\Entity;

use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceChat\Enum\SpaceChatChannelKindEnum;
use DateTimeImmutable;
use Doctrine\Common\Collections\Collection;

interface SpaceChatChannelInterface
{
    public function getId(): ?int;

    public function getSpace(): CustomerSpaceInterface;

    public function setSpace(CustomerSpaceInterface $space): static;

    public function getName(): string;

    public function setName(string $name): static;

    public function getKind(): SpaceChatChannelKindEnum;

    public function setKind(SpaceChatChannelKindEnum $kind): static;

    public function getPosition(): int;

    public function setPosition(int $position): static;

    public function isOpenToClient(): bool;

    public function setOpenToClient(bool $openToClient): static;

    /** @return Collection<int, SpaceChatChannelMemberInterface> */
    public function getMembers(): Collection;

    public function addMember(SpaceChatChannelMemberInterface $member): static;

    public function removeMember(SpaceChatChannelMemberInterface $member): static;

    /** Whether this account is one of the people in the room. */
    public function holds(CoreUserInterface $user): bool;

    /** Whether this address is one of the people in the room. */
    public function holdsLink(SpaceAccessLinkInterface $link): bool;

    public function getCreatedAt(): DateTimeImmutable;
}
