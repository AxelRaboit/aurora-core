<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Taxonomy\Dto;

interface TaxonomyTermInputFactoryInterface
{
    /** @param array<string, mixed> $data */
    public function fromArray(array $data): TaxonomyTermInputInterface;
}
