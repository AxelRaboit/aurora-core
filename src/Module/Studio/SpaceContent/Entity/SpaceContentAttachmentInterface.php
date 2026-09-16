<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Entity;

use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use DateTimeImmutable;

interface SpaceContentAttachmentInterface
{
    public function getId(): ?int;

    public function getItem(): SpaceContentItemInterface;

    public function setItem(SpaceContentItemInterface $item): static;

    public function getDocument(): DocumentInterface;

    public function setDocument(DocumentInterface $document): static;

    public function getPosition(): int;

    public function setPosition(int $position): static;

    public function getAuthorLabel(): string;

    public function isFromClient(): bool;

    public function getAuthorUser(): ?CoreUserInterface;

    public function getAuthorLink(): ?SpaceAccessLinkInterface;

    public function addedByStudio(CoreUserInterface $user, string $label): static;

    public function addedByClient(SpaceAccessLinkInterface $link): static;

    public function getCreatedAt(): DateTimeImmutable;
}
