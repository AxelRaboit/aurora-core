<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Link\Entity;

/**
 * What a share link serves.
 *
 * Two values because the same secret points at two different renderers, and they
 * are not interchangeable: a `Web` token opens a page a person reads, an `Ics`
 * token answers a file a calendar application subscribes to. Letting one token do
 * both would mean the address somebody sent to a guest was also a permanent feed
 * they could add to their phone - a wider grant than the person sharing it chose.
 */
enum PlanningShareLinkModeEnum: string
{
    /** A page, for a person. */
    case Web = 'web';

    /** An `.ics` file, for a calendar application polling on a timer. */
    case Ics = 'ics';

    /**
     * The route that serves this mode.
     *
     * On the enum rather than in a `match` at the serialiser, for the reason
     * `PostStatusEnum::getLabelKey()` is: a name belongs beside the value it names,
     * and a route built by concatenation somewhere else is invisible to every test.
     */
    public function routeName(): string
    {
        return match ($this) {
            self::Web => 'planning_share_show',
            self::Ics => 'planning_feed_show',
        };
    }

    public function getLabelKey(): string
    {
        return 'backend.plannings.links.mode_'.$this->value;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
