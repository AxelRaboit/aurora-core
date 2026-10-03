<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Entity;

use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\Post\Enum\PostVisibilityEnum;
use Aurora\Module\Editorial\Post\Enum\ThumbnailFitEnum;
use Aurora\Module\Editorial\PostType\Entity\PostTypeInterface;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTermInterface;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeImmutable;
use Doctrine\Common\Collections\Collection;

interface PostInterface
{
    public function getId(): ?int;

    /** From TimestampableTrait - declared here so callers can type on the interface. */
    public function getCreatedAt(): DateTimeImmutable;

    public function getUpdatedAt(): DateTimeImmutable;

    public function getReference(): ?string;

    public function setReference(?string $reference): static;

    public function getVersion(): int;

    public function getStatus(): PostStatusEnum;

    public function setStatus(PostStatusEnum $status): static;

    public function isPublished(): bool;

    public function getVisibility(): PostVisibilityEnum;

    public function setVisibility(PostVisibilityEnum $visibility): static;

    /**
     * Published, not trashed, and offered by the site. What every listing,
     * menu and index of the site asks; a publication shared by link answers
     * no here and yes to {@see isPublished()}.
     */
    public function isOnSite(): bool;

    /** @return array{preparedFor: ?string, showDate: bool, showLogo: bool} */
    public function getReadingPage(): array;

    /** @param array<string, mixed> $readingPage normalised on the way in */
    public function setReadingPage(array $readingPage): static;

    public function getPublishedAt(): ?DateTimeImmutable;

    public function setPublishedAt(?DateTimeImmutable $publishedAt): static;

    public function getScheduledAt(): ?DateTimeImmutable;

    public function setScheduledAt(?DateTimeImmutable $scheduledAt): static;

    public function getDeletedAt(): ?DateTimeImmutable;

    public function setDeletedAt(?DateTimeImmutable $deletedAt): static;

    public function isTrashed(): bool;

    public function isCommentsEnabled(): bool;

    public function setCommentsEnabled(bool $commentsEnabled): static;

    public function isShareEnabled(): bool;

    public function setShareEnabled(bool $shareEnabled): static;

    /** @return list<array{type: string, label: ?string, url: ?string, color: ?string}>|null */
    public function getShareLinks(): ?array;

    /** @param list<array{type: string, label: ?string, url: ?string, color: ?string}>|null $shareLinks */
    public function setShareLinks(?array $shareLinks): static;

    public function isUsefulLinksEnabled(): bool;

    public function setUsefulLinksEnabled(bool $usefulLinksEnabled): static;

    /** @return list<array{label: string, url: string, color: ?string}>|null null follows the site's list */
    public function getUsefulLinks(): ?array;

    /** @param list<array{label: string, url: string, color: ?string}>|null $usefulLinks */
    public function setUsefulLinks(?array $usefulLinks): static;

    public function isTitleVisible(): bool;

    public function setTitleVisible(bool $titleVisible): static;

    public function getPosition(): ?int;

    public function setPosition(?int $position): static;

    /**
     * The banner's design, shared by every language. Its words live on each
     * translation and join back by item id.
     *
     * @return array<string, mixed>
     */
    public function getBannerLayout(): array;

    /** @param array<string, mixed> $bannerLayout */
    public function setBannerLayout(array $bannerLayout): static;

    /**
     * The content grid's arrangement, shared by every language. What each zone
     * holds lives on each translation and joins back by zone id.
     *
     * @return array<string, mixed>
     */
    public function getGridLayout(): array;

    /** @param array<string, mixed> $gridLayout */
    public function setGridLayout(array $gridLayout): static;

    /** @return array<string, mixed> */
    public function getGalleryLayout(): array;

    /** @param array<string, mixed> $galleryLayout */
    public function setGalleryLayout(array $galleryLayout): static;

    /**
     * This publication's own colour for the topbar, `#rrggbb`, or null to keep
     * whatever the active theme paints there.
     */
    public function getHeaderColor(): ?string;

    public function setHeaderColor(?string $headerColor): static;

    /** This publication's own footer colour, or null to keep the theme's. */
    public function getFooterColor(): ?string;

    public function setFooterColor(?string $footerColor): static;

    /** This publication's own page background, or null to keep the theme's. */
    public function getBackgroundColor(): ?string;

    public function setBackgroundColor(?string $backgroundColor): static;

    /** This publication's own accent colour, or null to keep the theme's. */
    public function getAccentColor(): ?string;

    public function setAccentColor(?string $accentColor): static;

    /**
     * The other theme colours this publication repaints (text, lines, cards,
     * headings, figures), keyed as in the theme's config. A missing key keeps
     * the theme's.
     *
     * @return array<string, string>
     */
    public function getColorOverrides(): array;

    /** @param array<string, mixed> $colorOverrides */
    public function setColorOverrides(array $colorOverrides): static;

    /** Whether the topbar and the footer take this publication's accent and hovers. */
    public function isChromeFollowsPage(): bool;

    public function setChromeFollowsPage(bool $chromeFollowsPage): static;

    /** `accent`, `neutral` or `custom` for this publication, or null to keep the theme's. */
    public function getHighlight(): ?string;

    public function setHighlight(?string $highlight): static;

    public function getHighlightColor(): ?string;

    public function setHighlightColor(?string $highlightColor): static;

    /** The hover colour of this publication's card in a listing, or null to follow the listing page. */
    public function getCardHighlightColor(): ?string;

    public function getPostType(): PostTypeInterface;

    public function setPostType(PostTypeInterface $postType): static;

    /**
     * The picture that stands for this publication wherever it is listed. Not
     * rendered at the top of the page any more - the custom header does that.
     */
    public function getThumbnail(): ?DocumentInterface;

    public function setThumbnail(?DocumentInterface $thumbnail): static;

    public function getThumbnailFit(): ThumbnailFitEnum;

    public function setThumbnailFit(ThumbnailFitEnum $thumbnailFit): static;

    public function getThumbnailFocalX(): ?float;

    public function getThumbnailFocalY(): ?float;

    /** Both or neither: half a focal point is not a position. */
    public function setThumbnailFocal(?float $x, ?float $y): static;

    public function getUnpublishAt(): ?DateTimeImmutable;

    public function setUnpublishAt(?DateTimeImmutable $unpublishAt): static;

    public function getReviewNote(): ?string;

    public function setReviewNote(?string $reviewNote): static;

    public function getReviewedAt(): ?DateTimeImmutable;

    public function setReviewedAt(?DateTimeImmutable $reviewedAt): static;

    public function getReviewedBy(): ?CoreUserInterface;

    public function setReviewedBy(?CoreUserInterface $reviewedBy): static;

    public function getAuthor(): ?CoreUserInterface;

    public function setAuthor(?CoreUserInterface $author): static;

    /** @return Collection<string, PostTranslationInterface> */
    public function getTranslations(): Collection;

    public function getTranslation(string $locale): ?PostTranslationInterface;

    public function translate(string $locale): PostTranslationInterface;

    /** @return Collection<int, TaxonomyTermInterface> */
    public function getTerms(): Collection;

    public function addTerm(TaxonomyTermInterface $term): static;

    public function removeTerm(TaxonomyTermInterface $term): static;

    /** @return Collection<int, PostRevisionInterface> */
    public function getRevisions(): Collection;

    /** @return Collection<int, PostInterface> */
    public function getRelatedPosts(): Collection;

    public function addRelatedPost(PostInterface $post): static;

    public function removeRelatedPost(PostInterface $post): static;
}
