<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Entity;

use Aurora\Core\Storage\Enum\StorageDiskEnum;
use Aurora\Core\Timestampable\TimestampableInterface;
use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategoryInterface;
use Aurora\Module\Ged\DocumentFolder\Entity\DocumentFolderInterface;
use Aurora\Module\Ged\DocumentTag\Entity\DocumentTagInterface;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use Aurora\Module\Ged\Enum\DocumentTransferStateEnum;
use DateTimeImmutable;
use Doctrine\Common\Collections\Collection;

interface DocumentInterface extends TimestampableInterface
{
    public function getId(): ?int;

    public function getReference(): ?string;

    public function setReference(?string $reference): static;

    public function getTitle(): string;

    public function setTitle(string $title): static;

    public function getDescription(): ?string;

    public function setDescription(?string $description): static;

    public function getStatus(): DocumentStatusEnum;

    public function setStatus(DocumentStatusEnum $status): static;

    public function getCategory(): ?DocumentCategoryInterface;

    public function setCategory(?DocumentCategoryInterface $category): static;

    public function getFilePath(): ?string;

    public function setFilePath(?string $filePath): static;

    public function getStorageDisk(): StorageDiskEnum;

    public function setStorageDisk(StorageDiskEnum $storageDisk): static;

    public function getStorageTransferState(): DocumentTransferStateEnum;

    public function setStorageTransferState(DocumentTransferStateEnum $state): static;

    public function getStorageTransferError(): ?string;

    public function setStorageTransferError(?string $error): static;

    public function getFileName(): ?string;

    public function setFileName(?string $fileName): static;

    public function getOriginalName(): ?string;

    public function setOriginalName(?string $originalName): static;

    public function getMimeType(): ?string;

    public function setMimeType(?string $mimeType): static;

    public function getSize(): ?int;

    public function setSize(?int $size): static;

    public function getWidth(): ?int;

    public function setWidth(?int $width): static;

    public function getHeight(): ?int;

    public function setHeight(?int $height): static;

    public function getThumbnailPath(): ?string;

    public function setThumbnailPath(?string $thumbnailPath): static;

    public function getAlt(): ?string;

    public function setAlt(?string $alt): static;

    public function getCaption(): ?string;

    public function setCaption(?string $caption): static;

    /** @return Collection<int, DocumentTagInterface> */
    public function getTags(): Collection;

    public function addTag(DocumentTagInterface $tag): static;

    public function removeTag(DocumentTagInterface $tag): static;

    public function clearTags(): static;

    public function getFolder(): ?DocumentFolderInterface;

    public function setFolder(?DocumentFolderInterface $folder): static;

    public function getFocalX(): ?float;

    public function setFocalX(?float $focalX): static;

    public function getFocalY(): ?float;

    public function setFocalY(?float $focalY): static;

    /** @return array<string, string> */
    public function getVariants(): array;

    /** @param array<string, string> $variants */
    public function setVariants(array $variants): static;

    /** @return array<string, string> the camera settings read from the file at upload */
    public function getExif(): array;

    /** @param array<string, string> $exif */
    public function setExif(array $exif): static;

    public function getSourceUrl(): ?string;

    public function setSourceUrl(?string $sourceUrl): static;

    public function getAttributionName(): ?string;

    public function setAttributionName(?string $attributionName): static;

    public function getAttributionUrl(): ?string;

    public function setAttributionUrl(?string $attributionUrl): static;

    public function getDeletedAt(): ?DateTimeImmutable;

    public function setDeletedAt(?DateTimeImmutable $deletedAt): static;

    /** Whether this document sits in the trash rather than in the library. */
    public function isTrashed(): bool;

    /** The folder whose deletion took this document down, if any. */
    public function getTrashedWithFolderId(): ?int;

    public function setTrashedWithFolderId(?int $trashedWithFolderId): static;
}
