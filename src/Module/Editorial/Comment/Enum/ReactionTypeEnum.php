<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Comment\Enum;

enum ReactionTypeEnum: string
{
    case Like = 'like';
    case Love = 'love';
    case Haha = 'haha';
    case Wow = 'wow';
    case Sad = 'sad';
    case Angry = 'angry';

    public function emoji(): string
    {
        return match ($this) {
            self::Like => '👍',
            self::Love => '❤️',
            self::Haha => '😂',
            self::Wow => '😮',
            self::Sad => '😢',
            self::Angry => '😡',
        };
    }

    /**
     * A key, not a word. The reference returned French literals here, so an
     * English reader hovering a reaction was told "J'adore" - on the public
     * site, where the visitor has no say in the matter.
     */
    public function labelKey(): string
    {
        return sprintf('frontend.editorial.comments.reactions.%s', $this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
