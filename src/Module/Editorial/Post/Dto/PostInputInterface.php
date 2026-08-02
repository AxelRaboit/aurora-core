<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Dto;

interface PostInputInterface
{
    public function getPostTypeId(): int;

    public function getStatus(): string;

    public function getFeaturedMediaId(): ?int;

    /** @return list<int> */
    public function getTermIds(): array;

    /** @return array<string, PostTranslationInput> */
    public function getTranslations(): array;

    /** @return list<int> */
    public function getRelatedPostIds(): array;

    public function getScheduledAt(): ?string;

    /** Version the editor loaded, for optimistic locking. Null skips the check. */
    public function getVersion(): ?int;

    /** Save anyway, discarding the other editor's version. */
    public function isForce(): bool;

    public function isCommentsEnabled(): bool;

    /** Returns a copy with a different status, leaving everything else alone. */
    public function withStatus(string $status): self;
}
