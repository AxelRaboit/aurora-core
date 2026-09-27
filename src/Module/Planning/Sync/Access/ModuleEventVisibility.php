<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Sync\Access;

use Aurora\Core\Scheduling\Access\ScheduledSourceAccessInterface;
use Aurora\Module\Planning\Event\Entity\PlanningEventInterface;
use Aurora\Module\Planning\Recurrence\PlanningOccurrence;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

use function array_fill_keys;
use function array_filter;
use function array_keys;
use function array_values;

/**
 * Leaves out the module events the reader may not see.
 *
 * A calendar a module fills is shared, so that it appears at all; what is on
 * it is not for everybody. Every place the calendar lists events - the grid,
 * the dashboard panel, the search - passes them through here, and the routes
 * that act on one event ask {@see self::canSee()}.
 *
 * One question per source type and per batch, never per event: a month of
 * publications is a hundred rows, and a hundred questions would be a hundred
 * queries.
 */
final readonly class ModuleEventVisibility
{
    /** @param iterable<ScheduledSourceAccessInterface> $accesses */
    public function __construct(
        #[AutowireIterator(ScheduledSourceAccessInterface::TAG)]
        private iterable $accesses,
    ) {}

    /**
     * @template T of PlanningEventInterface|PlanningOccurrence
     *
     * @param iterable<T> $entries
     *
     * @return list<T>
     */
    public function filter(iterable $entries): array
    {
        $entries = [...$entries];
        $wanted = [];

        foreach ($entries as $entry) {
            $event = $entry instanceof PlanningOccurrence ? $entry->event : $entry;

            if ($event->isFromModule()) {
                $wanted[(string) $event->getSourceType()][(int) $event->getSourceId()] = true;
            }
        }

        if ([] === $wanted) {
            return $entries;
        }

        $visible = [];
        foreach ($wanted as $sourceType => $ids) {
            $visible[$sourceType] = $this->visibleIds($sourceType, array_keys($ids));
        }

        return array_values(array_filter($entries, static function (PlanningEventInterface|PlanningOccurrence $entry) use ($visible): bool {
            $event = $entry instanceof PlanningOccurrence ? $entry->event : $entry;
            if (!$event->isFromModule()) {
                return true;
            }

            if (null === $visible[(string) $event->getSourceType()]) {
                return true;
            }

            return isset($visible[(string) $event->getSourceType()][(int) $event->getSourceId()]);
        }));
    }

    public function canSee(PlanningEventInterface $event): bool
    {
        return [] !== $this->filter([$event]);
    }

    /**
     * The visible ids as a set, or null when no module answers for this
     * source - which leaves every one of its events visible.
     *
     * @param list<int> $ids
     *
     * @return array<int, true>|null
     */
    private function visibleIds(string $sourceType, array $ids): ?array
    {
        foreach ($this->accesses as $access) {
            if ($access->supports($sourceType)) {
                return array_fill_keys($access->visibleAmong($sourceType, $ids), true);
            }
        }

        return null;
    }
}
