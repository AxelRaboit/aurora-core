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

    /**
     * The translation key naming this status, following the other enums that carry
     * one.
     *
     * Here rather than built by whoever needs it, which is how the global search
     * came to draw `backend.posts.status_options.draft` on screen: `AppSidemenu`
     * lives in Core, concatenated a prefix it had guessed at, and no test noticed
     * because a key assembled at runtime is invisible to the one that checks Vue
     * keys resolve. A key belongs next to the value it names.
     */
    public function getLabelKey(): string
    {
        return 'backend.posts.status.'.$this->value;
    }

    /** Statuses a reader may reach. Everything else is editor-only. */
    public function isPublic(): bool
    {
        return self::Published === $this;
    }
}
