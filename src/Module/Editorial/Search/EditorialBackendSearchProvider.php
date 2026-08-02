<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Search;

use Aurora\Core\Locale\Service\LocaleContextInterface;
use Aurora\Core\Search\BackendSearchProviderInterface;
use Aurora\Module\Editorial\EditorialContext;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Aurora\Module\Editorial\Post\Service\PostAccessService;
use Throwable;

/**
 * Editorial's slice of the backend global search: posts by title, slug or
 * body, through the same full-text path the posts list uses.
 *
 * Scoped like the list it mirrors — a writer who may only manage their own
 * posts finds only their own here too. A search box that surfaces rows the
 * list refuses to show is a leak, and the surprise lands on whoever clicks
 * the result and gets a 403.
 */
final readonly class EditorialBackendSearchProvider implements BackendSearchProviderInterface
{
    private const int LIMIT = 8;

    public function __construct(
        private PostRepository $postRepository,
        private PostAccessService $postAccessService,
        private EditorialContext $editorialContext,
        private LocaleContextInterface $localeContext,
    ) {}

    public function search(string $query): array
    {
        if (!$this->editorialContext->isPostsEnabled()) {
            return [];
        }

        // The contract says never throw: one module's search failing must not
        // take the whole search box down with it.
        try {
            $locale = $this->localeContext->getDefaultLocale();
            $result = $this->postRepository->findPaginated(
                page: 1,
                locale: $locale,
                limit: self::LIMIT,
                search: $query,
                authorId: $this->postAccessService->scopedAuthorId(),
            );

            return ['posts' => array_map(
                fn (PostInterface $post): array => $this->row($post, $locale),
                $result['items'],
            )];
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<string, mixed> */
    private function row(PostInterface $post, string $locale): array
    {
        $translation = $post->getTranslation($locale) ?? ($post->getTranslations()->first() ?: null);

        return [
            'id' => $post->getId(),
            'title' => $translation?->getTitle(),
            'slug' => $translation?->getSlug(),
            'postType' => $post->getPostType()->getLabel(),
            'status' => $post->getStatus()->value,
        ];
    }
}
