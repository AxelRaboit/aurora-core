<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Event\Enum;

/**
 * How an alert reaches the reader.
 *
 * Per alert rather than per calendar, which is how Google puts it: the same
 * meeting often wants a notification ten minutes before and an email the day
 * before, and a single per-calendar switch can express neither.
 *
 * Two cases and no push: this application has no push channel, and adding one to
 * the enum before it exists would be an option the form offers and nothing
 * delivers.
 */
enum PlanningAlertChannelEnum: string
{
    /** The backend's own notification list. */
    case Notification = 'notification';

    case Email = 'email';

    public function getLabelKey(): string
    {
        return 'backend.plannings.alerts.channel_'.$this->value;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
