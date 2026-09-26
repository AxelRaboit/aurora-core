<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Dto;

use Aurora\Module\Ged\Enum\DocumentStatusEnum;

interface DocumentInputInterface
{
    public function getTitle(): string;

    public function getDescription(): ?string;

    public function getStatus(): DocumentStatusEnum;

    public function getCategoryId(): ?int;

    public function getFilePath(): ?string;

    public function getFileName(): ?string;

    public function getOriginalName(): ?string;

    public function getMimeType(): ?string;

    public function getSize(): ?int;

    public function getWidth(): ?int;

    public function getHeight(): ?int;

    public function getThumbnailPath(): ?string;

    public function getAlt(): ?string;

    public function getCaption(): ?string;

    /** @return int[] */
    public function getTagIds(): array;

    public function getFolderId(): ?int;

    public function getFocalX(): ?float;

    public function getFocalY(): ?float;

    public function isKept(): bool;

    public function getOriginalId(): ?int;

    public function getAlternateLabel(): ?string;

    public function getSourceUrl(): ?string;

    public function getAttributionName(): ?string;

    public function getAttributionUrl(): ?string;
}
