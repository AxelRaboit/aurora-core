<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerInteraction\Dto;

interface CustomerInteractionInputFactoryInterface
{
    /** @param array<string, mixed> $data */
    public function fromArray(array $data): CustomerInteractionInputInterface;
}
