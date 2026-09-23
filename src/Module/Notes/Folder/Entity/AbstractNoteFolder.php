<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Folder\Entity;

use Aurora\Core\Encryption\Doctrine\EncryptedTextType;
use Aurora\Core\Timestampable\TimestampableTrait;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Entity\User;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A folder is private to the person who made it, like the notes it holds.
 *
 * **The name is encrypted**, through {@see EncryptedTextType}, exactly like a
 * note's title. Two reasons, and the second is the one that decided it: a
 * folder name says as much about somebody as the title of the note inside it
 * ("Divorce", "Analyses"), and the conversion of the old tree into folders is
 * a plain SQL copy of the note title into this column, which only works
 * because both hold the same self-contained ciphertext.
 *
 * The cost is the same as for notes and is known: an encrypted column cannot
 * be sorted or searched in SQL, so the tree is ordered in PHP. A person has
 * tens of folders, not thousands, so the ceiling is far away.
 */
#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class AbstractNoteFolder implements NoteFolderInterface
{
    use TimestampableTrait;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected CoreUserInterface $user;

    #[ORM\ManyToOne(targetEntity: NoteFolderInterface::class, inversedBy: 'children')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?NoteFolderInterface $parent = null;

    /**
     * Nullable, because the note it may have been converted from could be
     * untitled. The screens read an empty name as "Sans titre" rather than
     * refusing to show the folder.
     */
    #[ORM\Column(type: EncryptedTextType::NAME, nullable: true)]
    protected ?string $name = null;

    /**
     * `#rrggbb`, ou rien. Sept caractères parce que c'est la forme que le
     * sélecteur de la maison produit, celle que porte déjà l'étiquette de
     * document.
     */
    #[ORM\Column(length: 7, nullable: true)]
    protected ?string $color = null;

    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true, 'default' => 0])]
    protected int $position = 0;

    /** Quand le dossier a été épinglé, jamais s'il ne l'est pas. */
    #[ORM\Column(nullable: true)]
    protected ?DateTimeImmutable $favoritedAt = null;

    /**
     * Depuis quand ce dossier, et tout ce qu'il contient, est lisible par
     * les autres.
     *
     * **Le partage se pose sur le dossier**, et ce qu'il range suit. Poser
     * la question note par note devient ingérable au bout de trente notes :
     * plus personne ne sait qui voit quoi. Un dossier est un endroit, et un
     * endroit se partage.
     */
    #[ORM\Column(nullable: true)]
    protected ?DateTimeImmutable $sharedAt = null;

    /** When the folder was moved to the trash. */
    #[ORM\Column(nullable: true)]
    protected ?DateTimeImmutable $deletedAt = null;

    /**
     * The folder whose deletion took this one down with it.
     *
     * Null when it was trashed on its own. Restoring a folder brings back the
     * folders and notes carrying its id, and only those, so a page deleted by
     * hand last week stays where its author left it.
     */
    #[ORM\Column(nullable: true)]
    protected ?int $trashedWithFolderId = null;

    public function getUser(): CoreUserInterface
    {
        return $this->user;
    }

    public function setUser(CoreUserInterface $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getParent(): ?NoteFolderInterface
    {
        return $this->parent;
    }

    public function setParent(?NoteFolderInterface $parent): static
    {
        $this->parent = $parent;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getSharedAt(): ?DateTimeImmutable
    {
        return $this->sharedAt;
    }

    public function setSharedAt(?DateTimeImmutable $sharedAt): static
    {
        $this->sharedAt = $sharedAt;

        return $this;
    }

    public function getFavoritedAt(): ?DateTimeImmutable
    {
        return $this->favoritedAt;
    }

    public function setFavoritedAt(?DateTimeImmutable $favoritedAt): static
    {
        $this->favoritedAt = $favoritedAt;

        return $this;
    }

    public function getDeletedAt(): ?DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function setDeletedAt(?DateTimeImmutable $deletedAt): static
    {
        $this->deletedAt = $deletedAt;

        return $this;
    }

    public function isTrashed(): bool
    {
        return $this->deletedAt instanceof DateTimeImmutable;
    }

    public function getTrashedWithFolderId(): ?int
    {
        return $this->trashedWithFolderId;
    }

    public function setTrashedWithFolderId(?int $trashedWithFolderId): static
    {
        $this->trashedWithFolderId = $trashedWithFolderId;

        return $this;
    }
}
