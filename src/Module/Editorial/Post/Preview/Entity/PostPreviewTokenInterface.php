<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Preview\Entity;

use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeImmutable;

interface PostPreviewTokenInterface
{
    public function getId(): ?int;

    public function getToken(): string;

    public function getPost(): PostInterface;

    public function setPost(PostInterface $post): static;

    public function getCreatedBy(): ?CoreUserInterface;

    public function setCreatedBy(?CoreUserInterface $createdBy): static;

    public function getExpiresAt(): DateTimeImmutable;

    public function setExpiresAt(DateTimeImmutable $expiresAt): static;

    public function getCreatedAt(): DateTimeImmutable;

    public function isUsableAt(DateTimeImmutable $now): bool;
}
