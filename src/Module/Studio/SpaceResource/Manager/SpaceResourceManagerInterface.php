<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceResource\Manager;

use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceResource\Dto\SpaceResourceInputInterface;
use Aurora\Module\Studio\SpaceResource\Entity\SpaceResourceInterface;

interface SpaceResourceManagerInterface
{
    public function create(CustomerSpaceInterface $space, SpaceResourceInputInterface $input): SpaceResourceInterface;

    public function update(SpaceResourceInterface $resource, SpaceResourceInputInterface $input): void;

    /**
     * Opens or closes a resource to the client, without reopening the modal.
     *
     * Its own write: saying "this one, they can see it" should not have to send
     * a label, an address and a body back.
     */
    public function toggleVisibility(SpaceResourceInterface $resource): void;

    /** @param list<int> $orderedIds */
    public function reorder(CustomerSpaceInterface $space, array $orderedIds): void;

    public function delete(SpaceResourceInterface $resource): void;
}
