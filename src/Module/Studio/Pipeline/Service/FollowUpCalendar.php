<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Pipeline\Service;

use Aurora\Module\Configuration\Setting\Service\SiteTimezone;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * What "today" is for a follow-up.
 *
 * The site's day, not the server's: a follow-up set for Tuesday is due on
 * Tuesday where the studio works, and a server in UTC would make it due at
 * 1 a.m. on Monday evening's terms. One place says it, so the reminder, the
 * dashboard and the side menu agree on which follow-ups are late.
 */
final readonly class FollowUpCalendar
{
    public function __construct(
        private SiteTimezone $siteTimezone,
        private ?ClockInterface $clock = null,
    ) {}

    public function today(): DateTimeImmutable
    {
        $now = $this->clock?->now() ?? new DateTimeImmutable();

        return $now->setTimezone($this->siteTimezone->get())->setTime(0, 0);
    }
}
