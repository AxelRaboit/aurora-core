<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Comment\Entity;

use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeImmutable;

interface NoteCommentInterface
{
    public function getId(): ?int;

    public function getNote(): MarkdownNoteInterface;

    public function setNote(MarkdownNoteInterface $note): static;

    public function getAuthor(): ?CoreUserInterface;

    public function setAuthor(?CoreUserInterface $author): static;

    public function getGuestName(): ?string;

    public function setGuestName(?string $guestName): static;

    public function getQuote(): ?string;

    public function setQuote(?string $quote): static;

    public function getBody(): string;

    public function setBody(string $body): static;

    public function getParent(): ?self;

    public function setParent(?self $parent): static;

    public function getResolvedAt(): ?DateTimeImmutable;

    public function setResolvedAt(?DateTimeImmutable $resolvedAt): static;

    public function getCreatedAt(): DateTimeImmutable;
}
