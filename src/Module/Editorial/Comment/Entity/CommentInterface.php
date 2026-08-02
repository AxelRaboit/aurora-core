<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Comment\Entity;

use Aurora\Module\Editorial\Comment\Enum\CommentStatusEnum;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use DateTimeImmutable;
use Doctrine\Common\Collections\Collection;

interface CommentInterface
{
    public function getId(): ?int;

    public function getReference(): ?string;

    public function setReference(?string $reference): static;

    public function getPost(): PostInterface;

    public function setPost(PostInterface $post): static;

    public function getAuthorName(): string;

    public function setAuthorName(string $authorName): static;

    public function getAuthorEmail(): string;

    public function setAuthorEmail(string $authorEmail): static;

    public function getContent(): string;

    public function setContent(string $content): static;

    public function getStatus(): CommentStatusEnum;

    public function setStatus(CommentStatusEnum $status): static;

    public function isApproved(): bool;

    public function getCreatedAt(): DateTimeImmutable;

    public function getParent(): ?CommentInterface;

    public function setParent(?CommentInterface $parent): static;

    /** @return Collection<int, CommentInterface> */
    public function getReplies(): Collection;
}
