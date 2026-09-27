<?php

declare(strict_types=1);

namespace Aurora\Core\Scheduling\Access;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Who may see what a module put on the calendar.
 *
 * The calendar shows the dates modules announce - a client's publications, a
 * visitor's booking - but it cannot know who is allowed to see them: that is
 * the module's own rule (a member of the client's space, somebody who manages
 * the pages). Each module that announces dates says it here, and the calendar
 * asks before it shows one. A source nobody answers for stays visible, which
 * is what a publication date on the public site is.
 */
#[AutoconfigureTag(self::TAG)]
interface ScheduledSourceAccessInterface
{
    public const string TAG = 'aurora.scheduled_source_access';

    public function supports(string $sourceType): bool;

    /**
     * Those of these entities the signed-in reader may see.
     *
     * @param list<int> $sourceIds
     *
     * @return list<int>
     */
    public function visibleAmong(string $sourceType, array $sourceIds): array;
}
