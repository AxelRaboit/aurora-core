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
            // The fields of the other kinds are not carried over: the modal keeps them
            // on screen while someone hesitates, and saving a contact after starting a
            // link must not leave an orphan address in the row.
            url: $kind->needsUrl() ? Str::trimOrNullFromArray($data, 'url') : null,
            // The body goes across every kind: it is the body of a text, and a detail
            // on a link or a contact - "the password is with the client", "does not
            // answer on Fridays".
            body: Str::trimOrNullFromArray($data, 'body'),
            email: SpaceResourceKindEnum::Contact === $kind ? Str::emailOrNullFromArray($data, 'email') : null,
            phone: SpaceResourceKindEnum::Contact === $kind ? Str::trimOrNullFromArray($data, 'phone') : null,
            visibleToClient: true === ($data['visibleToClient'] ?? false),
        );
    }
}
