<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Favorite\Entity;

use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A note or a folder a person has pinned.
 *
 * On the person and not on the row: pinning was a date set on the note,
 * which was fine as long as a note had only one reader. In a shared space,
 * pinning a note would have pinned it for everyone. One row per person and
 * per item, which goes away with either one.
 */
#[ORM\MappedSuperclass]
#[ORM\UniqueConstraint(name: 'uniq_notes_favorite_note', columns: ['user_id', 'note_id'])]
#[ORM\UniqueConstraint(name: 'uniq_notes_favorite_folder', columns: ['user_id', 'folder_id'])]
abstract class AbstractNoteFavorite implements NoteFavoriteInterface
{
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected CoreUserInterface $user;

    #[ORM\ManyToOne(targetEntity: MarkdownNoteInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    protected ?MarkdownNoteInterface $note = null;

    #[ORM\ManyToOne(targetEntity: NoteFolderInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    protected ?NoteFolderInterface $folder = null;

    #[ORM\Column]
    protected DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getUser(): CoreUserInterface
    {
        return $this->user;
    }

    public function setUser(CoreUserInterface $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getNote(): ?MarkdownNoteInterface
    {
        return $this->note;
    }

    public function setNote(?MarkdownNoteInterface $note): static
    {
        $this->note = $note;

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

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $at): static
    {
        $this->createdAt = $at;

        return $this;
    }
}
