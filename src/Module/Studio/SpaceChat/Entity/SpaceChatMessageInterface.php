<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceChat\Entity;

use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use DateTimeImmutable;

interface SpaceChatMessageInterface
{
    public function getId(): ?int;

    public function getSpace(): CustomerSpaceInterface;

    public function setSpace(CustomerSpaceInterface $space): static;

    public function getBody(): string;

    public function setBody(string $body): static;

    public function getAuthorLabel(): string;

    public function isFromClient(): bool;

    public function getAuthorUser(): ?CoreUserInterface;

    public function getAuthorLink(): ?SpaceAccessLinkInterface;

    public function writtenByStudio(CoreUserInterface $user, string $label): static;

    public function writtenByClient(SpaceAccessLinkInterface $link): static;

    public function getCreatedAt(): DateTimeImmutable;
}
