<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Comment\Entity;

use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Entity\User;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A comment on a note, on a passage of it (09/10/2026), as Notion and Craft
 * have them: the words it is about, what is said, the replies, and a way to
 * close the thread once it is settled.
 *
 * **The passage is kept as words, not as a position.** A note is rewritten
 * all the time; an offset would point at the wrong line after the first
 * edit, while the words are found again wherever they moved, and say what
 * they were when they are gone.
 *
 * Written by a person of the suite, or by a guest of a writing share link,
 * who gives a name and has no account.
 */
#[ORM\MappedSuperclass]
abstract class AbstractNoteComment implements NoteCommentInterface
{
    #[ORM\ManyToOne(targetEntity: MarkdownNoteInterface::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected MarkdownNoteInterface $note;

    /** `SET NULL`: a person who leaves does not take the discussion along. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?CoreUserInterface $author = null;

    #[ORM\Column(length: 80, nullable: true)]
    protected ?string $guestName = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    protected ?string $quote = null;

    #[ORM\Column(type: Types::TEXT)]
    protected string $body = '';

    /** A reply goes with its thread. */
    #[ORM\ManyToOne(targetEntity: NoteCommentInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    protected ?NoteCommentInterface $parent = null;

    #[ORM\Column(nullable: true)]
    protected ?DateTimeImmutable $resolvedAt = null;

    #[ORM\Column]
    protected DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getNote(): MarkdownNoteInterface
    {
        return $this->note;
    }

    public function setNote(MarkdownNoteInterface $note): static
    {
        $this->note = $note;

        return $this;
    }

    public function getAuthor(): ?CoreUserInterface
    {
        return $this->author;
    }

    public function setAuthor(?CoreUserInterface $author): static
    {
        $this->author = $author;

        return $this;
    }

    public function getGuestName(): ?string
    {
        return $this->guestName;
    }

    public function setGuestName(?string $guestName): static
    {
        $this->guestName = $guestName;

        return $this;
    }

    public function getQuote(): ?string
    {
        return $this->quote;
    }

    public function setQuote(?string $quote): static
    {
        $this->quote = $quote;

        return $this;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): static
    {
        $this->body = $body;

        return $this;
    }

    public function getParent(): ?NoteCommentInterface
    {
        return $this->parent;
    }

    public function setParent(?NoteCommentInterface $parent): static
    {
        $this->parent = $parent;

        return $this;
    }

    public function getResolvedAt(): ?DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function setResolvedAt(?DateTimeImmutable $resolvedAt): static
    {
        $this->resolvedAt = $resolvedAt;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
