<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Manager;

use Aurora\Module\Ged\Document\Dto\DocumentInputInterface;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategoryInterface;
use Aurora\Module\Ged\DocumentFolder\Entity\DocumentFolderInterface;
use DateTimeImmutable;

interface DocumentManagerInterface
{
    public function create(DocumentInputInterface $input): DocumentInterface;

    public function update(DocumentInterface $document, DocumentInputInterface $input): void;

    /** Moves a document to the trash: the row and its bytes both stay. */
    public function delete(DocumentInterface $document): void;

    /** Brings a trashed document back into the library. */
    public function restore(DocumentInterface $document): void;

    /** Deletes a document for good, bytes included. */
    public function forceDelete(DocumentInterface $document): void;

    /** Destroys everything currently in the trash. Returns how many went. */
    public function emptyTrash(): int;

    /** Destroys what has been in the trash since before `$cutoff`. */
    public function purgeTrashedBefore(DateTimeImmutable $cutoff): int;

    /** Moves a document into a folder (or null = root). */
    public function move(DocumentInterface $document, ?DocumentFolderInterface $folder): void;

    /** @param list<int> $ids */
    public function bulkMove(array $ids, ?DocumentFolderInterface $folder): void;

    /**
     * Files a selection under one category, or under none. Returns how many
     * documents actually changed.
     *
     * @param list<int> $ids
     */
    public function bulkCategorize(array $ids, ?DocumentCategoryInterface $category): int;

    /** @param list<int> $ids */
    public function bulkDelete(array $ids): int;

    /** @param list<int> $ids */
    public function bulkRestore(array $ids): int;

    /**
     * Crops an image document to a fresh file and records the result as a new
     * version, preserving the pre-crop original. No-op for non-image documents.
     */
    public function cropImage(DocumentInterface $document, int $x, int $y, int $width, int $height): void;
}
