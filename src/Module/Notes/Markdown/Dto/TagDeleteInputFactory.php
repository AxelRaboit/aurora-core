<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Dto;

use Aurora\Core\Support\Str;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(TagDeleteInputFactoryInterface::class)]
class TagDeleteInputFactory implements TagDeleteInputFactoryInterface
{
    public function fromArray(array $data): TagDeleteInput
    {
        return new TagDeleteInput(tag: Str::trimFromArray($data, 'tag'));
    }
}
