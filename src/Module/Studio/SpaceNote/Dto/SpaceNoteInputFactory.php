<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceNote\Dto;

use Aurora\Core\Support\Str;
use Aurora\Module\Studio\SpaceNote\Enum\SpaceNoteVisibilityEnum;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

use function is_array;

#[AsAlias(SpaceNoteInputFactoryInterface::class)]
class SpaceNoteInputFactory implements SpaceNoteInputFactoryInterface
{
    /** @param array<string, mixed> $data */
    public function fromArray(array $data): SpaceNoteInputInterface
    {
        $body = $data['body'] ?? [];

        return new SpaceNoteInput(
            title: Str::trimFromArray($data, 'title'),
            // Ce que l'editeur envoie, tel quel. Une liste de blocs non
            // reconnue devient une note vide plutot qu'une erreur : le corps
            // est une structure, pas une saisie, et rien ici ne sait mieux que
            // l'editeur ce qu'un bloc doit contenir.
            body: is_array($body) ? array_values(array_filter($body, is_array(...))) : [],
            colourSlot: isset($data['colourSlot']) && is_numeric($data['colourSlot'])
                ? (int) $data['colourSlot']
                : null,
            pinned: (bool) ($data['pinned'] ?? false),
            // Une valeur inconnue retombe sur « partagee » plutot que de faire
            // une erreur : c'est le defaut, et il ne divulgue rien.
            visibility: SpaceNoteVisibilityEnum::tryFrom((string) ($data['visibility'] ?? '')) ?? SpaceNoteVisibilityEnum::Shared,
        );
    }
}
