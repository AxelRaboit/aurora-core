<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Entity;

use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\PostType\Entity\PostTypeInterface;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTermInterface;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeImmutable;
use Doctrine\Common\Collections\Collection;

interface PostInterface
{
    public function getId(): ?int;

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

    public function getPostType(): PostTypeInterface;

    public function setPostType(PostTypeInterface $postType): static;

    public function getFeaturedMedia(): ?DocumentInterface;

    public function setFeaturedMedia(?DocumentInterface $featuredMedia): static;

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
