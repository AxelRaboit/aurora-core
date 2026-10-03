<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Entity;

use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\Deliverable\Enum\DeliverableScopeEnum;
use DateTimeImmutable;

interface DeliverableInterface
{
    public function getId(): ?int;

    public function getSpace(): ?CustomerSpaceInterface;

    /** Vrai pour un livrable de Studio, rattaché à aucun espace. */
    public function isStandalone(): bool;

    public function getOwner(): ?CoreUserInterface;

    public function setOwner(?CoreUserInterface $owner): static;

    public function getScope(): DeliverableScopeEnum;

    public function setScope(DeliverableScopeEnum $scope): static;

    public function getTitle(): string;

    public function setTitle(string $title): static;

    public function getSummary(): ?string;

    public function setSummary(?string $summary): static;

    public function getLocale(): string;

    public function setLocale(string $locale): static;

    /** @return array<string, mixed> */
    public function getGridLayout(): array;

    /** @param array<string, mixed> $gridLayout */
    public function setGridLayout(array $gridLayout): static;

    /** @return array<string, mixed> */
    public function getGridContent(): array;

    /** @param array<string, mixed> $gridContent */
    public function setGridContent(array $gridContent): static;

    /** @return array<string, mixed> */
    public function getAppearance(): array;

    /** @param array<string, mixed> $appearance */
    public function setAppearance(array $appearance): static;

    /** @return array<string, mixed> */
    public function getReadingHeader(): array;

    /** @param array<string, mixed> $readingHeader */
    public function setReadingHeader(array $readingHeader): static;

    public function isVisibleToClient(): bool;

    public function setVisibleToClient(bool $visibleToClient): static;

    public function getCreatedAt(): DateTimeImmutable;

    public function getUpdatedAt(): DateTimeImmutable;

    public function touch(): static;
}
