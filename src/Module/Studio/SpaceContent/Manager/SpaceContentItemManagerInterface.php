<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Manager;

use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceContent\Dto\SpaceContentItemInputInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItemInterface;
use Aurora\Module\Studio\SpaceContent\Enum\SpaceContentApprovalEnum;
use DateTimeImmutable;

interface SpaceContentItemManagerInterface
{
    public function create(CustomerSpaceInterface $space, SpaceContentItemInputInterface $input): SpaceContentItemInterface;

    public function update(SpaceContentItemInterface $item, SpaceContentItemInputInterface $input): void;

    /**
     * Puts the card in the trash: off the board, the calendar, the counts and
     * the client's page, with its thread and files kept for a restore.
     */
    public function trash(SpaceContentItemInterface $item): void;

    /** Takes the card out of the trash, at the bottom of its step. */
    public function restore(SpaceContentItemInterface $item): void;

    /** Destroys the card for good, with its thread and attachments; the documents stay in the library. */
    public function forceDelete(SpaceContentItemInterface $item): void;

    /** Destroys what has been in the trash since before this date, and says how many. */
    public function purgeTrashedBefore(DateTimeImmutable $cutoff): int;

    /** Re-announces every card of the space, after its name, colour or status changed, or it left the trash. */
    public function announceSpace(CustomerSpaceInterface $space): void;

    /** Takes every card of the space off the calendar, when the space goes to the trash or is deleted. */
    public function unscheduleSpace(CustomerSpaceInterface $space): void;

    /**
     * Writes the order of one column after a drag.
     *
     * One column at a time and the whole of it: a card that left another column
     * is named here, and the column it left keeps the order it had minus that
     * card, which its own remaining positions already express.
     *
     * @param list<int> $itemIds in their new order, top to bottom
     */
    public function reorder(CustomerSpaceInterface $space, int $columnId, array $itemIds): void;

    /**
     * Moves a card to a day, which is what dragging it across the month does.
     *
     * Separate from `update` because it is the calendar's only write and it
     * must not need a title and a column to perform it.
     */
    public function reschedule(SpaceContentItemInterface $item, ?string $scheduledAt): void;

    /**
     * Records what a client answered through their link.
     *
     * The verdict never moves the card. See {@see SpaceContentApprovalEnum}.
     */
    public function answer(
        SpaceContentItemInterface $item,
        SpaceAccessLinkInterface $link,
        SpaceContentApprovalEnum $approval,
    ): void;

    /**
     * Approves several cards at once, as one gesture.
     *
     * Written together and announced once: a client tidying their week
     * approves twenty cards, and the team hears it as one piece of news, not
     * twenty bells. Cards of another space are skipped.
     *
     * @param list<SpaceContentItemInterface> $items
     *
     * @return int how many were approved
     */
    public function approveMany(array $items, SpaceAccessLinkInterface $link): int;
}
