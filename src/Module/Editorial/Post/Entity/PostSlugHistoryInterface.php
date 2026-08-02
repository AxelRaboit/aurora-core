<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Entity;

interface PostSlugHistoryInterface
{
    public function getId(): ?int;

    public function getPost(): PostInterface;

    public function setPost(PostInterface $post): static;

    public function getLocale(): string;

    public function setLocale(string $locale): static;

    public function getSlug(): string;

    public function setSlug(string $slug): static;
}
