<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Dto;

use Aurora\Core\Support\Str;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(TagRenameInputFactoryInterface::class)]
class TagRenameInputFactory implements TagRenameInputFactoryInterface
{
    public function fromArray(array $data): TagRenameInput
    {
        return new TagRenameInput(
            oldTag: Str::trimFromArray($data, 'oldTag'),
            newTag: Str::trimFromArray($data, 'newTag'),
        );
    }
}
