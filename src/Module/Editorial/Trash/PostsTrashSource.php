<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Trash;

use Aurora\Core\Locale\Service\LocaleContextInterface;
use Aurora\Core\Trash\TrashItem;
use Aurora\Core\Trash\TrashSourceInterface;
use Aurora\Core\Trash\TrashSummary;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Aurora\Module\Editorial\Post\Service\PostAccessService;

/**
 * Publications, scoped exactly as their own listing scopes them.
 *
 * Whoever is neither developer nor administrator sees their own publications
 * and no others, in the trash as in the list. This is the one source where
 * forgetting that would be a leak rather than a detail: the count, the rows
 * and the oldest date all go through the same author filter.
 */
final readonly class PostsTrashSource implements TrashSourceInterface
{
    public function __construct(
        private PostRepository $postRepository,
        private PostAccessService $postAccessService,
        private LocaleContextInterface $localeContext,
    ) {}

    public function getModuleKey(): string
    {
        return 'editorial';
    }

    public function getRequiredPrivilege(): string
    {
        return 'editorial.posts.view';
    }

    public function getSummary(int $limit): TrashSummary
    {
        $locale = $this->localeContext->getDefaultLocale();
        $page = $this->postRepository->findPaginated(
            1,
            $locale,
            $limit,
            trashed: true,
            authorId: $this->postAccessService->scopedAuthorId(),
        );

        return new TrashSummary(
            key: 'editorial_posts',
            labelKey: 'backend.nav.posts',
            icon: 'file-text',
            count: $page['total'],
            items: array_map(fn (PostInterface $post): TrashItem => $this->present($post, $locale), $page['items']),
            oldestDeletedAt: $this->postRepository->oldestTrashedAt(),
            restoreRoute: 'backend_editorial_posts_restore',
            forceDeleteRoute: 'backend_editorial_posts_force_delete',
            emptyTrashRoute: 'backend_editorial_posts_empty_trash',
            actionPrivilege: 'editorial.posts.delete',
        );
    }

    private function present(PostInterface $post, string $locale): TrashItem
    {
        $title = $post->getTranslation($locale)?->getTitle();

        return new TrashItem(
            id: (int) $post->getId(),
            label: null !== $title && '' !== $title ? $title : '#'.$post->getId(),
            deletedAt: $post->getDeletedAt(),
            context: $post->getPostType()->getLabel(),
        );
    }
}
