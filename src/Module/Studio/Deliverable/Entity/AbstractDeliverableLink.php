<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Entity;

use Aurora\Core\Encryption\Doctrine\EncryptedStringType;
use Aurora\Module\Studio\Sharing\ShareToken;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A secret address that opens a deliverable, without a client space around
 * it.
 *
 * For someone you pass a document to without opening the space to them: a
 * client's management, a partner, a contractor. One address per recipient,
 * so that one can be cut off without touching the others; an optional expiry
 * date and password. The same contract as the publications' reading links,
 * kept separate with the rest of the deliverable.
 *
 * Revoking does not delete: the row keeps who had the address, when, and how
 * many times it was used.
 */
#[ORM\MappedSuperclass]
abstract class AbstractDeliverableLink implements DeliverableLinkInterface
{
    /**
     * 64 hexadecimal characters, drawn from 32 random bytes, encrypted at
     * rest: a backup or an SQL log no longer gives a usable address, and the
     * links dialog can still display it.
     */
    #[ORM\Column(type: EncryptedStringType::NAME, length: 255)]
    protected string $token;

    /** SHA-256 hash of the token: what it is looked up by, never what is shown. */
    #[ORM\Column(length: 64, unique: true)]
    protected string $tokenHash;

    /** Who it was sent to, to find your way in the list. */
    #[ORM\Column(length: 120, options: ['default' => ''])]
    protected string $label = '';

    #[ORM\Column(nullable: true)]
    protected ?DateTimeImmutable $expiresAt = null;

    #[ORM\Column(nullable: true)]
    protected ?DateTimeImmutable $revokedAt = null;

    /**
     * When the author hid this link from their list; null, it is listed. A
     * revoked or expired link clutters the dialog without being of use: it is
     * hidden instead of deleted, because its row still says who may have read
     * and how many times. Hiding reopens nothing: the link stays revoked.
     */
    #[ORM\Column(nullable: true)]
    protected ?DateTimeImmutable $hiddenAt = null;

    #[ORM\Column(nullable: true)]
    protected ?DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(options: ['default' => 0])]
    protected int $openCount = 0;

    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $passwordHash = null;

    #[ORM\Column]
    protected DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: DeliverableInterface::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        protected DeliverableInterface $deliverable,
    ) {
        $this->token = ShareToken::generate();
        $this->tokenHash = ShareToken::hash($this->token);
        $this->createdAt = new DateTimeImmutable();
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getDeliverable(): DeliverableInterface
    {
        return $this->deliverable;
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
        $this->revokedAt ??= $at;

        return $this;
    }

    public function getHiddenAt(): ?DateTimeImmutable
    {
        return $this->hiddenAt;
    }

    public function hide(DateTimeImmutable $at): static
    {
        $this->hiddenAt ??= $at;

        return $this;
    }

    public function isHidden(): bool
    {
        return $this->hiddenAt instanceof DateTimeImmutable;
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
