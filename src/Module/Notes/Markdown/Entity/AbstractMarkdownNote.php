<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Entity;

use Aurora\Core\Encryption\Doctrine\EncryptedTextType;
use Aurora\Core\Timestampable\TimestampableTrait;
use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;
use Aurora\Module\Notes\Markdown\Enum\NoteAppearanceEnum;
use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
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

    /**
     * The author. Null when their account has been deleted: in a shared
     * space, what they wrote stays with the team. Their personal space, for
     * its part, goes with them, through the space's cascade.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?CoreUserInterface $user = null;

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

    /**
     * The space where the row lives. Always the one of its folder: it is what
     * says who reads it and who writes it. Deleting the space takes what it holds.
     */
    #[ORM\ManyToOne(targetEntity: NoteSpaceInterface::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected NoteSpaceInterface $space;

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
     * A note that serves as a starting point: "Nouvelle note depuis un
     * modèle" makes a copy of it. It stays a note like the others, which you
     * read, file and edit.
     */
    #[ORM\Column(name: 'is_template', type: Types::BOOLEAN, options: ['default' => false])]
    protected bool $template = false;

    /**
     * Moves forward on each write of the content, and only there.
     *
     * Not Doctrine's lock: it moves forward on each write of the row, and
     * moving or pinning the open note would have refused its next save for a
     * conflict that does not exist. What matters here is that two people do
     * not overwrite each other's text.
     */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
    protected int $version = 1;

    /**
     * The header image, at whoever hosts it.
     *
     * **An address, not a file.** The photo stays at Pexels: nothing goes
     * into the media library, which has no need to fill up with decorative
     * illustrations nobody will ever ask for again. The accepted price is that
     * an image removed on their side leaves an empty frame; you pick another
     * one, and that is all.
     *
     * In plain text, like a folder's color: an image address says nothing
     * about what the note tells.
     */
    #[ORM\Column(length: 1024, nullable: true)]
    protected ?string $coverUrl = null;

    /**
     * Who took the photo, and where to see them.
     *
     * Not overzealousness: the Pexels license asks for credit, and once the
     * image is out of the media library this is the only place left to do it.
     */
    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $coverCreditName = null;

    #[ORM\Column(length: 1024, nullable: true)]
    protected ?string $coverCreditUrl = null;

    /**
     * Where to crop the photo, as a percentage of its height.
     *
     * A banner shows a strip of an image that was not framed for it: without
     * this setting, a portrait photo shows a forehead or a chin, never a
     * face.
     */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 50])]
    protected int $coverPosition = 50;

    /** {@see NoteAppearanceEnum} - the note's background and its ink. */
    #[ORM\Column(length: 20, options: ['default' => 'plain'])]
    protected string $appearance = NoteAppearanceEnum::Plain->value;

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

    /**
     * The Craft document this note is a copy of, when it comes from one.
     *
     * **Kept so that it can be brought back to the current version of the
     * document.** The note is a one-off copy and not a mirror: nothing comes
     * back to change it on its own. But without this id, reimporting the same
     * document would create a second note next to the first, and finding the
     * source would mean searching for it by title.
     *
     * In plain text, like the banner address: a Craft block id says nothing
     * about what the note tells.
     */
    #[ORM\Column(length: 64, nullable: true)]
    protected ?string $craftDocumentId = null;

    public function getUser(): ?CoreUserInterface
    {
        return $this->user;
    }

    public function setUser(?CoreUserInterface $user): static
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

    public function getCoverUrl(): ?string
    {
        return $this->coverUrl;
    }

    public function setCoverUrl(?string $coverUrl): static
    {
        $this->coverUrl = $coverUrl;

        return $this;
    }

    public function getCoverCreditName(): ?string
    {
        return $this->coverCreditName;
    }

    public function setCoverCreditName(?string $name): static
    {
        $this->coverCreditName = $name;

        return $this;
    }

    public function getCoverCreditUrl(): ?string
    {
        return $this->coverCreditUrl;
    }

    public function setCoverCreditUrl(?string $url): static
    {
        $this->coverCreditUrl = $url;

        return $this;
    }

    public function getCoverPosition(): int
    {
        return $this->coverPosition;
    }

    /** Clamped here rather than at the edge: it is a percentage, nothing else. */
    public function setCoverPosition(int $percent): static
    {
        $this->coverPosition = max(0, min(100, $percent));

        return $this;
    }

    public function getAppearance(): NoteAppearanceEnum
    {
        return NoteAppearanceEnum::fromNullable($this->appearance);
    }

    public function setAppearance(NoteAppearanceEnum $appearance): static
    {
        $this->appearance = $appearance->value;

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

    public function isTemplate(): bool
    {
        return $this->template;
    }

    public function setTemplate(bool $template): static
    {
        $this->template = $template;

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

    public function getSpace(): NoteSpaceInterface
    {
        return $this->space;
    }

    public function setSpace(NoteSpaceInterface $space): static
    {
        $this->space = $space;

        return $this;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function bumpVersion(): void
    {
        ++$this->version;
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

    public function getCraftDocumentId(): ?string
    {
        return $this->craftDocumentId;
    }

    public function setCraftDocumentId(?string $craftDocumentId): static
    {
        $this->craftDocumentId = $craftDocumentId;

        return $this;
    }
}
