<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Entity;

use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\Deliverable\Enum\DeliverableFormatEnum;
use Aurora\Module\Studio\Deliverable\Enum\DeliverableScopeEnum;
use Aurora\Module\Studio\Deliverable\Slides\Entity\SlideInterface;
use Aurora\Module\Studio\Deliverable\Slides\Enum\DeckThemeEnum;
use DateTimeImmutable;
use Doctrine\Common\Collections\Collection;

/**
 * A deliverable carries slides when its format is `slides`; a page has none.
 * Everything that writes, draws or counts slides (`SlidesManager`,
 * `DeckAppearance`, `DeckPictures`, `DeckFonts`, the serializer) talks to a
 * deliverable: since presentations became deliverables, there is no other
 * owner.
 *
 * The theme and its adjustments are called `slideTheme`/`slideStyle` because
 * a deliverable already has its appearance, the page's: the two must not be
 * confused.
 */
interface DeliverableInterface
{
    public function getId(): ?int;

    /** @return Collection<int, SlideInterface> */
    public function getSlides(): Collection;

    public function addSlide(SlideInterface $slide): static;

    public function removeSlide(SlideInterface $slide): static;

    /** The theme the slides are drawn in. */
    public function getSlideTheme(): DeckThemeEnum;

    public function setSlideTheme(DeckThemeEnum $theme): static;

    /**
     * What the slides adjust in their theme, see `DeckStyleNormalizer`.
     *
     * @return array<string, mixed>
     */
    public function getSlideStyle(): array;

    /** @param array<string, mixed> $style */
    public function setSlideStyle(array $style): static;

    public function getSpace(): ?CustomerSpaceInterface;

    /** True for a Studio deliverable, attached to no space. */
    public function isStandalone(): bool;

    /** A page or slides, set at creation. */
    public function getFormat(): DeliverableFormatEnum;

    /** A slideshow: slides rather than a grid. */
    public function isSlides(): bool;

    /** A Studio template, offered when creating a deliverable; never in a space. */
    public function isTemplate(): bool;

    public function setTemplate(bool $template): static;

    /** The client a Studio deliverable was written for; null in a space. */
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

    /** When it was moved to the trash; null, it is live. */
    public function getDeletedAt(): ?DateTimeImmutable;

    public function setDeletedAt(?DateTimeImmutable $deletedAt): static;

    public function isTrashed(): bool;
}
