<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Entity;

use Aurora\Module\Ged\Document\Entity\DocumentInterface;

interface PostTranslationInterface
{
    public function getId(): ?int;

    public function getLocale(): string;

    public function setLocale(string $locale): static;

    public function getTitle(): ?string;

    public function setTitle(?string $title): static;

    public function getSlug(): ?string;

    public function setSlug(?string $slug): static;

    public function getDescription(): ?string;

    public function setDescription(?string $description): static;

    /**
     * The banner's words for this locale, keyed by layout item id. The design
     * they belong to lives on the post.
     *
     * @return array<string, mixed>
     */
    public function getBanner(): array;

    /** @param array<string, mixed> $banner */
    public function setBanner(array $banner): static;

    /**
     * What each content-grid zone holds in this locale, keyed by zone id.
     *
     * @return array<string, mixed>
     */
    public function getGrid(): array;

    /** @param array<string, mixed> $grid */
    public function setGrid(array $grid): static;

    /** @return list<array{id?: string, type: string, data: array<string, mixed>}> */
    public function getBlocks(): array;

    /** @param list<array{id?: string, type: string, data: array<string, mixed>}> $blocks */
    public function setBlocks(array $blocks): static;

    public function getMetaTitle(): ?string;

    public function setMetaTitle(?string $metaTitle): static;

    public function getMetaDescription(): ?string;

    public function setMetaDescription(?string $metaDescription): static;

    /** @return array<string, mixed> */
    public function getCustomFields(): array;

    /** @param array<string, mixed> $customFields */
    public function setCustomFields(array $customFields): static;

    public function getOgImage(): ?DocumentInterface;

    public function setOgImage(?DocumentInterface $ogImage): static;

    public function getCanonicalUrl(): ?string;

    public function setCanonicalUrl(?string $canonicalUrl): static;

    public function isNoindex(): bool;

    public function setNoindex(bool $noindex): static;

    public function getFocusKeyword(): ?string;

    public function setFocusKeyword(?string $focusKeyword): static;

    /** @return array<string, mixed>|null */
    public function getJsonLd(): ?array;

    /** @param array<string, mixed>|null $jsonLd */
    public function setJsonLd(?array $jsonLd): static;

    public function getSearchContent(): ?string;

    public function setSearchContent(?string $searchContent): static;

    public function getPost(): PostInterface;

    public function setPost(PostInterface $post): static;
}
