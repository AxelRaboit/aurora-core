<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceResource\Dto;

interface SpaceResourceInputFactoryInterface
{
    /** @param array<string, mixed> $data */
    public function fromArray(array $data): SpaceResourceInputInterface;
}
