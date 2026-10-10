<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Nav;

use Aurora\Core\Module\Nav\NavItemCountProviderInterface;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Aurora\Module\Editorial\Post\Service\PostAccessService;
use Aurora\Module\Editorial\PostType\Repository\PostTypeRepository;
use Aurora\Module\Editorial\Taxonomy\Repository\TaxonomyRepository;

/**
 * The side menu's figures for Editorial: publications, content types and
 * taxonomies - the three counts the dashboard's Editorial tiles already show.
 *
 * Publications are counted with the list's own scope, so a contributor reads
 * the number of their own posts. Comments, forms and menus are left out: a
 * total of comments would read as "to moderate" when most are long approved,
 * and the other two are settings more than collections.
 */
final readonly class EditorialNavItemCountProvider implements NavItemCountProviderInterface
{
    private const string POSTS = 'suite_editorial_posts';
    private const string POST_TYPES = 'suite_editorial_post_types';
    private const string TAXONOMIES = 'suite_editorial_taxonomies';

    public function __construct(
        private PostRepository $postRepository,
        private PostAccessService $postAccessService,
        private PostTypeRepository $postTypeRepository,
        private TaxonomyRepository $taxonomyRepository,
    ) {}

    public function getCountedItemKeys(): array
    {
        return [self::POSTS, self::POST_TYPES, self::TAXONOMIES];
    }

    public function countItem(string $itemKey): int
    {
        return match ($itemKey) {
            self::POSTS => $this->postRepository->countNotTrashed($this->postAccessService->scopedAuthorId()),
            self::POST_TYPES => $this->postTypeRepository->count([]),
            self::TAXONOMIES => $this->taxonomyRepository->count([]),
            default => 0,
        };
    }
}
