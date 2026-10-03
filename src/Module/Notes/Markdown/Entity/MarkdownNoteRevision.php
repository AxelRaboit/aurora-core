<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Entity;

use Aurora\Core\Encryption\Doctrine\EncryptedTextType;
use Aurora\Module\Notes\Markdown\Repository\MarkdownNoteRevisionRepository;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Entity\User;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une version passée d'une note : son titre et son texte à un moment donné.
 *
 * Chiffrés comme ceux de la note : une version n'est pas moins privée que la
 * note qu'elle a été. Elle meurt avec la note.
 */
#[ORM\Entity(repositoryClass: MarkdownNoteRevisionRepository::class)]
#[ORM\Table(name: 'core_notes_markdown_revisions')]
#[ORM\Index(name: 'idx_notes_md_revision_note', columns: ['note_id', 'created_at'])]
class MarkdownNoteRevision
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_notes_markdown_revision_id', allocationSize: 1)]
    #[ORM\Column]
    protected ?int $id = null;

    #[ORM\Column(type: EncryptedTextType::NAME, nullable: true)]
    protected ?string $title = null;

    #[ORM\Column(type: EncryptedTextType::NAME, nullable: true)]
    protected ?string $content = null;

    /** La version de la note qu'elle a été (le compteur des conflits). */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    protected int $noteVersion = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    protected DateTimeImmutable $createdAt;

    public function __construct(#[ORM\ManyToOne(targetEntity: MarkdownNoteInterface::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        protected MarkdownNoteInterface $note, /** Qui l'a enregistrée : celui qui s'apprêtait à la remplacer. */
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        protected ?CoreUserInterface $author = null)
    {
        $this->title = $this->note->getTitle();
        $this->content = $this->note->getContent();
        $this->noteVersion = $this->note->getVersion();
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNote(): MarkdownNoteInterface
    {
        return $this->note;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function getNoteVersion(): int
    {
        return $this->noteVersion;
    }

    public function getAuthor(): ?CoreUserInterface
    {
        return $this->author;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
