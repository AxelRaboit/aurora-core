<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Event\Enum;

/**
 * Where an event stands, which is not the same question as whether it happened.
 *
 * `Cancelled` keeps the row rather than deleting it: a cancelled meeting still
 * answers "what was on Tuesday", and a reader who remembers it and cannot find
 * it assumes the calendar lost it.
 */
enum PlanningEventStatusEnum: string
{
    case Tentative = 'tentative';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';

    public function getLabelKey(): string
    {
        return sprintf('backend.plannings.events.status.%s', $this->value);
    }

    /**
     * Its badge colour, beside the label for the reason `UserRoleEnum::colour()`
     * gives: one decision, one place, so two screens cannot disagree.
     */
    public function badgeColor(): string
    {
        return match ($this) {
            self::Tentative => 'amber',
            self::Confirmed => 'emerald',
            self::Cancelled => 'rose',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
