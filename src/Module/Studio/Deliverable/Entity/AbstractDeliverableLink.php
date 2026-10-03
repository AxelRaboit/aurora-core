<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

use function bin2hex;
use function random_bytes;

/**
 * Une adresse secrète qui ouvre un livrable, sans espace client autour.
 *
 * Pour celui à qui on transmet un document sans lui ouvrir l'espace : la
 * direction d'un client, un associé, un prestataire. Une adresse par
 * destinataire, pour pouvoir couper l'une sans toucher aux autres ; une date
 * d'expiration et un mot de passe au choix. Le même contrat que les liens de
 * lecture des publications, gardé à part avec le reste du livrable.
 *
 * Révoquer ne supprime pas : la ligne garde qui a eu l'adresse, quand, et
 * combien de fois elle a servi.
 */
#[ORM\MappedSuperclass]
abstract class AbstractDeliverableLink implements DeliverableLinkInterface
{
    /** 64 caractères hexadécimaux, tirés de 32 octets aléatoires. */
    #[ORM\Column(length: 64, unique: true)]
    protected string $token;

    /** À qui on l'a envoyée, pour s'y retrouver dans la liste. */
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

    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $passwordHash = null;

    #[ORM\Column]
    protected DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: DeliverableInterface::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        protected DeliverableInterface $deliverable,
    ) {
        $this->token = bin2hex(random_bytes(32));
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
