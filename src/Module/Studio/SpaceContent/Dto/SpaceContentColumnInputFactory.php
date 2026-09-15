<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Dto;

use Aurora\Core\Support\Str;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(SpaceContentColumnInputFactoryInterface::class)]
class SpaceContentColumnInputFactory implements SpaceContentColumnInputFactoryInterface
{
    /** @param array<string, mixed> $data */
    public function fromArray(array $data): SpaceContentColumnInputInterface
    {
        return new SpaceContentColumnInput(name: Str::trimFromArray($data, 'name'));
    }
}
