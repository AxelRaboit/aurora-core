<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deck\Share\Entity;

use Aurora\Module\Studio\Deck\Entity\DeckInterface;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

use function bin2hex;
use function random_bytes;

/**
 * One address that opens a deck without an account.
 *
 * **The token is stored in clear, like the note share link and unlike the
 * contract one**, and the difference is the stake rather than an oversight.
 * A contract's token is what stands between a database dump and a signature in
 * somebody else's name, so it is split into a selector and a hashed secret. A
 * deck has nothing to forge: a database of decks that leaks has already leaked
 * the decks, and hashing the address would buy nothing while costing the
 * lookup a second step.
 *
 * **The view is live, not a snapshot.** Fixing a typo changes what the
 * recipient sees when they reopen the link, which is what people expect of a
 * shared document - and it is also why `expiresAt` is offered: a forgotten
 * share keeps publishing whatever is written into the deck afterwards.
 *
 * Read-only, and nothing here claims otherwise: there is no comment, no
 * reaction, no download. What a recipient can do is read the slides, and the
 * speaker notes are not among them.
 *
 * No `recipientEmail` yet, deliberately. The notes link has one because it
 * mails the address; a field that names a recipient without writing to them
 * would look like a send button that does nothing.
 */
#[ORM\MappedSuperclass]
abstract class AbstractDeckShareLink implements DeckShareLinkInterface
{
    /**
     * 32 random bytes, hex-encoded.
     *
     * The address *is* the credential, so it has to be long enough that
     * guessing one is not a strategy. Unique, so a collision fails loudly on
     * insert rather than handing one person another person's deck.
     */
    #[ORM\Column(length: 64, unique: true)]
    protected string $token;

    /** Free text, so a list of links is still readable months later. */
    #[ORM\Column(length: 120, options: ['default' => ''])]
    protected string $label = '';

    /**
     * Null means the link works until it is revoked.
     *
     * Offered rather than imposed: a deck sent to a client is reopened weeks
     * later more often than it is abused, and a link that dies on its own
     * surprises the holder more than it protects the sender.
     */
    #[ORM\Column(nullable: true)]
    protected ?DateTimeImmutable $expiresAt = null;

    #[ORM\Column(nullable: true)]
    protected ?DateTimeImmutable $revokedAt = null;

    /** Answers "did they ever open it", which is most of why the row is worth keeping. */
    #[ORM\Column(nullable: true)]
    protected ?DateTimeImmutable $lastUsedAt = null;

    /**
     * How many times the link has been opened.
     *
     * A counter and not a log. "Did they read it, and did they come back to it"
     * is the question a sender actually has, and it is answered by a number;
     * a row per opening would be a record of somebody's reading habits kept
     * because it was easy, which is not a reason to keep one.
     */
    #[ORM\Column(options: ['default' => 0])]
    protected int $openCount = 0;

    /**
     * A second secret, on top of the address, for the deck that needs one.
     *
     * **Hashed, unlike the token.** The address is the credential and hashing it
     * would buy nothing, since a database of decks that leaks has already
     * leaked the decks. A password is different in one way that decides it:
     * people reuse passwords. What leaks here must not open anything else.
     *
     * Null is the ordinary case. A link that is hard to guess and expires is
     * enough for most of what gets shared, and a password on every link is a
     * password nobody types and everybody mails alongside the address.
     */
    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $passwordHash = null;

    #[ORM\Column]
    protected DateTimeImmutable $createdAt;

    public function __construct(#[ORM\ManyToOne(targetEntity: DeckInterface::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        protected DeckInterface $deck)
    {
        $this->token = bin2hex(random_bytes(32));
        $this->createdAt = new DateTimeImmutable();
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getDeck(): DeckInterface
    {
        return $this->deck;
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

    /** Revoking is stamping a date, never deleting the row: "who could see this, and until when" is a question worth being able to answer afterwards. */
    public function revoke(DateTimeImmutable $at): static
    {
        $this->revokedAt ??= $at;

        return $this;
    }

    public function getLastUsedAt(): ?DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function touch(DateTimeImmutable $at): static
    {
        $this->lastUsedAt = $at;
        ++$this->openCount;

        return $this;
    }

    public function getOpenCount(): int
    {
        return $this->openCount;
    }

    public function isLocked(): bool
    {
        return null !== $this->passwordHash;
    }

    /**
     * Sets or clears the password. The hashing is the caller's, because the
     * hasher is a service and an entity does not reach for one.
     */
    public function setPasswordHash(?string $passwordHash): static
    {
        $this->passwordHash = $passwordHash;

        return $this;
    }

    public function getPasswordHash(): ?string
    {
        return $this->passwordHash;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isUsable(DateTimeImmutable $now): bool
    {
        if ($this->revokedAt instanceof DateTimeImmutable) {
            return false;
        }

        return !$this->expiresAt instanceof DateTimeImmutable || $this->expiresAt > $now;
    }
}
