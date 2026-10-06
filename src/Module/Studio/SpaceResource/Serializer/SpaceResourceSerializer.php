<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceResource\Serializer;

use Aurora\Module\Studio\SpaceResource\Entity\SpaceResourceInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(SpaceResourceSerializerInterface::class)]
class SpaceResourceSerializer implements SpaceResourceSerializerInterface
{
    /** @return array<string, mixed> */
    public function serialize(SpaceResourceInterface $resource): array
    {
        return [
            'id' => $resource->getId(),
            'kind' => $resource->getKind()->value,
            'label' => $resource->getLabel(),
            'url' => $resource->getUrl(),
            'body' => $resource->getBody(),
            'email' => $resource->getEmail(),
            'phone' => $resource->getPhone(),
            'visibleToClient' => $resource->isVisibleToClient(),
        ];
    }

    /**
     * What goes to a public page.
     *
     * **Without `visibleToClient`.** Every one that arrives there is visible, so
     * the field could only say "yes": a flag that has only one value informs
     * nobody and makes people believe it has two. The same rule as the visible
     * steps on a client's page.
     *
     * **And without `position`.** The order is that of the list; a rank that
     * travels alongside is a second order, which ends up no longer matching.
     *
     * The identifier stays: the page needs it as a render key, and it leads
     * nowhere - no public route takes a resource.
     *
     * @return array<string, mixed>
     */
    public function serializeForGuest(SpaceResourceInterface $resource): array
    {
        return [
            'id' => $resource->getId(),
            'kind' => $resource->getKind()->value,
            'label' => $resource->getLabel(),
            'url' => $resource->getUrl(),
            'body' => $resource->getBody(),
            'email' => $resource->getEmail(),
            'phone' => $resource->getPhone(),
        ];
    }
}
