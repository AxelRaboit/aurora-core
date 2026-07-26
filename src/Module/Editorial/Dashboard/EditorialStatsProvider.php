<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Dashboard;

use Aurora\Core\Dashboard\DashboardStatsProviderInterface;
use Aurora\Core\Storage\Enum\MimeTypeEnum;
use Aurora\Module\Editorial\Comment\Repository\CommentRepository;
use Aurora\Module\Editorial\Menu\Repository\MenuRepository;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Aurora\Module\Editorial\PostType\Repository\PostTypeRepository;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Platform\User\Repository\UserRepository;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Editorial slice of the backend dashboard — the CMS overview (posts,
 * comments, media library, menus, users, activity). Lives in the Editorial
 * module so the General dashboard never imports Editorial repositories.
 *
 * Reads Ged (media) and Platform (users) repositories too: both are core, so
 * importing them respects the no-sideways-dependency invariant.
 */
final readonly class EditorialStatsProvider implements DashboardStatsProviderInterface
{
    public function __construct(
        private PostRepository $postRepository,
        private PostTypeRepository $postTypeRepository,
        private CommentRepository $commentRepository,
        private MenuRepository $menuRepository,
        private DocumentRepository $documentRepository,
        private UserRepository $userRepository,
    ) {}

    public function getModuleKey(): string
    {
        return 'editorial';
    }

    public function getStats(): array
    {
        return [
            'posts' => $this->getPostStats(),
            'comments' => $this->commentRepository->countByStatus(),
            'media' => $this->getMediaStats(),
            'menus' => ['total' => $this->menuRepository->count([])],
            'users' => ['total' => $this->userRepository->count([])],
            'postsByMonth' => $this->getPostsByMonth(),
            'recentPosts' => $this->getRecentPosts(),
            'postsByAuthor' => $this->getPostsByAuthor(),
            'mediaByType' => $this->getMediaByType(),
        ];
    }

    /** @return array<string, mixed> */
    private function getPostStats(): array
    {
        $countByType = $this->postRepository->countGroupedByPostType();
        $byType = [];
        foreach ($this->postTypeRepository->findAll() as $type) {
            $byType[] = [
                'slug' => $type->getSlug(),
                'label' => $type->getLabel(),
                'count' => $countByType[$type->getId()] ?? 0,
            ];
        }

        $byStatus = $this->postRepository->countGroupedByStatus();

        return [
            'total' => $this->postRepository->count([]),
            'published' => $byStatus[PostStatusEnum::Published->value] ?? 0,
            'draft' => $byStatus[PostStatusEnum::Draft->value] ?? 0,
            'pendingReview' => $byStatus[PostStatusEnum::PendingReview->value] ?? 0,
            'scheduled' => $byStatus[PostStatusEnum::Scheduled->value] ?? 0,
            'archived' => $byStatus[PostStatusEnum::Archived->value] ?? 0,
            'trashed' => $this->postRepository->countTrashed(),
            'byType' => $byType,
        ];
    }

    /** @return array<string, mixed> */
    private function getMediaStats(): array
    {
        return [
            'total' => $this->documentRepository->count([]),
            'totalSize' => $this->documentRepository->getTotalStorageSize(),
        ];
    }

    /**
     * @return array<int, array{month: string, count: int}>
     */
    private function getPostsByMonth(): array
    {
        $since = new DateTimeImmutable('-5 months')->modify('first day of this month')->setTime(0, 0);
        $monthCountMap = $this->postRepository->countByMonthSince($since);

        $result = [];
        for ($monthOffset = 5; $monthOffset >= 0; --$monthOffset) {
            $monthKey = new DateTimeImmutable(sprintf('-%d months', $monthOffset))->format('Y-m');
            $result[] = ['month' => $monthKey, 'count' => $monthCountMap[$monthKey] ?? 0];
        }

        return $result;
    }

    /** @return array<int, array{label: string, count: int}> */
    private function getPostsByAuthor(): array
    {
        $countByAuthor = $this->postRepository->countGroupedByAuthor();

        $result = [];
        foreach ($countByAuthor as $authorId => $count) {
            $author = $authorId > 0 ? $this->userRepository->find($authorId) : null;
            $result[] = ['label' => $author?->getName() ?? '—', 'count' => $count];
        }

        usort($result, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $result;
    }

    /**
     * Buckets documents into the same image/pdf/other categories as the
     * GED document type filter (`backend.ged.documents.type_*`), since
     * {@see MimeTypeEnum} only distinguishes those.
     *
     * @return array<int, array{key: string, count: int}>
     */
    private function getMediaByType(): array
    {
        $buckets = ['image' => 0, 'pdf' => 0, 'other' => 0];

        foreach ($this->documentRepository->countGroupedByMimeType() as $mime => $count) {
            $enum = MimeTypeEnum::tryFrom((string) $mime);
            $key = match (true) {
                $enum?->isImage() => 'image',
                MimeTypeEnum::Pdf === $enum => 'pdf',
                default => 'other',
            };
            $buckets[$key] += $count;
        }

        $result = [];
        foreach ($buckets as $key => $count) {
            if ($count > 0) {
                $result[] = ['key' => $key, 'count' => $count];
            }
        }

        return $result;
    }

    /**
     * @return array<int, array{id: int, title: string, status: string, updatedAt: string, postType: string}>
     */
    private function getRecentPosts(): array
    {
        $result = [];
        foreach ($this->postRepository->findRecent(5) as $post) {
            $firstTranslation = $post->getTranslations()->first() ?: null;
            $result[] = [
                'id' => $post->getId(),
                'title' => $firstTranslation ? $firstTranslation->getTitle() : '(sans titre)',
                'status' => $post->getStatus()->value,
                'updatedAt' => $post->getUpdatedAt()->format(DateTimeInterface::ATOM),
                'postType' => $post->getPostType()->getLabel(),
            ];
        }

        return $result;
    }
}
