<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Space\Dto;

use Aurora\Core\Support\Str;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

use function is_numeric;

#[AsAlias(NoteSpaceInputFactoryInterface::class)]
class NoteSpaceInputFactory implements NoteSpaceInputFactoryInterface
{
    public function fromArray(array $data): NoteSpaceInputInterface
    {
        return new NoteSpaceInput(
            name: Str::trimOrNullFromArray($data, 'name'),
            color: Str::trimOrNullFromArray($data, 'color'),
            access: Str::trimOrNullFromArray($data, 'access'),
            defaultRole: Str::trimOrNullFromArray($data, 'defaultRole'),
            position: isset($data['position']) && is_numeric($data['position']) ? (int) $data['position'] : null,
        );
    }
}
