<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Share\Entity;

use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use function random_bytes;

/**
 * One address that opens a note without an account.
 *
 * Two shapes share this table on purpose. A link with no `recipientEmail` is
 * the "copy the link" one, handed out by whoever holds it. A link with an
 * address is personal: it was mailed to that person, it can be revoked without
 * touching anybody else's, and `lastUsedAt` says whether they ever opened it.
 * Both are the same secret-address mechanism, so making them two tables would
 * have duplicated the token, the expiry and the revocation to express one
 * nullable column.
 *
 * The view is live, not a snapshot: editing the note changes what the guest
 * sees. That is what note applications do and what people expect, and it is
 * also why `expiresAt` exists - a forgotten share keeps publishing whatever
 * gets written into the note afterwards.
 *
 * Read-only, with no column claiming otherwise. See
 * `project_notes_share_link_read_only` for what is deliberately absent.
 */
#[ORM\MappedSuperclass]
abstract class AbstractMarkdownNoteShareLink implements MarkdownNoteShareLinkInterface
{
    /**
     * 32 random bytes, hex-encoded.
     *
     * The address *is* the credential, so it has to be long enough that guessing
     * one is not a strategy. Unique so a collision fails loudly on insert rather
     * than handing one person another person's note.
     */
    #[ORM\Column(length: 64, unique: true)]
    protected string $token;

    #[ORM\ManyToOne(targetEntity: MarkdownNoteInterface::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected MarkdownNoteInterface $note;

    /**
     * Whether the notes filed under this one come along.
     *
     * Off by default, and asked as a question with the count shown: publishing a
     * branch of thirty notes and publishing one note are different acts, and the
     * person doing it should have had to say which they meant.
     */
    #[ORM\Column(options: ['default' => false])]
    protected bool $includeDescendants = false;

    /**
     * Whether the notes this one links to come along.
     *
     * A different question from the tree, and the riskier of the two: links are
     * followed transitively, so a note citing two notes that each cite two more
     * reaches most of a vault in three hops. Off by default, and the screen
     * lists the titles it would publish before the click rather than after -
     * a count alone cannot be checked against what somebody meant to share.
     */
    #[ORM\Column(options: ['default' => false])]
    protected bool $includeLinked = false;

    /** Null for a plain copy-the-link share; set when this link was mailed to somebody. */
    #[ORM\Column(length: 180, nullable: true)]
    protected ?string $recipientEmail = null;

    /** Free text so a list of links is readable months later. */
    #[ORM\Column(length: 120, options: ['default' => ''])]
    protected string $label = '';

    /**
     * Null means the link works until it is revoked.
     *
     * Notes are shared and forgotten, and a link that dies on its own surprises
     * the person holding it more often than it protects the person who sent it.
     * The field is offered, not imposed.
     */
    #[ORM\Column(nullable: true)]
    protected ?DateTimeImmutable $expiresAt = null;

    #[ORM\Column(nullable: true)]
    protected ?DateTimeImmutable $revokedAt = null;

    /** When the invitation email went out. Null for a link nobody mailed. */
    #[ORM\Column(nullable: true)]
    protected ?DateTimeImmutable $sentAt = null;

    /** Answers "did they ever open it", which is most of why a per-person link is worth the row. */
    #[ORM\Column(nullable: true)]
    protected ?DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    protected DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->token = bin2hex(random_bytes(32));
        $this->createdAt = new DateTimeImmutable();
    }

    abstract public function getId(): ?int;

    public function getToken(): string
    {
        return $this->token;
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

    public function includesDescendants(): bool
    {
        return $this->includeDescendants;
    }

    public function setIncludeDescendants(bool $includeDescendants): static
    {
        $this->includeDescendants = $includeDescendants;

        return $this;
    }

    public function includesLinked(): bool
    {
        return $this->includeLinked;
    }

    public function setIncludeLinked(bool $includeLinked): static
    {
        $this->includeLinked = $includeLinked;

        return $this;
    }

    public function getRecipientEmail(): ?string
    {
        return $this->recipientEmail;
    }

    public function setRecipientEmail(?string $recipientEmail): static
    {
        $this->recipientEmail = $recipientEmail;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getExpiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getRevokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function revoke(DateTimeImmutable $at): static
    {
        // The first revocation is the one that counts: revoking twice is an
        // ordinary double click, and moving the date would rewrite when the link
        // actually stopped working.
        $this->revokedAt ??= $at;

        return $this;
    }

    public function getSentAt(): ?DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function markSent(DateTimeImmutable $at): static
    {
        $this->sentAt = $at;

        return $this;
    }

    public function getLastUsedAt(): ?DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function setLastUsedAt(?DateTimeImmutable $lastUsedAt): static
    {
        $this->lastUsedAt = $lastUsedAt;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isUsableAt(DateTimeImmutable $now): bool
    {
        if ($this->revokedAt instanceof DateTimeImmutable) {
            return false;
        }

        // Expiry is inclusive of the instant itself: a link good "until 18:00"
        // stops at 18:00 rather than lasting the rest of that second.
        return !$this->expiresAt instanceof DateTimeImmutable || $this->expiresAt > $now;
    }
}
