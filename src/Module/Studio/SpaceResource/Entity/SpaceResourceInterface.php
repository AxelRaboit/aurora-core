<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceResource\Entity;

use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceResource\Enum\SpaceResourceKindEnum;
use DateTimeInterface;

interface SpaceResourceInterface
{
    public function getId(): ?int;

    public function getSpace(): CustomerSpaceInterface;

    public function setSpace(CustomerSpaceInterface $space): static;

    public function getKind(): SpaceResourceKindEnum;

    public function setKind(SpaceResourceKindEnum $kind): static;

    public function getLabel(): string;

    public function setLabel(string $label): static;

    public function getUrl(): ?string;

    public function setUrl(?string $url): static;

    public function getBody(): ?string;

    public function setBody(?string $body): static;

    public function getEmail(): ?string;

    public function setEmail(?string $email): static;

    public function getPhone(): ?string;

    public function setPhone(?string $phone): static;

    public function isVisibleToClient(): bool;

    public function setVisibleToClient(bool $visibleToClient): static;

    public function getPosition(): int;

    public function setPosition(int $position): static;

    public function getCreatedAt(): DateTimeInterface;

    public function getUpdatedAt(): DateTimeInterface;
}
