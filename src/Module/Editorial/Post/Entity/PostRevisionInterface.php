<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Entity;

use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeImmutable;

interface PostRevisionInterface
{
    public function getId(): ?int;

    public function getPost(): PostInterface;

    public function setPost(PostInterface $post): static;

    public function getPostVersion(): int;

    public function setPostVersion(int $postVersion): static;

    public function getStatus(): PostStatusEnum;

    public function setStatus(PostStatusEnum $status): static;

    /** @return array<string, mixed> */
    public function getSnapshot(): array;

    /** @param array<string, mixed> $snapshot */
    public function setSnapshot(array $snapshot): static;

    public function getAuthor(): ?CoreUserInterface;

    public function setAuthor(?CoreUserInterface $author): static;

    public function getCreatedAt(): DateTimeImmutable;
}
