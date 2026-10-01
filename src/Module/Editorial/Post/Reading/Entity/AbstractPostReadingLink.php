<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Reading\Entity;

use Aurora\Module\Editorial\Post\Entity\PostInterface;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

use function bin2hex;
use function random_bytes;

/**
 * An address that opens one publication, outside the site, without an account.
 *
 * The shape of a deck's share link, on purpose: the two hand a document to
 * somebody outside the application, and the same questions follow - who could
 * open it, until when, how often they did, behind which password.
 *
 * **The token is kept in clear**, unlike a contract's access link. An author
 * copies a reading link again weeks later to send it a second time, and a
 * hashed token could only be shown once. What protects a confidential
 * publication is the length of the token, the password when there is one, and
 * revocation - which stamps a date and never deletes the row, so "who could
 * read this, and until when" can still be answered afterwards.
 *
 * Several per publication: one per recipient, so access can be cut for one
 * person without breaking it for the others.
 */
#[ORM\MappedSuperclass]
abstract class AbstractPostReadingLink implements PostReadingLinkInterface
{
    #[ORM\Column(length: 64, unique: true)]
    protected string $token;

    #[ORM\Column(length: 120, options: ['default' => ''])]
    protected string $label = '';

    #[ORM\Column(nullable: true)]
    protected ?DateTimeImmutable $expiresAt = null;

    #[ORM\Column(nullable: true)]
    protected ?DateTimeImmutable $revokedAt = null;

    #[ORM\Column(nullable: true)]
    protected ?DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(options: ['default' => 0])]
    protected int $openCount = 0;

    /**
     * `password_hash()` of a phrase a person chose. Null for a link that opens
     * on its address alone.
     */
    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $passwordHash = null;

    #[ORM\Column]
    protected DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: PostInterface::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        protected PostInterface $post,
    ) {
        $this->token = bin2hex(random_bytes(32));
        $this->createdAt = new DateTimeImmutable();
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getPost(): PostInterface
    {
        return $this->post;
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
