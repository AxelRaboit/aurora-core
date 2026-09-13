<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\DocumentCategory\Manager;

use Aurora\Module\Ged\DocumentCategory\Dto\DocumentCategoryInputInterface;
use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategoryInterface;
use DateTimeImmutable;

interface DocumentCategoryManagerInterface
{
    public function create(DocumentCategoryInputInterface $input): DocumentCategoryInterface;

    public function update(DocumentCategoryInterface $category, DocumentCategoryInputInterface $input): void;

    /** Moves a category to the trash, documents still pointing at it. */
    public function delete(DocumentCategoryInterface $category): void;

    /** Brings a trashed category back, under a slug that is free again. */
    public function restore(DocumentCategoryInterface $category): void;

    /** Deletes a category for good: its documents lose it. */
    public function forceDelete(DocumentCategoryInterface $category): void;

    /** Destroys every category in the trash. Returns how many went. */
    public function emptyTrash(): int;

    /** @return int how many categories were destroyed */
    public function purgeTrashedBefore(DateTimeImmutable $cutoff): int;
}
