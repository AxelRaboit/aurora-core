<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Entity;

use Aurora\Core\Timestampable\TimestampableInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Doctrine\Common\Collections\Collection;

interface MarkdownNoteInterface extends TimestampableInterface
{
    public function getId(): ?int;

    public function getUser(): CoreUserInterface;

    public function setUser(CoreUserInterface $user): static;

    public function getParent(): ?self;

    public function setParent(?self $parent): static;

    /** @return Collection<int, MarkdownNoteInterface> */
    public function getChildren(): Collection;

    public function getTitle(): ?string;

    public function setTitle(?string $title): static;

    public function getContent(): ?string;

    public function setContent(?string $content): static;

    /** @return list<string> */
    public function getTags(): array;

    /** @param list<string> $tags */
    public function setTags(array $tags): static;

    public function getPosition(): int;

    public function setPosition(int $position): static;
}
