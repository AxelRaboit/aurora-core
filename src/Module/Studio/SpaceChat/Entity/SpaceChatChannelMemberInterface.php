<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceChat\Entity;

use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use DateTimeImmutable;

interface SpaceChatChannelMemberInterface
{
    public function getId(): ?int;

    public function getChannel(): SpaceChatChannelInterface;

    public function setChannel(SpaceChatChannelInterface $channel): static;

    public function getUser(): ?CoreUserInterface;

    public function setUser(?CoreUserInterface $user): static;

    public function getLink(): ?SpaceAccessLinkInterface;

    public function setLink(?SpaceAccessLinkInterface $link): static;

    public function getLabel(): string;

    public function setLabel(string $label): static;

    public function isFromClient(): bool;

    public function getCreatedAt(): DateTimeImmutable;
}
