<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceAccess\Entity;

use Aurora\Module\Studio\Contract\Access\Entity\AbstractContractAccessLink;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceContent\Enum\SpaceContentApprovalEnum;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

use function bin2hex;
use function hash;
use function random_bytes;

/**
 * The address that opens one client space without an account.
 *
 * **Deliberately the contract link's scheme, not the note share's.** A selector
 * identifies the row and a secret proves the holder, and only a SHA-256 of the
 * secret is stored: a stolen database hands somebody the ability to look up
 * rows and not the ability to read a client's content plan. The note link keeps
 * its token in clear because a leaked notes database has already leaked the
 * notes; here the token stands between a dump and a year of somebody's
 * unpublished work, which is a different stake - and it will stand between a
 * dump and a validation made in their name once this link can write.
 *
 * The lookup is therefore two steps: find by selector, then compare hashes in
 * constant time. See {@see AbstractContractAccessLink}, which argues the same
 * three choices at length; this is the same design applied to a screen rather
 * than to a document.
 *
 * **Each right arrives with the write it governs**, not before. Both columns
 * were deliberately absent while the link only read: a column standing for a
 * permission nobody enforces is a switch that does nothing, which is the trap
 * the calendar's share links are documented as having avoided. Both are
 * enforced by the public controller, on rate-limited routes, which is the other
 * half of what that memory asks for before a guest may write.
 */
#[ORM\MappedSuperclass]
abstract class AbstractSpaceAccessLink implements SpaceAccessLinkInterface
{
    /** 16 random bytes, hex. Public: it travels in the URL and identifies the row. */
    #[ORM\Column(length: 32, unique: true)]
    protected string $selector;

    /**
     * SHA-256 of the secret half, hex.
     *
     * A fast digest rather than a password hash, for the reason the contract
     * link gives: Argon2 exists to make guessing a human-chosen secret
     * expensive, and this secret is 32 random bytes, which no amount of
     * guessing reaches. `hash_equals` keeps the comparison constant time.
     */
    #[ORM\Column(length: 64)]
    protected string $hashedToken;

    #[ORM\ManyToOne(targetEntity: CustomerSpaceInterface::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected CustomerSpaceInterface $space;

    /** Where this link was sent. Always personal: a space is shown to somebody. */
    #[ORM\Column(length: 180)]
    protected string $recipientEmail;

    /**
     * What the studio calls this link, for the list that shows several.
     *
     * A client with three people looking at their plan gets three links, and
     * "camille@" is not always enough to remember which is the marketing lead.
     */
    #[ORM\Column(length: 120, nullable: true)]
    protected ?string $label = null;

    /**
     * Links die on their own, and this one cannot be absent.
     *
     * The same default the contract link takes, for a lighter version of the
     * same reason: an address that still opens a client's content a year after
     * the engagement ended is a liability nobody remembers to close.
     */
    #[ORM\Column]
    protected DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    protected ?DateTimeImmutable $revokedAt = null;

    /**
     * Whether the holder may say "validé" or "à revoir".
     *
     * True by default, because that is what a link is usually for: the studio
     * sends it to get an answer. It exists for the second reader - a colleague
     * of the client, a partner agency - who is shown the plan and does not
     * decide on it.
     *
     * The answer is an opinion recorded against the content, never a move: see
     * {@see SpaceContentApprovalEnum}.
     */
    #[ORM\Column(options: ['default' => true])]
    protected bool $canApprove = true;

    /**
     * Whether the holder may write on the thread.
     *
     * Separate from `canApprove` because the two answer different questions. A
     * partner agency can be worth hearing without being the one who decides;
     * and a plan shown to a prospect wants neither. Both true by default, both
     * enforced, and a link with neither is the read-only one.
     */
    #[ORM\Column(options: ['default' => true])]
    protected bool $canComment = true;

    /**
     * The first open, kept apart from the last.
     *
     * Two columns because they answer different questions: the first says
     * whether the link was ever used, which is what tells the studio the mail
     * arrived, and the last says whether somebody keeps coming back.
     */
    #[ORM\Column(nullable: true)]
    protected ?DateTimeImmutable $firstOpenedAt = null;

    #[ORM\Column(nullable: true)]
    protected ?DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    protected DateTimeImmutable $createdAt;

    /**
     * The plaintext secret, held in memory only.
     *
     * Set once when the link is minted and never persisted. This is the single
     * moment it exists in a readable form - long enough to build the address
     * that goes in the mail - and after that request nothing can recover it.
     */
    protected ?string $plainToken = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    abstract public function getId(): ?int;

    /**
     * Mints both halves and returns the secret, once.
     *
     * Generation lives on the entity so no caller can create a link with a weak
     * secret, or with a hash that does not match the token it handed out.
     */
    public function mint(): string
    {
        $this->selector = bin2hex(random_bytes(16));
        $this->plainToken = bin2hex(random_bytes(32));
        $this->hashedToken = self::hashToken($this->plainToken);

        return $this->plainToken;
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function getSelector(): string
    {
        return $this->selector;
    }

    public function getHashedToken(): string
    {
        return $this->hashedToken;
    }

    public function getPlainToken(): ?string
    {
        return $this->plainToken;
    }

    public function getSpace(): CustomerSpaceInterface
    {
        return $this->space;
    }

    public function setSpace(CustomerSpaceInterface $space): static
    {
        $this->space = $space;

        return $this;
    }

    public function getRecipientEmail(): string
    {
        return $this->recipientEmail;
    }

    public function setRecipientEmail(string $recipientEmail): static
    {
        $this->recipientEmail = $recipientEmail;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getRevokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function canApprove(): bool
    {
        return $this->canApprove;
    }

    public function setCanApprove(bool $canApprove): static
    {
        $this->canApprove = $canApprove;

        return $this;
    }

    public function canComment(): bool
    {
        return $this->canComment;
    }

    public function setCanComment(bool $canComment): static
    {
        $this->canComment = $canComment;

        return $this;
    }

    public function revoke(DateTimeImmutable $at): static
    {
        // The first revocation is the one that counts: revoking twice is a
        // double click, and moving the date would rewrite when the address
        // actually stopped working.
        $this->revokedAt ??= $at;

        return $this;
    }

    public function getFirstOpenedAt(): ?DateTimeImmutable
    {
        return $this->firstOpenedAt;
    }

    public function getLastUsedAt(): ?DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function markUsed(DateTimeImmutable $at): static
    {
        $this->firstOpenedAt ??= $at;
        $this->lastUsedAt = $at;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt instanceof DateTimeImmutable;
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt < $now;
    }

    /**
     * Whether this address still opens the space.
     *
     * Revoked and expired stay apart in the columns because the back office
     * shows which, and fold together here because a stranger gets one page
     * either way.
     */
    public function isUsable(DateTimeImmutable $now): bool
    {
        return !$this->isRevoked() && !$this->isExpired($now);
    }
}
