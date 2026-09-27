<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Security;

use Aurora\Core\Scheduling\Access\ScheduledSourceAccessInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Security\SpaceVisibility;
use Aurora\Module\Studio\SpaceContent\Manager\SpaceContentItemManager;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentItemRepository;

use function array_map;

/**
 * A client's publications, on the calendar, for the members of that client's
 * space only.
 *
 * Every space's dated cards land in one shared calendar, so that it shows up
 * at all; before this, everybody who used the calendar read every client's
 * schedule. The rule is {@see SpaceVisibility}'s, the one every Studio screen
 * already asks: a member sees their spaces, an administrator sees all.
 */
final readonly class SpaceContentScheduleAccess implements ScheduledSourceAccessInterface
{
    public function __construct(
        private SpaceVisibility $visibility,
        private SpaceContentItemRepository $items,
    ) {}

    public function supports(string $sourceType): bool
    {
        return SpaceContentItemManager::SCHEDULE_SOURCE === $sourceType;
    }

    public function visibleAmong(string $sourceType, array $sourceIds): array
    {
        $spaceIds = array_map(static fn (CustomerSpaceInterface $space): int => (int) $space->getId(), $this->visibility->visibleSpaces());

        return $this->items->idsInSpaces($sourceIds, $spaceIds);
    }
}
