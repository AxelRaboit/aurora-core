<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Taxonomy\View;

use Aurora\Core\Locale\Service\LocaleContextInterface;
use Aurora\Module\Editorial\PostType\Repository\PostTypeRepository;
use Aurora\Module\Editorial\PostType\Serializer\PostTypeSerializerInterface;
use Aurora\Module\Editorial\Taxonomy\Repository\TaxonomyRepository;
use Aurora\Module\Editorial\Taxonomy\Serializer\TaxonomySerializerInterface;

/**
 * Builds the Twig payload consumed by the admin taxonomies screen.
 */
final readonly class TaxonomiesViewBuilder
{
    public function __construct(
        private TaxonomyRepository $taxonomyRepository,
        private PostTypeRepository $postTypeRepository,
        private TaxonomySerializerInterface $taxonomySerializer,
        private PostTypeSerializerInterface $postTypeSerializer,
        private LocaleContextInterface $localeContext,
    ) {}

    /**
     * @return array<string, mixed>
     */
    /**
     * The taxonomy a bare `/taxonomies` should send the reader to, or null when
     * there is nothing to send them to.
     */
    public function firstId(): ?int
    {
        $taxonomies = $this->taxonomyRepository->findAllForIndex();

        return [] === $taxonomies ? null : $taxonomies[0]->getId();
    }

    /** @param ?int $activeId the taxonomy the address names */
    public function indexView(?int $activeId = null): array
    {
        return [
            'activeId' => $activeId,
            'taxonomies' => array_map(
                $this->taxonomySerializer->serializeFull(...),
                $this->taxonomyRepository->findAllForIndex(),
            ),
            'postTypes' => array_map(
                $this->postTypeSerializer->serialize(...),
                $this->postTypeRepository->findAllWithRelations(),
            ),
            // The form edits every active locale at once, so it needs the list
            // rather than inferring it from the translations that exist.
            'locales' => $this->localeContext->getActiveLocales(),
        ];
    }
}
