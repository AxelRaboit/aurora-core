<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Taxonomy\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class TaxonomyTermInput implements TaxonomyTermInputInterface
{
    /**
     * @param array<string, array{name: string, slug: ?string, description: ?string}> $translations
     */
    public function __construct(
        #[Assert\Count(min: 1, minMessage: 'backend.taxonomies.errors.translations_required')]
        public readonly array $translations,
        public readonly ?int $parentId = null,
    ) {}

    public function getTranslations(): array
    {
        return $this->translations;
    }

    public function getParentId(): ?int
    {
        return $this->parentId;
    }
}
