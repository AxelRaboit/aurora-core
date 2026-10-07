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
 * A past version of a note: its title and its text at a given moment.
 *
 * Encrypted like the note's: a version is no less private than the note it
 * was. It dies with the note.
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

    /** The version of the note it was (the conflict counter). */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    protected int $noteVersion = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    protected DateTimeImmutable $createdAt;

    public function __construct(#[ORM\ManyToOne(targetEntity: MarkdownNoteInterface::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        protected MarkdownNoteInterface $note, /** Who saved it: the one who was about to replace it. */
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
