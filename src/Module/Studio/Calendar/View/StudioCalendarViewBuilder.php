<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Calendar\View;

use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Enum\SpaceScopeEnum;
use Aurora\Module\Studio\CustomerSpace\Security\SpaceVisibility;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItemInterface;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentItemRepository;
use Aurora\Module\Studio\SpaceContent\Workload\SpaceWorkload;
use DateTimeImmutable;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function array_filter;
use function array_map;
use function array_values;

/**
 * What the editorial calendar shows: what goes out, for whom, and when.
 *
 * Every space the reader may see, for the scope asked - their own first,
 * every one for whoever sees all and asks. Read-only on purpose: a card is
 * moved in its own space, where its step and its thread are.
 */
final readonly class StudioCalendarViewBuilder
{
    /** The widest window one request may ask for, so a hand-made one cannot ask for years. */
    private const int MAX_WINDOW_DAYS = 62;

    public function __construct(
        private SpaceVisibility $visibility,
        private SpaceContentItemRepository $items,
        private SpaceWorkload $workload,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    /** @return array<string, mixed> */
    public function indexView(SpaceScopeEnum $scope): array
    {
        return [
            'scope' => $scope->value,
            'hasScopeChoice' => $this->visibility->hasScopeChoice(),
            'spaces' => array_map(fn (CustomerSpaceInterface $space): array => [
                'id' => $space->getId(),
                'name' => $space->getName(),
                'customerName' => $space->getCustomer()->getLegalName(),
                'colourSlot' => $space->getColourSlot(),
            ], $this->activeSpaces($scope)),
            'itemsPath' => $this->urlGenerator->generate('backend_studio_calendar_items'),
        ];
    }

    /**
     * The cards of the window, or null when the window is not one.
     *
     * @return list<array<string, mixed>>|null
     */
    public function items(SpaceScopeEnum $scope, DateTimeImmutable $from, DateTimeImmutable $to): ?array
    {
        if ($to <= $from || $from->diff($to)->days > self::MAX_WINDOW_DAYS) {
            return null;
        }

        $ids = array_map(static fn (CustomerSpaceInterface $space): int => (int) $space->getId(), $this->activeSpaces($scope));

        return array_map(fn (SpaceContentItemInterface $item): array => [
            'id' => $item->getId(),
            'title' => $item->getTitle(),
            'spaceId' => $item->getSpace()->getId(),
            'startAt' => $item->getScheduledAt()?->format(DATE_ATOM),
            'endAt' => $item->getScheduledAt()?->format(DATE_ATOM),
            'allDay' => false,
            'colourSlot' => $item->getSpace()->getColourSlot(),
            // Moved in its own space, never here: the grid must not offer
            // to drag it.
            'readOnly' => true,
            'stepName' => $item->getColumn()->getName(),
            'states' => $this->workload->statesOf($item),
            // La fiche elle-même, dans la vue calendrier de son espace.
            'path' => $this->urlGenerator->generate('workspace_space_content', ['id' => $item->getSpace()->getId(), 'view' => 'calendar', 'item' => $item->getId()]),
        ], $this->items->findOnCalendar($ids, $from, $to));
    }

    /** @return list<CustomerSpaceInterface> */
    private function activeSpaces(SpaceScopeEnum $scope): array
    {
        return array_values(array_filter(
            $this->visibility->spacesIn($scope),
            static fn (CustomerSpaceInterface $space): bool => !$space->isArchived(),
        ));
    }
}
