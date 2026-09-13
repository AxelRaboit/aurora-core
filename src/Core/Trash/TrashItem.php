<?php

declare(strict_types=1);

namespace Aurora\Core\Trash;

use DateTimeImmutable;

/**
 * One deleted thing, as the trash screen needs to show it.
 *
 * Four fields and no entity: the screen lists documents beside notes beside
 * publications, and anything it could only say about one of them would be a
 * column empty on every other row.
 *
 * `context` is the one place a type may be specific - the folder a document
 * was in, the type of a publication, the parent of a note - because "Contrat"
 * on its own does not tell you which of the four you are about to restore.
 */
final readonly class TrashItem
{
    public function __construct(
        public int $id,
        public string $label,
        public ?DateTimeImmutable $deletedAt = null,
        public ?string $context = null,
    ) {}
}
