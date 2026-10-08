<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Entity;

use Aurora\Core\Encryption\Doctrine\EncryptedTextType;
use Aurora\Module\Notes\Markdown\Repository\MarkdownNoteRevisionRepository;
use Aurora\Module\Notes\Share\Entity\MarkdownNoteShareLinkInterface;
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
 *
 * **Who wrote it is two columns, not one.** `author` names an account.
 * `viaLink` names the share link a guest came through, who has no account at
 * all - the address *was* their identity. Pointing at the link rather than
 * copying its label means the name is not duplicated into a second table, and
 * it survives revocation, which is exactly when somebody goes looking.
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
        protected ?CoreUserInterface $author = null, /** The share link a guest wrote through; null for a signed-in author. */
        #[ORM\ManyToOne(targetEntity: MarkdownNoteShareLinkInterface::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        protected ?MarkdownNoteShareLinkInterface $viaLink = null)
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

    public function getViaLink(): ?MarkdownNoteShareLinkInterface
    {
        return $this->viaLink;
    }

    /**
     * How the history names whoever wrote this version.
     *
     * An account's name, else the share link's own words - the address it was
     * mailed to, or the label somebody gave it. Null when neither is left,
     * which is an account deleted or a link deleted, not a gap in the record.
     */
    public function getAuthorLabel(): ?string
    {
        return $this->author?->getName() ?? ($this->viaLink?->getRecipientEmail() ?: ($this->viaLink?->getLabel() ?: null));
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
