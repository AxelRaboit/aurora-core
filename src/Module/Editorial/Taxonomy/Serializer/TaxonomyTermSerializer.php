<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Taxonomy\Serializer;

use Aurora\Core\Locale\Service\LocaleContextInterface;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTermInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(TaxonomyTermSerializerInterface::class)]
class TaxonomyTermSerializer implements TaxonomyTermSerializerInterface
{
    public function __construct(
        protected readonly LocaleContextInterface $localeContext,
    ) {}

    public function serialize(TaxonomyTermInterface $term, ?string $locale = null): array
    {
        $locale ??= $this->localeContext->getDefaultLocale();
        // Falling back to any translation beats rendering a blank row: a term
        // translated in one locale still needs a name in the others.
        $translation = $term->getTranslation($locale) ?? ($term->getTranslations()->first() ?: null);

        return [
            ...$this->identity($term),
            'reference' => $term->getReference(),
            'name' => $translation?->getName(),
            'slug' => $translation?->getSlug(),
            'description' => $translation?->getDescription(),
        ];
    }

    public function serializeFull(TaxonomyTermInterface $term): array
    {
        $translations = [];
        foreach ($term->getTranslations() as $locale => $translation) {
            $translations[(string) $locale] = [
                'name' => $translation->getName(),
                'slug' => $translation->getSlug(),
                'description' => $translation->getDescription(),
            ];
        }

        return [
            ...$this->identity($term),
            'reference' => $term->getReference(),
            'translations' => $translations,
        ];
    }

    /** @return array<string, mixed> */
    private function identity(TaxonomyTermInterface $term): array
    {
        return [
            'id' => $term->getId(),
            'taxonomyId' => $term->getTaxonomy()->getId(),
            'taxonomySlug' => $term->getTaxonomy()->getSlug(),
            'parentId' => $term->getParent()?->getId(),
            'position' => $term->getPosition(),
        ];
    }
}
