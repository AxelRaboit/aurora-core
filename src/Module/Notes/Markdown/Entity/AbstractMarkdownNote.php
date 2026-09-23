<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Entity;

use Aurora\Core\Encryption\Doctrine\EncryptedTextType;
use Aurora\Core\Timestampable\TimestampableTrait;
use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Entity\User;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A note is private to the person who wrote it.
 *
 * Title and body are stored through {@see EncryptedTextType}: notes are a
 * scratchpad, and people write things there they would not put in a document
 * they know is shared. That choice has a cost worth knowing - an encrypted
 * column cannot be searched or sorted in SQL, so title search and tag filtering
 * happen in PHP over the user's own notes rather than in the query.
 */
#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class AbstractMarkdownNote implements MarkdownNoteInterface
{
    use TimestampableTrait;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected CoreUserInterface $user;

    /**
     * The folder this note is filed in, null at the root.
     *
     * It used to be another note: a note with children stood in for a folder,
     * which is the ambiguity the folder entity exists to remove. A note is a
     * leaf now, and nothing is filed inside it.
     */
    #[ORM\ManyToOne(targetEntity: NoteFolderInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?NoteFolderInterface $folder = null;

    #[ORM\Column(type: EncryptedTextType::NAME, nullable: true)]
    protected ?string $title = null;

    #[ORM\Column(type: EncryptedTextType::NAME, nullable: true)]
    protected ?string $content = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    protected array $tags = [];

    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true, 'default' => 0])]
    protected int $position = 0;

    /**
     * Quand la note a été épinglée, jamais si elle ne l'est pas.
     *
     * Une date plutôt qu'un booléen : elle donne l'ordre des favoris sans
     * rien de plus, et « épinglé le » est une information qu'un booléen
     * jette.
     */
    #[ORM\Column(nullable: true)]
    protected ?DateTimeImmutable $favoritedAt = null;

    /** When the note was moved to the trash. */
    #[ORM\Column(nullable: true)]
    protected ?DateTimeImmutable $deletedAt = null;

    /**
     * The folder whose deletion took this note down with it.
     *
     * Null when the note was trashed on its own. Restoring a folder brings
     * back the notes that carry its id, and only those, so a page deleted by
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

    public function getFolder(): ?NoteFolderInterface
    {
        return $this->folder;
    }

    public function setFolder(?NoteFolderInterface $folder): static
    {
        $this->folder = $folder;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(?string $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function getTags(): array
    {
        return $this->tags;
    }

    public function setTags(array $tags): static
    {
        $this->tags = $tags;

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
