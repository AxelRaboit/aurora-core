<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Planning\Enum;

/**
 * Who sees a calendar besides the person who owns it.
 *
 * Two values and not three: "shared with these people" is a list, not a
 * visibility, and adding it as a third case here would make the column mean two
 * different things. When per-person sharing arrives it arrives as its own table.
 */
enum PlanningVisibilityEnum: string
{
    /** Only the owner. */
    case Private = 'private';

    /** Anyone who can reach the calendar screen. */
    case Shared = 'shared';

    public function getLabelKey(): string
    {
        return sprintf('backend.plannings.visibility.%s', $this->value);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
