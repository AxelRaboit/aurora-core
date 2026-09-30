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
 * Une note ou un dossier qu'une personne a épinglé.
 *
 * Sur la personne et non sur la ligne : l'épinglage était une date posée sur
 * la note, ce qui convenait tant qu'une note n'avait qu'un lecteur. Dans un
 * espace à plusieurs, épingler une note l'aurait épinglée chez tout le monde.
 * Une ligne par personne et par élément, qui part avec l'un ou l'autre.
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
