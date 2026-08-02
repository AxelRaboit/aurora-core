<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Taxonomy\Dto;

interface TaxonomyInputInterface
{
    public function getSlug(): string;

    public function isHierarchical(): bool;

    /** @return array<string, array{label?: string, description?: ?string}> */
    public function getTranslations(): array;

    /** @return list<int> */
    public function getPostTypeIds(): array;
}
