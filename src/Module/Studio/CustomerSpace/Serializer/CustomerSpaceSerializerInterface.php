<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\Serializer;

use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;

interface CustomerSpaceSerializerInterface
{
    /** @return array<string, mixed> */
    public function serialize(CustomerSpaceInterface $space): array;
}
