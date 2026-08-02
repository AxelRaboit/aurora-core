<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\PostType\Dto;

use Aurora\Core\Support\Str;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(PostTypeFieldInputFactoryInterface::class)]
class PostTypeFieldInputFactory implements PostTypeFieldInputFactoryInterface
{
    /** @param array<string, mixed> $data */
    public function fromArray(array $data): PostTypeFieldInputInterface
    {
        return new PostTypeFieldInput(
            name: mb_strtolower(Str::trimOrNull((string) ($data['name'] ?? '')) ?? ''),
            label: Str::trimOrNull((string) ($data['label'] ?? '')) ?? '',
            type: Str::trimOrNull((string) ($data['type'] ?? '')) ?? 'text',
            required: (bool) ($data['required'] ?? false),
            translatable: (bool) ($data['translatable'] ?? false),
            options: is_array($data['options'] ?? null) ? $data['options'] : [],
        );
    }
}
