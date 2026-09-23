<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Entity;

use Aurora\Core\Encryption\Doctrine\EncryptedTextType;
use Aurora\Core\Timestampable\TimestampableTrait;
use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;
use Aurora\Module\Notes\Markdown\Enum\NoteAppearanceEnum;
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
     * L'image d'entête, chez celui qui l'héberge.
     *
     * **Une adresse, pas un fichier.** La photo reste chez Pexels : rien
     * n'entre dans la médiathèque, qui n'a pas à se remplir d'illustrations
     * décoratives dont personne ne redemandera jamais une seule. Le prix
     * assumé est qu'une image retirée de chez eux laisse un cadre vide ; on
     * en choisit une autre, et c'est tout.
     *
     * En clair, comme la couleur d'un dossier : une adresse d'image ne dit
     * rien de ce que la note raconte.
     */
    #[ORM\Column(length: 1024, nullable: true)]
    protected ?string $coverUrl = null;

    /**
     * Qui a pris la photo, et où le voir.
     *
     * Pas du zèle : la licence Pexels demande de créditer, et une fois
     * l'image sortie de la médiathèque il n'y a plus qu'ici pour le faire.
     */
    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $coverCreditName = null;

    #[ORM\Column(length: 1024, nullable: true)]
    protected ?string $coverCreditUrl = null;

    /**
     * Où couper la photo, en pourcentage de sa hauteur.
     *
     * Un bandeau montre une bande d'une image qui n'a pas été cadrée pour
     * ça : sans ce réglage, une photo de portrait montre un front ou un
     * menton, jamais un visage.
     */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 50])]
    protected int $coverPosition = 50;

    /** {@see NoteAppearanceEnum} - le fond de la note et son encre. */
    #[ORM\Column(length: 20, options: ['default' => 'plain'])]
    protected string $appearance = NoteAppearanceEnum::Plain->value;

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

    /** Borné ici plutôt qu'au bord : c'est un pourcentage, rien d'autre. */
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
