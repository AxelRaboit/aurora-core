<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\View;

use Aurora\Core\Frontend\Service\Context;
use Aurora\Module\Configuration\Theme\Service\ThemeContext;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Serializer\PostSerializerInterface;
use Aurora\Module\Editorial\PostType\Entity\PostTypeInterface;
use Aurora\Module\Editorial\Seo\Service\AlternatesBuilder;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyInterface;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTermInterface;

/**
 * Payloads for the public listing pages. The single-post page has its own
 * renderer, since a failed comment has to re-render it.
 */
final readonly class PageViewBuilder
{
    public function __construct(
        private PostSerializerInterface $postSerializer,
        private AlternatesBuilder $alternatesBuilder,
        private Context $context,
        private ThemeContext $themeContext,
    ) {}

    /**
     * @param array{items: list<PostInterface>, total: int, page: int, totalPages: int} $result
     *
     * @return array<string, mixed>
     */
    public function homeView(string $locale, array $result, ?PostTypeInterface $postType, string $searchPath): array
    {
        return [
            ...$this->shared($locale),
            ...$this->pageData($result, $locale),
            'postTypeSlug' => $postType?->getSlug(),
            'searchPath' => $searchPath,
            'alternates' => $this->alternatesBuilder->forRoute('editorial_home'),
        ];
    }

    /**
     * @param array{items: list<PostInterface>, total: int, page: int, totalPages: int} $result
     *
     * @return array<string, mixed>
     */
    public function archiveView(string $locale, PostTypeInterface $postType, array $result): array
    {
        return [
            ...$this->shared($locale),
            ...$this->pageData($result, $locale),
            'postType' => [
                'slug' => $postType->getSlug(),
                'label' => $postType->getLabel(),
            ],
            'alternates' => $this->alternatesBuilder->forRoute('editorial_archive', ['postTypeSlug' => $postType->getSlug()]),
        ];
    }

    /**
     * @param array{items: list<PostInterface>, total: int, page: int, totalPages: int} $result
     *
     * @return array<string, mixed>
     */
    public function termView(string $locale, TaxonomyInterface $taxonomy, TaxonomyTermInterface $term, array $result): array
    {
        return [
            ...$this->shared($locale),
            ...$this->pageData($result, $locale),
            'taxonomy' => [
                'slug' => $taxonomy->getSlug(),
                'label' => $taxonomy->getTranslation($locale)?->getLabel() ?? $taxonomy->getSlug(),
            ],
            'term' => [
                'name' => $term->getTranslation($locale)?->getName(),
                'slug' => $term->getTranslation($locale)?->getSlug(),
                'description' => $term->getTranslation($locale)?->getDescription(),
            ],
            'alternates' => $this->alternatesBuilder->forTerm($taxonomy, $term),
        ];
    }

    /**
     * The same shape the page was rendered with, so the search endpoint and
     * the first render cannot drift apart.
     *
     * @param array{items: list<PostInterface>, total: int, page: int, totalPages: int} $result
     *
     * @return array<string, mixed>
     */
    public function pageData(array $result, string $locale): array
    {
        return [
            'posts' => array_map(
                fn (PostInterface $post): array => $this->postSerializer->serializeCard($post, $locale),
                $result['items'],
            ),
            'total' => $result['total'],
            'page' => $result['page'],
            'totalPages' => $result['totalPages'],
        ];
    }

    /** @return array<string, mixed> */
    private function shared(string $locale): array
    {
        return [
            'locale' => $locale,
            'context' => $this->context,
            'themeContext' => $this->themeContext,
        ];
    }
}
