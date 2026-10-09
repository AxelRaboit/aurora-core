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
 * **Who wrote it is three columns, not one.** `author` names an account.
 * `viaLink` names the share link a guest came through, who has no account at
 * all - the address *was* their identity. Pointing at the link rather than
 * copying its label means the name is not duplicated into a second table, and
 * it survives revocation, which is exactly when somebody goes looking.
 * `writtenBy` names everybody who was writing at once, for the one case the
 * other two get wrong.
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

    /**
     * Everybody who was writing the note when this version was kept.
     *
     * **Null for an ordinary save, which is almost every one of them.** It is
     * filled only when several people were in the editor at the same moment,
     * because that is the single case `author` reports wrongly: during a
     * co-editing session one elected client sends the write-back for the whole
     * room, so the account that saved a version may not have typed a word of
     * it. Crediting that person alone is not a rounding error, it is the wrong
     * name on the record.
     *
     * **The names are copied, not related.** A version is the record of a
     * moment: the name worth showing is the name as it was, and a closed
     * account must not quietly turn "written by two people" back into
     * "written by one". The same reasoning the share link's label follows,
     * reaching the opposite conclusion - a link is one row that survives its
     * own revocation, a room is a list that exists nowhere else.
     *
     * Only people the server saw *editing* are in it. Somebody who had the
     * note open in the reader did not write it, and saying they did would be a
     * worse record than saying nothing.
     *
     * @var list<array{id: int, name: ?string, guest?: bool}>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    protected ?array $writtenBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    protected DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: MarkdownNoteInterface::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        protected MarkdownNoteInterface $note, /** Who saved it: the one who was about to replace it. */
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        protected ?CoreUserInterface $author = null, /** The share link a guest wrote through; null for a signed-in author. */
        #[ORM\ManyToOne(targetEntity: MarkdownNoteShareLinkInterface::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        protected ?MarkdownNoteShareLinkInterface $viaLink = null,
        /*
         * Everybody writing at that moment, or null when one person was.
         *
         * @param list<array{id: int, name: ?string, guest?: bool}>|null $writtenBy
         */
        ?array $writtenBy = null
    ) {
        $this->writtenBy = 1 < count($writtenBy ?? []) ? $writtenBy : null;
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
     * The share link's own words - the address it was mailed to, or the label
     * somebody gave it.
     *
     * **Not for everybody who may read the note.** A link's recipient address
     * belongs to whoever created the link, and the history is readable by
     * anybody the note is open to - a member of its space, somebody it was
     * handed to as a reader. So this is deliberately *not* folded into one
     * `getAuthorLabel()`: the caller has to know whose eyes it is for, and
     * the only caller that does is the controller.
     */
    public function getLinkLabel(): ?string
    {
        return $this->viaLink?->getRecipientEmail() ?: ($this->viaLink?->getLabel() ?: null);
    }

    /** Whether this version was written through a share link rather than by an account. */
    public function wasWrittenThroughLink(): bool
    {
        return $this->viaLink instanceof MarkdownNoteShareLinkInterface;
    }

    /**
     * Everybody who was writing this version, oneself included.
     *
     * Empty for a version somebody wrote on their own, which is what
     * `author` already says.
     *
     * @return list<array{id: int, name: ?string, guest?: bool}>
     */
    public function getWrittenBy(): array
    {
        return $this->writtenBy ?? [];
    }

    /** Whether several people were writing this version at the same time. */
    public function wasWrittenBySeveralHands(): bool
    {
        return 1 < count($this->writtenBy ?? []);
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
