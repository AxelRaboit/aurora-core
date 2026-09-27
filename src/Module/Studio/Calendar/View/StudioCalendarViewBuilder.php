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
use function in_array;

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

        return array_map($this->serialize(...), $this->items->findOnCalendar($ids, $from, $to));
    }

    /**
     * Every card in one state, whatever its month and whether it has a date.
     *
     * What a tile of the dashboard opens: « 3 publications not made » names
     * cards of past months, and « to rework » ones that may have no date at
     * all, so a month on screen could not show them.
     *
     * @return list<array<string, mixed>>
     */
    public function itemsInState(SpaceScopeEnum $scope, string $state): array
    {
        $ids = array_map(static fn (CustomerSpaceInterface $space): int => (int) $space->getId(), $this->activeSpaces($scope));

        return array_values(array_map($this->serialize(...), array_filter(
            $this->items->findForSpaces($ids),
            fn (SpaceContentItemInterface $item): bool => in_array($state, $this->workload->statesOf($item), true),
        )));
    }

    /** @return array<string, mixed> */
    private function serialize(SpaceContentItemInterface $item): array
    {
        return [
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
            // The card itself: in its space's month when it has a date, in
            // the content otherwise - an undated card is not on a calendar.
            'path' => $this->urlGenerator->generate('workspace_space_content', [
                'id' => $item->getSpace()->getId(),
                'view' => $item->getScheduledAt() instanceof DateTimeImmutable ? 'calendar' : 'content',
                'item' => $item->getId(),
            ]),
        ];
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
