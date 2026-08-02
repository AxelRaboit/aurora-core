<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Taxonomy\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class TaxonomyInput implements TaxonomyInputInterface
{
    /**
     * @param array<string, array{label?: string, description?: ?string}> $translations
     * @param list<int>                                                   $postTypeIds
     */
    public function __construct(
        #[Assert\NotBlank(message: 'backend.taxonomies.errors.slug_required')]
        #[Assert\Regex(pattern: '/^[a-z0-9_\-]+$/', message: 'backend.taxonomies.errors.slug_format')]
        #[Assert\Length(max: 100)]
        public readonly string $slug,
        public readonly bool $hierarchical,
        #[Assert\Count(min: 1, minMessage: 'backend.taxonomies.errors.translations_required')]
        public readonly array $translations,
        public readonly array $postTypeIds = [],
    ) {}

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function isHierarchical(): bool
    {
        return $this->hierarchical;
    }

    public function getTranslations(): array
    {
        return $this->translations;
    }

    public function getPostTypeIds(): array
    {
        return $this->postTypeIds;
    }
}
