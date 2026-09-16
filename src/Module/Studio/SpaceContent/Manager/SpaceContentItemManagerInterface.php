<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Manager;

use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceContent\Dto\SpaceContentItemInputInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItemInterface;
use Aurora\Module\Studio\SpaceContent\Enum\SpaceContentApprovalEnum;

interface SpaceContentItemManagerInterface
{
    public function create(CustomerSpaceInterface $space, SpaceContentItemInputInterface $input): SpaceContentItemInterface;

    public function update(SpaceContentItemInterface $item, SpaceContentItemInputInterface $input): void;

    public function delete(SpaceContentItemInterface $item): void;

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
}
