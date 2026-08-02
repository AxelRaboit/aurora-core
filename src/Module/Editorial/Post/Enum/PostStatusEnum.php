<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Enum;

enum PostStatusEnum: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Archived = 'archived';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /** Statuses a reader may reach. Everything else is editor-only. */
    public function isPublic(): bool
    {
        return self::Published === $this;
    }
}
