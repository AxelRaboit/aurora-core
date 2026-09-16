<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\Dto;

interface CustomerSpaceInputFactoryInterface
{
    /** @param array<string, mixed> $data */
    public function fromArray(array $data): CustomerSpaceInputInterface;
}
