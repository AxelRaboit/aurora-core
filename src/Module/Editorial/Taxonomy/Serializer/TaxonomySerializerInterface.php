<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Taxonomy\Serializer;

use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyInterface;

interface TaxonomySerializerInterface
{
    /** @return array<string, mixed> */
    public function serialize(TaxonomyInterface $taxonomy): array;

    /** @return array<string, mixed> */
    public function serializeFull(TaxonomyInterface $taxonomy): array;
}
