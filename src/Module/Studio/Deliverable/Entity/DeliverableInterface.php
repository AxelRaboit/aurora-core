<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Entity;

use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\Deliverable\Enum\DeliverableFormatEnum;
use Aurora\Module\Studio\Deliverable\Enum\DeliverableScopeEnum;
use Aurora\Module\Studio\Deliverable\Slides\SlideOwnerInterface;
use DateTimeImmutable;

/**
 * Un livrable porte des diapositives quand son format est `slides` ; une page
 * n'en a aucune, cf. {@see SlideOwnerInterface}.
 */
interface DeliverableInterface extends SlideOwnerInterface
{
    public function getId(): ?int;

    public function getSpace(): ?CustomerSpaceInterface;

    /** Vrai pour un livrable de Studio, rattaché à aucun espace. */
    public function isStandalone(): bool;

    /** Une page ou des diapositives, fixé à la création. */
    public function getFormat(): DeliverableFormatEnum;

    /** Un diaporama : des diapositives plutôt qu'une grille. */
    public function isSlides(): bool;

    /** Un modèle de Studio, proposé à la création d'un livrable ; jamais dans un espace. */
    public function isTemplate(): bool;

    public function setTemplate(bool $template): static;

    /** Le client pour qui un livrable de Studio a été écrit ; nul dans un espace. */
    public function getCustomer(): ?CustomerInterface;

    public function setCustomer(?CustomerInterface $customer): static;

    public function getOwner(): ?CoreUserInterface;

    public function setOwner(?CoreUserInterface $owner): static;

    public function getThumbnail(): ?DocumentInterface;

    public function setThumbnail(?DocumentInterface $thumbnail): static;

    public function getCategory(): ?DeliverableCategoryInterface;

    public function setCategory(?DeliverableCategoryInterface $category): static;

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

    /** Quand il a été mis à la corbeille ; nul, il est vivant. */
    public function getDeletedAt(): ?DateTimeImmutable;

    public function setDeletedAt(?DateTimeImmutable $deletedAt): static;

    public function isTrashed(): bool;
}
