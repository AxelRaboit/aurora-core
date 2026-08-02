<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\PostType\Dto;

use Aurora\Core\Support\Str;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(PostTypeInputFactoryInterface::class)]
class PostTypeInputFactory implements PostTypeInputFactoryInterface
{
    /** @param array<string, mixed> $data */
    public function fromArray(array $data): PostTypeInputInterface
    {
        return new PostTypeInput(
            slug: mb_strtolower(Str::trimOrNull((string) ($data['slug'] ?? '')) ?? ''),
            label: Str::trimOrNull((string) ($data['label'] ?? '')) ?? '',
            icon: Str::trimOrNull((string) ($data['icon'] ?? '')),
            hasArchive: (bool) ($data['hasArchive'] ?? false),
            supports: $this->stringList($data['supports'] ?? null),
        );
    }

    /** @return list<string> */
    private function stringList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn (mixed $item): string => (string) $item, $raw)));
    }
}
