<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerInteraction\Serializer;

use Aurora\Module\Studio\CustomerInteraction\Entity\CustomerInteractionInterface;

interface CustomerInteractionSerializerInterface
{
    /** @return array<string, mixed> */
    public function serialize(CustomerInteractionInterface $interaction): array;
}
