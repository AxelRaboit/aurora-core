<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Share\Entity;

use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Share\Enum\NoteMemberRoleEnum;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * One person who was given this note, and what they may do to it.
 *
 * **Why this exists next to a space's members.** A space answers "who sees
 * this notebook", and it answers it well for a team that works together over
 * time. It cannot answer "just this page, just this once": putting a note in
 * a shared space to show it to one person opens the whole space to them, and
 * moving it back afterwards is not something anybody remembers to do. So a
 * note carries its own guest list, and that list grants nothing beyond the
 * one note it names.
 *
 * **It only ever adds.** A grant here is read on top of the space rule, never
 * instead of it: somebody who already writes the space keeps writing it
 * whatever this row says, and a reader grant cannot take away a right the
 * space gives. That is what makes this safe to add to a query - it widens a
 * set, and no path through it narrows one.
 *
 * Both sides cascade: without the note the row means nothing, and without the
 * account it would name somebody who no longer exists.
 *
 * Read-only companion: a share **link** is the other half of the same
 * question, for people who have no account at all.
 * See {@see AbstractMarkdownNoteShareLink}.
 */
#[ORM\MappedSuperclass]
#[ORM\UniqueConstraint(name: 'uniq_notes_markdown_member', columns: ['note_id', 'user_id'])]
abstract class AbstractMarkdownNoteMember implements MarkdownNoteMemberInterface
{
    #[ORM\ManyToOne(targetEntity: MarkdownNoteInterface::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected MarkdownNoteInterface $note;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected CoreUserInterface $user;

    #[ORM\Column(length: 16, enumType: NoteMemberRoleEnum::class, options: ['default' => 'reader'])]
    protected NoteMemberRoleEnum $role = NoteMemberRoleEnum::Reader;

    /**
     * When the note was handed over.
     *
     * Kept because the screen sorts by it: the last person added is the one
     * you are looking for when you came to correct a mistake.
     */
    #[ORM\Column(type: 'datetime_immutable')]
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

    public function getUser(): CoreUserInterface
    {
        return $this->user;
    }

    public function setUser(CoreUserInterface $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getRole(): NoteMemberRoleEnum
    {
        return $this->role;
    }

    public function setRole(NoteMemberRoleEnum $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
