<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Taxonomy\Serializer;

use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTermInterface;

interface TaxonomyTermSerializerInterface
{
    /**
     * One locale's view of a term - what a reader sees.
     *
     * @return array<string, mixed>
     */
    public function serialize(TaxonomyTermInterface $term, ?string $locale = null): array;

    /**
     * Every locale of a term - what the editor edits.
     *
     * @return array<string, mixed>
     */
    public function serializeFull(TaxonomyTermInterface $term): array;
}
