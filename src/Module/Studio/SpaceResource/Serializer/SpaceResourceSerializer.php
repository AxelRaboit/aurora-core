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
     * Ce qui part vers une page publique.
     *
     * **Sans `visibleToClient`.** Toutes celles qui arrivent là le sont, donc
     * le champ ne pourrait dire que « oui » : un drapeau qui n'a qu'une valeur
     * n'informe personne et fait croire qu'il en a deux. La même règle que les
     * étapes visibles sur la page d'un client.
     *
     * **Et sans `position`.** L'ordre est celui de la liste ; un rang qui
     * voyage à côté est un second ordre, qui finit par ne plus coïncider.
     *
     * L'identifiant reste : la page en a besoin comme clé de rendu, et il ne
     * mène à rien - aucune route publique ne prend une ressource.
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
