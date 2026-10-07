<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\Manager;

use Aurora\Module\Studio\CustomerSpace\Dto\CustomerSpaceInputInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use DateTimeImmutable;

interface CustomerSpaceManagerInterface
{
    public function create(CustomerSpaceInputInterface $input): CustomerSpaceInterface;

    public function update(CustomerSpaceInterface $space, CustomerSpaceInputInterface $input): void;

    /** Puts the space in the trash, with its dates off the calendar and its note space in the notes' trash. */
    public function trash(CustomerSpaceInterface $space): void;

    /** Takes the space out of the trash, as it was. */
    public function restore(CustomerSpaceInterface $space): void;

    /** Destroys the space for good, with everything it holds. */
    public function forceDelete(CustomerSpaceInterface $space): void;

    /** Destroys what has been in the trash since before this date, and says how many. */
    public function purgeTrashedBefore(DateTimeImmutable $cutoff): int;
}
