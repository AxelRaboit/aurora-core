<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Bulk;

use Aurora\Module\Editorial\Post\Security\PostVoter;

/**
 * What can be done to a selection at once.
 *
 * A closed list rather than a free string, because each value carries the
 * permission it needs. A bulk endpoint taking an arbitrary verb is one where the
 * permission check lives at the call site, and there is exactly one call site until
 * the day there are two.
 */
enum PostBulkActionEnum: string
{
    case Publish = 'publish';

    case Draft = 'draft';

    case Trash = 'trash';

    case Restore = 'restore';

    case ForceDelete = 'force_delete';

    /**
     * The voter attribute each one needs, per post.
     *
     * Checked for every row rather than once for the request: a selection spans
     * posts with different authors, and "may act on some of these" is the only
     * honest reading of a mixed list.
     */
    public function attribute(): string
    {
        return match ($this) {
            self::Publish => PostVoter::PUBLISH,
            self::Draft => PostVoter::EDIT,
            self::Trash, self::Restore, self::ForceDelete => PostVoter::DELETE,
        };
    }

    /**
     * Whether it acts on the trash rather than on the shelf.
     *
     * Restoring a live post and trashing a trashed one are both nonsense, and
     * doing nothing quietly is better than doing something surprising.
     */
    public function needsTrashed(): bool
    {
        return match ($this) {
            self::Restore, self::ForceDelete => true,
            default => false,
        };
    }

    public function getLabelKey(): string
    {
        return 'backend.posts.bulk.action_'.$this->value;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
