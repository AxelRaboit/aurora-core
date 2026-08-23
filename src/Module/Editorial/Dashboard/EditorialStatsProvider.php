<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Dashboard;

use Aurora\Core\Dashboard\DashboardStatsProviderInterface;
use Aurora\Module\Editorial\Comment\Repository\CommentRepository;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Aurora\Module\Editorial\PostType\Repository\PostTypeRepository;
use Aurora\Module\Editorial\Taxonomy\Repository\TaxonomyRepository;

/**
 * Editorial's figures on the backend dashboard.
 *
 * Deliberately unscoped by author, unlike the posts list: this answers "how
 * much content does the site hold", a question about the site rather than
 * about the reader. The panel is behind `editorial.posts.view` all the same.
 */
final readonly class EditorialStatsProvider implements DashboardStatsProviderInterface
{
    public function __construct(
        private PostRepository $postRepository,
        private CommentRepository $commentRepository,
        private PostTypeRepository $postTypeRepository,
        private TaxonomyRepository $taxonomyRepository,
    ) {}

    public function getModuleKey(): string
    {
        return 'editorial';
    }

    public function getStats(): array
    {
        $byStatus = $this->postRepository->countByStatus();

        // Every status appears, including the ones with nothing in them: a
        // panel whose rows come and go as content changes is harder to read
        // than one with a steady shape and a few zeroes.
        $statuses = [];
        foreach (PostStatusEnum::cases() as $case) {
            $statuses[$case->value] = $byStatus[$case->value] ?? 0;
        }

        return [
            'editorial' => [
                'posts' => array_sum($statuses),
                'byStatus' => $statuses,
                'trashed' => $this->postRepository->countTrashed(),
                'postTypes' => count($this->postTypeRepository->findAllWithRelations()),
                'taxonomies' => count($this->taxonomyRepository->findAllForIndex()),
                // `countByStatus()` already fills every status with a zero, for
                // the reason the posts one does: a panel whose rows come and go
                // is harder to read than one with a steady shape.
                'commentsByStatus' => $this->commentRepository->countByStatus(),
            ],
        ];
    }
}
