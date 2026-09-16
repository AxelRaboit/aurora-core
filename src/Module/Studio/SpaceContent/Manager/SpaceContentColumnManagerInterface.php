<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Manager;

use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceContent\Dto\SpaceContentColumnInputInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentColumnInterface;

interface SpaceContentColumnManagerInterface
{
    public function create(CustomerSpaceInterface $space, SpaceContentColumnInputInterface $input): SpaceContentColumnInterface;

    public function update(SpaceContentColumnInterface $column, SpaceContentColumnInputInterface $input): void;

    public function delete(SpaceContentColumnInterface $column): void;

    /**
     * Writes the left-to-right order a drag produced.
     *
     * @param list<int> $columnIds in their new order
     */
    public function reorder(CustomerSpaceInterface $space, array $columnIds): void;

    /**
     * The steps a new space's board opens with.
     *
     * Called when a space is created, so a board is usable the moment it
     * exists: an empty board offers nowhere to put the first card, and asking
     * somebody to invent five column names before writing anything is how a
     * feature goes unused.
     */
    public function seedDefaults(CustomerSpaceInterface $space): void;
}
