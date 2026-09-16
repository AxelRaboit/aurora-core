<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceAccess\Dto;

interface SpaceAccessLinkInputFactoryInterface
{
    /** @param array<string, mixed> $data */
    public function fromArray(array $data): SpaceAccessLinkInputInterface;
}
