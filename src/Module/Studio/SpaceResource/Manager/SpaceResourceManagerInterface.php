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
     * Ouvre ou ferme une ressource au client, sans rouvrir la modale.
     *
     * Sa propre écriture : dire « celle-ci, il peut la voir » ne devrait pas
     * renvoyer un libellé, une adresse et un corps.
     */
    public function toggleVisibility(SpaceResourceInterface $resource): void;

    /** @param list<int> $orderedIds */
    public function reorder(CustomerSpaceInterface $space, array $orderedIds): void;

    public function delete(SpaceResourceInterface $resource): void;
}
