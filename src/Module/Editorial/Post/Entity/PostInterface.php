<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Entity;

use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
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

    public function getPublishedAt(): ?DateTimeImmutable;

    public function setPublishedAt(?DateTimeImmutable $publishedAt): static;

    public function getScheduledAt(): ?DateTimeImmutable;

    public function setScheduledAt(?DateTimeImmutable $scheduledAt): static;

    public function getDeletedAt(): ?DateTimeImmutable;

    public function setDeletedAt(?DateTimeImmutable $deletedAt): static;

    public function isTrashed(): bool;

    public function isCommentsEnabled(): bool;

    public function setCommentsEnabled(bool $commentsEnabled): static;

    public function isTitleVisible(): bool;

    public function setTitleVisible(bool $titleVisible): static;

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
