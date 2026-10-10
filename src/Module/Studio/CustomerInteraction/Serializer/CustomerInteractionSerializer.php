<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerInteraction\Serializer;

use Aurora\Module\Studio\CustomerInteraction\Entity\CustomerInteractionInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

use const DATE_ATOM;

#[AsAlias(CustomerInteractionSerializerInterface::class)]
class CustomerInteractionSerializer implements CustomerInteractionSerializerInterface
{
    /** @return array<string, mixed> */
    public function serialize(CustomerInteractionInterface $interaction): array
    {
        return [
            'id' => $interaction->getId(),
            'kind' => $interaction->getKind()->value,
            'occurredAt' => $interaction->getOccurredAt()->format(DATE_ATOM),
            'summary' => $interaction->getSummary(),
            'authorLabel' => $interaction->getAuthorLabel(),
        ];
    }
}
