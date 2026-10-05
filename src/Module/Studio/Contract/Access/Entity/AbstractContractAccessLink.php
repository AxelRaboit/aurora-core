<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Access\Entity;

use Aurora\Module\Studio\Contract\Entity\ContractInterface;
use Aurora\Module\Studio\Sharing\ShareToken;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

use function bin2hex;
use function hash;
use function random_bytes;

/**
 * The address that opens one contract without an account.
 *
 * Split in two, unlike the note share link next door, and the difference is the
 * point. A selector identifies the row; a secret proves the holder. Only a
 * SHA-256 of the secret is stored, so a stolen database hands somebody the
 * ability to look up rows and not the ability to open, read or sign a contract.
 *
 * The note link stores its token in clear because a leaked notes database has
 * already leaked the notes. Here the token is what stands between a database
 * dump and a signature in somebody else's name, which is a different stake.
 *
 * The lookup is therefore two steps: find by selector, then compare the hash in
 * constant time. A single hashed column would have meant either hashing every
 * row on every request or storing the secret to find it by.
 */
#[ORM\MappedSuperclass]
abstract class AbstractContractAccessLink implements ContractAccessLinkInterface
{
    /**
     * 16 random bytes, hex. Public: it travels in the URL and identifies the
     * row, nothing more.
     */
    #[ORM\Column(length: 32, unique: true)]
    protected string $selector;

    /**
     * SHA-256 of the secret half, hex.
     *
     * Not a password hash, deliberately. Argon2 exists to make guessing a
     * human-chosen secret expensive; this secret is 32 random bytes, which no
     * amount of guessing reaches, and the comparison happens on every page load
     * of a document somebody is reading. A fast digest of a high-entropy secret
     * is the right trade here, and `hash_equals` keeps the comparison constant
     * time.
     */
    #[ORM\Column(length: 64)]
    protected string $hashedToken;

    #[ORM\ManyToOne(targetEntity: ContractInterface::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected ContractInterface $contract;

    /**
     * Where this link was mailed.
     *
     * Never null, unlike a note share: a contract link is always personal. It
     * is also the address the signature code will be sent to, which is what
     * makes it evidence rather than a convenience.
     */
    #[ORM\Column(length: 180)]
    protected string $recipientEmail;

    /**
     * Links die on their own.
     *
     * The opposite default from a shared note, for a reason: an offer that can
     * still be accepted a year later is a liability, and the paper version of
     * this always carried a validity period. The manager sets it; nothing here
     * lets it be absent.
     */
    #[ORM\Column]
    protected DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    protected ?DateTimeImmutable $revokedAt = null;

    #[ORM\Column(nullable: true)]
    protected ?DateTimeImmutable $sentAt = null;

    /**
     * The first open, kept apart from the last.
     *
     * Two columns rather than one because they answer different questions: the
     * first says whether the document was ever read, which is what changes the
     * contract's status, and the last says whether somebody came back to it.
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
     * moment the secret exists in a readable form - long enough to build the
     * URL that goes in the mail - and after that request nothing can recover
     * it, which is exactly what storing only its hash means.
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
     * Generation lives on the entity so no caller can create a link with a
     * weak secret, or with a hash that does not match the token it handed out.
     */
    public function mint(): string
    {
        $this->selector = bin2hex(random_bytes(16));
        $this->plainToken = ShareToken::generate();
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

    public function getContract(): ContractInterface
    {
        return $this->contract;
    }

    public function setContract(ContractInterface $contract): static
    {
        $this->contract = $contract;

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

    public function revoke(DateTimeImmutable $at): static
    {
        // The first revocation is the one that counts: revoking twice is a
        // double click, and moving the date would rewrite when the address
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
     * Whether this address still opens the document.
     *
     * One question with one answer, asked by the controller. Revoked and
     * expired are kept apart in the columns because the back office shows
     * which, and folded together here because a stranger gets one page either
     * way.
     */
    public function isUsable(DateTimeImmutable $now): bool
    {
        return !$this->isRevoked() && !$this->isExpired($now);
    }
}
