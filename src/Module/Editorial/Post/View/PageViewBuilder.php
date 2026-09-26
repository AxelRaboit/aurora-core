<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\View;

use Aurora\Core\Frontend\Service\Context;
use Aurora\Module\Configuration\Theme\Service\ThemeContext;
use Aurora\Module\Editorial\Post\Banner\BannerViewBuilder;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Entity\PostTranslationInterface;
use Aurora\Module\Editorial\Post\Grid\GridViewBuilder;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
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
        private BannerViewBuilder $bannerViewBuilder,
        private GridViewBuilder $gridViewBuilder,
        private PostRepository $postRepository,
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
                // What the page calls itself: the label names one publication,
                // and a page listing them all is not called by the singular.
                'heading' => $postType->getArchiveHeading(),
                // The header this page borrows, built by the same builder the
                // publication's own page uses - so the listing page gets the
                // whole banner, its settings and its words in this language,
                // rather than a poorer copy of one.
                'banner' => $this->archiveBanner($postType, $locale),
                // And its summary, which is what search engines read. Borrowed
                // from the same publication for the same reason: a listing
                // page that carries someone else's header and the site's
                // default description is a page describing itself by accident.
                'description' => $this->archiveDescription($postType, $locale),
                // And its content grid, which is what turns a listing page
                // into a page somebody composed: a card beside the words that
                // sell it, rather than a row of cards and nothing else.
                'grid' => $this->archiveGrid($postType, $locale),
                // Whether the automatic list still follows. It should on an
                // archive of articles, where the composed part is an
                // introduction; it should not on one whose composed part
                // already places every entry, or each would appear twice.
                'showsList' => $postType->archiveShowsList(),
            ],
            'alternates' => $this->alternatesBuilder->forRoute('editorial_archive', ['postTypeSlug' => $postType->getSlug()]),
        ];
    }

    /**
     * The content that publication carries, in this language.
     *
     * Null on every "no" the header gets, and one more: a publication with an
     * empty grid. Built by the same builder the publication's own page uses,
     * so a zone behaves the same on both.
     *
     * @return array<string, mixed>|null
     */
    private function archiveGrid(PostTypeInterface $postType, string $locale): ?array
    {
        $translation = $this->archivePostTranslation($postType, $locale);

        if (!$translation instanceof PostTranslationInterface) {
            return null;
        }

        return $this->gridViewBuilder->build(
            $translation->getPost()->getGridLayout(),
            $translation->getGrid(),
            $locale,
            $translation->getPost()->getId(),
        );
    }

    /**
     * The summary that publication carries, in this language.
     *
     * Null when there is nothing to borrow, which leaves the page on the
     * site's own description - the answer it has always had.
     */
    private function archiveDescription(PostTypeInterface $postType, string $locale): ?string
    {
        $translation = $this->archivePostTranslation($postType, $locale);
        $description = $translation?->getDescription();

        return null !== $description && '' !== $description ? $description : null;
    }

    /**
     * The header a listing page borrows from a publication.
     *
     * Null on every "no" - none designated, deleted since, unpublished, not
     * translated here, or its banner switched off - so the template falls back
     * to the plain title header it has always had rather than drawing an empty
     * frame. An unpublished publication is a draft: a listing page is not a
     * way to show one.
     *
     * The same resolution the homepage gets, and for the same reason: the
     * publication is named by an id, so every one of those answers has to be
     * asked here rather than assumed from the fact that a number was stored.
     *
     * @return array<string, mixed>|null
     */
    private function archiveBanner(PostTypeInterface $postType, string $locale): ?array
    {
        $translation = $this->archivePostTranslation($postType, $locale);

        if (!$translation instanceof PostTranslationInterface) {
            return null;
        }

        return $this->bannerViewBuilder->build(
            $translation->getPost()->getBannerLayout(),
            $translation->getBanner(),
        );
    }

    /**
     * The designated publication's translation, or nothing.
     *
     * Every refusal lives here, once: none designated, deleted since,
     * unpublished, or silent in the language being read. Both the header and
     * the summary ask the same question, and asking it twice in two places is
     * how they would come to disagree.
     */
    private function archivePostTranslation(PostTypeInterface $postType, string $locale): ?PostTranslationInterface
    {
        $postId = $postType->getArchivePostId();

        if (null === $postId) {
            return null;
        }

        $post = $this->postRepository->find($postId);

        if (!$post instanceof PostInterface || !$post->isPublished() || $post->isTrashed()) {
            return null;
        }

        $translation = $post->getTranslation($locale);

        return $translation instanceof PostTranslationInterface ? $translation : null;
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
        $this->postRepository->warmCards($result['items']);

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
