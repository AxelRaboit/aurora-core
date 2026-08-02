<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Comment\View;

use Aurora\Module\Editorial\Comment\Enum\CommentStatusEnum;
use Aurora\Module\Editorial\Comment\Repository\CommentRepository;

/**
 * Builds the Twig payload consumed by the admin comments screen.
 */
final readonly class CommentsViewBuilder
{
    public function __construct(private CommentRepository $commentRepository) {}

    /** @return array<string, mixed> */
    public function indexView(): array
    {
        return [
            'counts' => $this->commentRepository->countByStatus(),
            'statuses' => array_map(
                static fn (CommentStatusEnum $case): array => [
                    'value' => $case->value,
                    'labelKey' => $case->labelKey(),
                ],
                CommentStatusEnum::cases(),
            ),
        ];
    }
}
