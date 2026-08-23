<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Entity;

use Aurora\Core\Timestampable\TimestampableTrait;
use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategoryInterface;
use Aurora\Module\Ged\DocumentFolder\Entity\DocumentFolderInterface;
use Aurora\Module\Ged\DocumentTag\Entity\DocumentTagInterface;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
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
}
