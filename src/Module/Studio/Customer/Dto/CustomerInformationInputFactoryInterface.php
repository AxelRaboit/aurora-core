<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Customer\Dto;

interface CustomerInformationInputFactoryInterface
{
    /** @param array<string, mixed> $data */
    public function fromArray(array $data): CustomerInformationInputInterface;
}
