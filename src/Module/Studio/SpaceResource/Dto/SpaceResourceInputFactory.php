<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceResource\Dto;

use Aurora\Core\Support\Str;
use Aurora\Module\Studio\SpaceResource\Enum\SpaceResourceKindEnum;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(SpaceResourceInputFactoryInterface::class)]
class SpaceResourceInputFactory implements SpaceResourceInputFactoryInterface
{
    /** @param array<string, mixed> $data */
    public function fromArray(array $data): SpaceResourceInputInterface
    {
        $kind = SpaceResourceKindEnum::tryFrom(Str::trimFromArray($data, 'kind')) ?? SpaceResourceKindEnum::Link;

        return new SpaceResourceInput(
            kind: $kind,
            label: Str::trimFromArray($data, 'label'),
            // Les champs des autres genres ne sont pas repris : la modale les
            // garde à l'écran pendant qu'on hésite, et enregistrer un contact
            // après avoir commencé un lien ne doit pas laisser une adresse
            // orpheline dans la ligne.
            url: $kind->needsUrl() ? Str::trimOrNullFromArray($data, 'url') : null,
            // Le corps traverse tous les genres : c'est le corps d'un texte,
            // et une précision sur un lien ou un contact - « le mot de passe
            // est chez le client », « ne répond pas le vendredi ».
            body: Str::trimOrNullFromArray($data, 'body'),
            email: SpaceResourceKindEnum::Contact === $kind ? Str::emailOrNullFromArray($data, 'email') : null,
            phone: SpaceResourceKindEnum::Contact === $kind ? Str::trimOrNullFromArray($data, 'phone') : null,
            visibleToClient: true === ($data['visibleToClient'] ?? false),
        );
    }
}
