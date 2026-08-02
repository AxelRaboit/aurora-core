<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Comment\Enum;

enum CommentStatusEnum: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Spam = 'spam';

    public function labelKey(): string
    {
        return sprintf('backend.comments.status.%s', $this->value);
    }

    /** The only status a reader ever sees. */
    public function isPublic(): bool
    {
        return self::Approved === $this;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
