<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Entity;

use Aurora\Core\Encryption\Doctrine\EncryptedStringType;
use Aurora\Module\Studio\Sharing\ShareToken;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

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
    /**
     * 64 caractères hexadécimaux, tirés de 32 octets aléatoires, chiffrés au
     * repos : une sauvegarde ou un journal SQL ne donne plus d'adresse
     * utilisable, et la fenêtre des liens peut toujours l'afficher.
     */
    #[ORM\Column(type: EncryptedStringType::NAME, length: 255)]
    protected string $token;

    /** Empreinte SHA-256 du jeton : ce par quoi on le cherche, jamais ce qu'on montre. */
    #[ORM\Column(length: 64, unique: true)]
    protected string $tokenHash;

    /** À qui on l'a envoyée, pour s'y retrouver dans la liste. */
    #[ORM\Column(length: 120, options: ['default' => ''])]
    protected string $label = '';

    #[ORM\Column(nullable: true)]
    protected ?DateTimeImmutable $expiresAt = null;

    #[ORM\Column(nullable: true)]
    protected ?DateTimeImmutable $revokedAt = null;

    /**
     * Quand l'auteur a masqué ce lien de sa liste ; nul, il y figure. Un lien
     * retiré ou expiré encombre la fenêtre sans servir : on le masque au lieu de
     * le supprimer, parce que sa ligne dit encore qui a pu lire et combien de
     * fois. Masquer ne rouvre rien : le lien reste retiré.
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
