<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Recurrence;

/**
 * What an edit to a recurring event applies to.
 *
 * Google and Apple both ask this, and they ask it because there is no safe
 * default: guessing "this one" loses a change somebody meant for the series, and
 * guessing "all" rewrites occurrences they never looked at. The client says which,
 * and a request that does not say gets `Single` - which is refused on a series
 * rather than silently treated as one of the three.
 */
enum RecurrenceScopeEnum: string
{
    /** Not a series at all. The only scope a plain event can have. */
    case Single = 'single';

    /** One occurrence, which becomes a row of its own. */
    case This = 'this';

    /** This occurrence and every later one: the series is split in two. */
    case Following = 'following';

    /** The whole series, including the occurrences already past. */
    case All = 'all';

    public static function fromRequest(mixed $value): self
    {
        return self::tryFrom(is_string($value) ? $value : '') ?? self::Single;
    }

    public function isSeriesScope(): bool
    {
        return self::Single !== $this;
    }

    /**
     * Whether this scope has to be told which occurrence it points at.
     *
     * Not the same question as "is this a series scope", which is where this first
     * went wrong: `All` applies to the series and needs no date, while `This` and
     * `Following` are meaningless without one.
     */
    public function needsOccurrence(): bool
    {
        return self::This === $this || self::Following === $this;
    }
}
