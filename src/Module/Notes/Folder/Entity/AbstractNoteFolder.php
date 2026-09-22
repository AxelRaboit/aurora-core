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

    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true, 'default' => 0])]
    protected int $position = 0;

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

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

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
