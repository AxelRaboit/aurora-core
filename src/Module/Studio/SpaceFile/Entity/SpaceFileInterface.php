<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceFile\Entity;

use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use DateTimeImmutable;

interface SpaceFileInterface
{
    public function getId(): ?int;

    public function getSpace(): CustomerSpaceInterface;

    public function setSpace(CustomerSpaceInterface $space): static;

    public function getDocument(): DocumentInterface;

    public function setDocument(DocumentInterface $document): static;

    public function getAuthorLabel(): string;

    public function isFromClient(): bool;

    public function getAuthorUser(): ?CoreUserInterface;

    public function getAuthorLink(): ?SpaceAccessLinkInterface;

    public function addedByStudio(CoreUserInterface $user, string $label): static;

    public function addedByClient(SpaceAccessLinkInterface $link): static;

    public function getCreatedAt(): DateTimeImmutable;
}
