<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Entity;

use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use DateTimeImmutable;

interface SpaceContentCommentInterface
{
    public function getId(): ?int;

    public function getItem(): SpaceContentItemInterface;

    public function setItem(SpaceContentItemInterface $item): static;

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
