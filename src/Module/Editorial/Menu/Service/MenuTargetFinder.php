<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Menu\Service;

use Aurora\Module\Editorial\Menu\Enum\MenuItemTargetTypeEnum;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Aurora\Module\Editorial\PostType\Repository\PostTypeRepository;
use Aurora\Module\Editorial\Taxonomy\Repository\TaxonomyRepository;

/**
 * What the "point at…" picker offers, per target type.
 *
 * Searched rather than listed: a site with two thousand posts cannot ship
 * them all into the form, and the editor knows the title they are after.
 */
final readonly class MenuTargetFinder
{
    private const int LIMIT = 20;

    public function __construct(
        private PostRepository $postRepository,
        private TaxonomyRepository $taxonomyRepository,
        private PostTypeRepository $postTypeRepository,
    ) {}

    /** @return list<array{id: int, label: string, hint: ?string}> */
    public function search(MenuItemTargetTypeEnum $targetType, string $query, string $locale): array
    {
        return match ($targetType) {
            MenuItemTargetTypeEnum::Post => $this->posts($query, $locale),
            MenuItemTargetTypeEnum::Term => $this->terms($query, $locale),
            MenuItemTargetTypeEnum::PostTypeArchive => $this->archives($query),
            default => [],
        };
    }

    /** @return list<array{id: int, label: string, hint: ?string}> */
    private function posts(string $query, string $locale): array
    {
        $result = $this->postRepository->findPaginated(
            page: 1,
            locale: $locale,
            limit: self::LIMIT,
            search: '' !== $query ? $query : null,
        );

        $options = [];
        foreach ($result['items'] as $post) {
            $translation = $post->getTranslation($locale) ?? ($post->getTranslations()->first() ?: null);

            $options[] = [
                'id' => (int) $post->getId(),
                'label' => $translation?->getTitle() ?? '',
                'hint' => $post->getPostType()->getLabel(),
            ];
        }

        return $options;
    }

    /**
     * Terms are few enough to filter in PHP, and doing so keeps the taxonomy
     * name in the hint without a second query per row.
     *
     * @return list<array{id: int, label: string, hint: ?string}>
     */
    private function terms(string $query, string $locale): array
    {
        $needle = mb_strtolower($query);
        $options = [];

        foreach ($this->taxonomyRepository->findAllForIndex() as $taxonomy) {
            $taxonomyLabel = $taxonomy->getTranslation($locale)?->getLabel() ?? $taxonomy->getSlug();

            foreach ($taxonomy->getTerms() as $term) {
                $name = ($term->getTranslation($locale) ?? ($term->getTranslations()->first() ?: null))?->getName();
                if (null === $name) {
                    continue;
                }

                if ('' !== $needle && !str_contains(mb_strtolower((string) $name), $needle)) {
                    continue;
                }

                $options[] = ['id' => (int) $term->getId(), 'label' => $name, 'hint' => $taxonomyLabel];

                if (count($options) >= self::LIMIT) {
                    return $options;
                }
            }
        }

        return $options;
    }

    /**
     * Only types that actually have an archive page — offering the others
     * would let an editor build a link that resolves to nothing.
     *
     * @return list<array{id: int, label: string, hint: ?string}>
     */
    private function archives(string $query): array
    {
        $needle = mb_strtolower($query);
        $options = [];

        foreach ($this->postTypeRepository->findAllWithRelations() as $postType) {
            if (!$postType->hasArchive()) {
                continue;
            }

            if ('' !== $needle && !str_contains(mb_strtolower($postType->getLabel()), $needle)) {
                continue;
            }

            $options[] = ['id' => (int) $postType->getId(), 'label' => $postType->getLabel(), 'hint' => $postType->getSlug()];
        }

        return $options;
    }
}
