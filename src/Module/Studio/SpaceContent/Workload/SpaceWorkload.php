<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Workload;

use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItemInterface;
use Aurora\Module\Studio\SpaceContent\Enum\SpaceContentApprovalEnum;
use Aurora\Module\Studio\SpaceContent\Enum\SpaceContentColumnRoleEnum;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentItemRepository;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;

use function array_filter;
use function array_map;
use function array_values;
use function sprintf;

/**
 * The five states of a space's work, computed once for every screen.
 *
 * **One definition per word.** The dashboard, the list of spaces, the header
 * of a space, the review email and the editorial calendar used to count
 * « waiting for the client » five different ways - 13 on one screen, 6 on
 * the next, for the same space. Each state now means one thing:
 *
 * - **upcoming**: dated, on the calendar, not in a published step, due
 *   within the next {@see self::HORIZON_DAYS} days;
 * - **with the client**: in a step the client sees, dated, on the calendar,
 *   not published, and nobody has answered - what the client's page shows
 *   and waits on;
 * - **late review**: with the client, and its review deadline has passed;
 * - **changes requested**: the client asked for changes, not published yet;
 * - **missed**: its publication date has passed and it is still not in a
 *   published step. Only counted for a space whose board has a step marked
 *   « published »: without one, nothing can say it was not.
 *
 * Archived spaces are left out: their work is over, and counting it would
 * put yesterday's clients on today's list.
 */
final readonly class SpaceWorkload
{
    public const int HORIZON_DAYS = 7;

    public function __construct(
        private SpaceContentItemRepository $items,
        private ?ClockInterface $clock = null,
    ) {}

    /**
     * One row per active space among these, in the order given.
     *
     * @param list<CustomerSpaceInterface> $spaces
     *
     * @return list<SpaceWorkloadRow>
     */
    public function forSpaces(array $spaces): array
    {
        $active = array_values(array_filter($spaces, static fn (CustomerSpaceInterface $space): bool => !$space->isArchived()));

        if ([] === $active) {
            return [];
        }

        $now = $this->clock?->now() ?? new DateTimeImmutable();
        $ids = array_map(static fn (CustomerSpaceInterface $space): int => (int) $space->getId(), $active);
        $counts = $this->items->workloadBySpace($ids, $now, $now->modify(sprintf('+%d days', self::HORIZON_DAYS)));

        return array_map(static function (int $id) use ($counts): SpaceWorkloadRow {
            $row = $counts[$id] ?? null;

            return null === $row ? new SpaceWorkloadRow($id) : new SpaceWorkloadRow(
                spaceId: $id,
                upcoming: $row['upcoming'],
                withClient: $row['withClient'],
                lateReview: $row['lateReview'],
                changesRequested: $row['changesRequested'],
                missed: $row['hasPublishedStep'] ? $row['missed'] : 0,
                nextPublication: $row['nextPublication'],
            );
        }, $ids);
    }

    /**
     * The states one card is in, by the same definitions as the counts.
     *
     * Written next to the query that counts them, on purpose: the two say the
     * same thing in two languages, and a change to one that is not made to
     * the other is visible in the same screenful.
     *
     * @return list<string> among `upcoming`, `with_client`, `late_review`,
     *                      `changes_requested`, `missed`, `published`
     */
    public function statesOf(SpaceContentItemInterface $item): array
    {
        $now = $this->clock?->now() ?? new DateTimeImmutable();
        $scheduledAt = $item->getScheduledAt();
        $column = $item->getColumn();

        if (SpaceContentColumnRoleEnum::Published === $column->getRole()) {
            return ['published'];
        }

        $states = [];
        $onCalendar = $item->appearsOnCalendar() && $scheduledAt instanceof DateTimeImmutable;
        $pending = SpaceContentApprovalEnum::Pending === $item->getApproval();

        if ($onCalendar && $scheduledAt >= $now && $scheduledAt < $now->modify(sprintf('+%d days', self::HORIZON_DAYS))) {
            $states[] = 'upcoming';
        }

        if ($onCalendar && $pending && $column->isVisibleToClient()) {
            $states[] = 'with_client';
        }

        // The card's own rule, which the badge on it reads too.
        if ($item->isLateForReview($now)) {
            $states[] = 'late_review';
        }

        if (SpaceContentApprovalEnum::ChangesRequested === $item->getApproval()) {
            $states[] = 'changes_requested';
        }

        if ($onCalendar && $scheduledAt < $now && $this->hasPublishedStep($item)) {
            $states[] = 'missed';
        }

        return $states;
    }

    public function forSpace(CustomerSpaceInterface $space): SpaceWorkloadRow
    {
        return $this->forSpaces([$space])[0] ?? new SpaceWorkloadRow((int) $space->getId());
    }

    private function hasPublishedStep(SpaceContentItemInterface $item): bool
    {
        foreach ($item->getSpace()->getContentColumns() as $column) {
            if (SpaceContentColumnRoleEnum::Published === $column->getRole()) {
                return true;
            }
        }

        return false;
    }
}
