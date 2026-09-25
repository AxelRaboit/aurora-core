<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Entity;

use Aurora\Core\Storage\Enum\StorageDiskEnum;
use Aurora\Core\Timestampable\TimestampableTrait;
use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategoryInterface;
use Aurora\Module\Ged\DocumentFolder\Entity\DocumentFolderInterface;
use Aurora\Module\Ged\DocumentTag\Entity\DocumentTagInterface;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use Aurora\Module\Ged\Enum\DocumentTransferStateEnum;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class AbstractDocument implements DocumentInterface
{
    use TimestampableTrait;

    #[ORM\Column(length: 64, unique: true, nullable: true)]
    protected ?string $reference = null;

    #[ORM\Column(length: 200)]
    protected string $title;

    /**
     * When the document was moved to the trash.
     *
     * Null for a document in the library. The file on disk is deliberately
     * left alone while this is set: a trashed document must be restorable,
     * and deleting the bytes at the same time as the row would make the
     * restore a promise nothing can keep. The bytes go when the purge does.
     */
    #[ORM\Column(nullable: true)]
    protected ?DateTimeImmutable $deletedAt = null;

    /**
     * The folder whose deletion took this document down with it.
     *
     * Null when it was trashed on its own. Restoring a folder brings back what
     * carries its id here, so a document deleted by hand last week stays where
     * its owner put it.
     */
    #[ORM\Column(nullable: true)]
    protected ?int $trashedWithFolderId = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    protected ?string $description = null;

    #[ORM\Column(length: 20, enumType: DocumentStatusEnum::class)]
    protected DocumentStatusEnum $status = DocumentStatusEnum::Draft;

    #[ORM\ManyToOne(targetEntity: DocumentCategoryInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?DocumentCategoryInterface $category = null;

    // ── Self-owned file storage ──────────────────────────────────────────
    // Documents are stored under `var/uploads/ged/documents/Y/m/<file>`
    // and served via the `/uploads/{path}` catch-all. No coupling to the
    // Media library - GED owns its own physical files so it can evolve
    // its own retention / encryption / versioning policies later.

    /** Relative path within var/uploads/ (e.g. ged/documents/2026/05/contract-abc.pdf). */
    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $filePath = null;

    /**
     * Which backend actually holds the bytes of `filePath`.
     *
     * Carried per document rather than read from the settings, because the
     * setting says where the NEXT file goes and says nothing about this one.
     * Without this column, switching a backend would strand every file written
     * before the switch, and there would be no way to move a document from one
     * side to the other and back.
     */
    #[ORM\Column(length: 20, enumType: StorageDiskEnum::class, options: ['default' => 'local'])]
    protected StorageDiskEnum $storageDisk = StorageDiskEnum::Local;

    /**
     * Where a move between backends stands, and the lock that guards it.
     * See {@see DocumentTransferStateEnum}.
     */
    #[ORM\Column(length: 20, enumType: DocumentTransferStateEnum::class, options: ['default' => 'idle'])]
    protected DocumentTransferStateEnum $storageTransferState = DocumentTransferStateEnum::Idle;

    /**
     * Why the last move gave up, in the words the backend used.
     *
     * Shown to whoever pressed the button. A move that fails silently is a
     * button that appears broken, and the message a storage API returns is
     * usually the only clue about which of the many possible causes it was.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    protected ?string $storageTransferError = null;

    /** Filename on disk (slug + extension). */
    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $fileName = null;

    /** Filename the user originally uploaded - kept for the Content-Disposition header on download. */
    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $originalName = null;

    #[ORM\Column(length: 100, nullable: true)]
    protected ?string $mimeType = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    protected ?int $size = null;

    /** Pixel dimensions - populated at upload for image documents only. */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    protected ?int $width = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    protected ?int $height = null;

    /**
     * Relative path of a generated thumbnail (e.g. ged/thumbnails/2026/05/contract-abc.jpg).
     * For PDFs and similar opaque formats, produced server-side at upload time so
     * the list/preview UIs can render a real image instead of a generic icon.
     * For native image MIMEs (jpg/png/webp), this stays null and the serializer
     * falls back on `filePath` itself.
     */
    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $thumbnailPath = null;

    /** Alternative text - accessibility/SEO, only meaningful for image documents. */
    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $alt = null;

    /** Optional caption shown alongside the document (image documents). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    protected ?string $caption = null;

    /**
     * Focal point - normalized [0, 1] coordinates anchoring the visually-important
     * area of an image. Consumed by `DocumentUrlGenerator::focalPositionCss()` to
     * drive `object-position` in the frontend renderer. `null` = center.
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    protected ?float $focalX = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    protected ?float $focalY = null;

    /**
     * Map of variant name → relative path under var/uploads/ (e.g.
     * `['thumbnail' => 'ged/.../variants/thumbnail/foo.webp', 'medium' => …]`).
     * Generated server-side at upload / crop for raster images. Empty for
     * non-image documents.
     *
     * @var array<string, string>
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    protected array $variants = [];

    /**
     * What the camera wrote in the photograph: body, lens, aperture, speed,
     * sensitivity, focal length, date. Read at upload, **before** the variant
     * generator re-encodes the JPEG and drops its metadata - after that the
     * file no longer knows. Position is deliberately not kept: a picture
     * published on a page should not say where its author lives.
     *
     * @var array<string, string>
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    protected array $exif = [];

    // ── Provenance ───────────────────────────────────────────────────────
    // Where a document came from, when it did not come from someone's disk.
    // Null for an upload, which is the ordinary case; set for a picture
    // imported from a stock photo library.
    //
    // The file itself is ours either way - it is downloaded at import and
    // stored like any other. These three columns do not change how it is
    // served; they answer two questions the file cannot. Whose photograph is
    // this, which the credit under the picture is rendered from, and where
    // did it come from, which is the only way to trace a library entry back
    // a year later or to honour a takedown.

    /** Address the picture was fetched from. Null for an upload. */
    #[ORM\Column(length: 1024, nullable: true)]
    protected ?string $sourceUrl = null;

    /** Photographer's name, as the provider gives it. */
    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $attributionName = null;

    /** Link back to the author's profile, credited beside the picture. */
    #[ORM\Column(length: 1024, nullable: true)]
    protected ?string $attributionUrl = null;

    /** @var Collection<int, DocumentTagInterface> */
    protected Collection $tags;

    #[ORM\ManyToOne(targetEntity: DocumentFolderInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?DocumentFolderInterface $folder = null;

    public function __construct()
    {
        $this->tags = new ArrayCollection();
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): static
    {
        $this->reference = $reference;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getStatus(): DocumentStatusEnum
    {
        return $this->status;
    }

    public function setStatus(DocumentStatusEnum $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getCategory(): ?DocumentCategoryInterface
    {
        return $this->category;
    }

    public function setCategory(?DocumentCategoryInterface $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getFilePath(): ?string
    {
        return $this->filePath;
    }

    public function setFilePath(?string $filePath): static
    {
        $this->filePath = $filePath;

        return $this;
    }

    public function getStorageDisk(): StorageDiskEnum
    {
        return $this->storageDisk;
    }

    public function setStorageDisk(StorageDiskEnum $storageDisk): static
    {
        $this->storageDisk = $storageDisk;

        return $this;
    }

    public function getStorageTransferState(): DocumentTransferStateEnum
    {
        return $this->storageTransferState;
    }

    public function setStorageTransferState(DocumentTransferStateEnum $state): static
    {
        $this->storageTransferState = $state;

        return $this;
    }

    public function getStorageTransferError(): ?string
    {
        return $this->storageTransferError;
    }

    public function setStorageTransferError(?string $error): static
    {
        $this->storageTransferError = $error;

        return $this;
    }

    public function getFileName(): ?string
    {
        return $this->fileName;
    }

    public function setFileName(?string $fileName): static
    {
        $this->fileName = $fileName;

        return $this;
    }

    public function getOriginalName(): ?string
    {
        return $this->originalName;
    }

    public function setOriginalName(?string $originalName): static
    {
        $this->originalName = $originalName;

        return $this;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function setMimeType(?string $mimeType): static
    {
        $this->mimeType = $mimeType;

        return $this;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function setSize(?int $size): static
    {
        $this->size = $size;

        return $this;
    }

    public function getWidth(): ?int
    {
        return $this->width;
    }

    public function setWidth(?int $width): static
    {
        $this->width = $width;

        return $this;
    }

    public function getHeight(): ?int
    {
        return $this->height;
    }

    public function setHeight(?int $height): static
    {
        $this->height = $height;

        return $this;
    }

    public function getThumbnailPath(): ?string
    {
        return $this->thumbnailPath;
    }

    public function setThumbnailPath(?string $thumbnailPath): static
    {
        $this->thumbnailPath = $thumbnailPath;

        return $this;
    }

    public function getAlt(): ?string
    {
        return $this->alt;
    }

    public function setAlt(?string $alt): static
    {
        $this->alt = $alt;

        return $this;
    }

    public function getCaption(): ?string
    {
        return $this->caption;
    }

    public function setCaption(?string $caption): static
    {
        $this->caption = $caption;

        return $this;
    }

    public function getTags(): Collection
    {
        return $this->tags;
    }

    public function addTag(DocumentTagInterface $tag): static
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
        }

        return $this;
    }

    public function removeTag(DocumentTagInterface $tag): static
    {
        $this->tags->removeElement($tag);

        return $this;
    }

    public function clearTags(): static
    {
        $this->tags->clear();

        return $this;
    }

    public function getFolder(): ?DocumentFolderInterface
    {
        return $this->folder;
    }

    public function setFolder(?DocumentFolderInterface $folder): static
    {
        $this->folder = $folder;

        return $this;
    }

    public function getFocalX(): ?float
    {
        return $this->focalX;
    }

    public function setFocalX(?float $focalX): static
    {
        $this->focalX = $focalX;

        return $this;
    }

    public function getFocalY(): ?float
    {
        return $this->focalY;
    }

    public function setFocalY(?float $focalY): static
    {
        $this->focalY = $focalY;

        return $this;
    }

    /** @return array<string, string> */
    public function getVariants(): array
    {
        return $this->variants;
    }

    /** @param array<string, string> $variants */
    public function setVariants(array $variants): static
    {
        $this->variants = $variants;

        return $this;
    }

    /** @return array<string, string> */
    public function getExif(): array
    {
        return $this->exif;
    }

    /** @param array<string, string> $exif */
    public function setExif(array $exif): static
    {
        $this->exif = $exif;

        return $this;
    }

    public function getSourceUrl(): ?string
    {
        return $this->sourceUrl;
    }

    public function setSourceUrl(?string $sourceUrl): static
    {
        $this->sourceUrl = $sourceUrl;

        return $this;
    }

    public function getAttributionName(): ?string
    {
        return $this->attributionName;
    }

    public function setAttributionName(?string $attributionName): static
    {
        $this->attributionName = $attributionName;

        return $this;
    }

    public function getAttributionUrl(): ?string
    {
        return $this->attributionUrl;
    }

    public function setAttributionUrl(?string $attributionUrl): static
    {
        $this->attributionUrl = $attributionUrl;

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
