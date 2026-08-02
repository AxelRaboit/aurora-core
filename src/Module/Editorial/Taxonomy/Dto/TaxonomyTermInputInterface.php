<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Taxonomy\Dto;

interface TaxonomyTermInputInterface
{
    /** @return array<string, array{name: string, slug: ?string, description: ?string}> */
    public function getTranslations(): array;

    public function getParentId(): ?int;
}
