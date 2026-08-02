<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Taxonomy\Serializer;

use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(TaxonomySerializerInterface::class)]
class TaxonomySerializer implements TaxonomySerializerInterface
{
    public function __construct(
        protected readonly TaxonomyTermSerializerInterface $termSerializer,
    ) {}

    public function serialize(TaxonomyInterface $taxonomy): array
    {
        $translations = [];
        foreach ($taxonomy->getTranslations() as $locale => $translation) {
            $translations[(string) $locale] = [
                'label' => $translation->getLabel(),
                'description' => $translation->getDescription(),
            ];
        }

        return [
            'id' => $taxonomy->getId(),
            'slug' => $taxonomy->getSlug(),
            'hierarchical' => $taxonomy->isHierarchical(),
            'isBuiltIn' => $taxonomy->isBuiltIn(),
            'translations' => $translations,
            'postTypeIds' => array_values(array_filter(
                $taxonomy->getPostTypes()->map(static fn ($postType): ?int => $postType->getId())->toArray(),
            )),
        ];
    }

    public function serializeFull(TaxonomyInterface $taxonomy): array
    {
        return [
            ...$this->serialize($taxonomy),
            'terms' => array_map(
                $this->termSerializer->serializeFull(...),
                $taxonomy->getTerms()->toArray(),
            ),
        ];
    }
}
