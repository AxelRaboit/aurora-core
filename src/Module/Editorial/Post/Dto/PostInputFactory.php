<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Dto;

use Aurora\Core\Support\Arr;
use Aurora\Core\Support\Str;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(PostInputFactoryInterface::class)]
class PostInputFactory implements PostInputFactoryInterface
{
    /** @param array<string, mixed> $data */
    public function fromArray(array $data): PostInputInterface
    {
        $version = (int) ($data['version'] ?? 0);

        return new PostInput(
            postTypeId: (int) ($data['postTypeId'] ?? 0),
            status: Str::trimOrNull((string) ($data['status'] ?? '')) ?? PostStatusEnum::Draft->value,
            featuredMediaId: $this->positiveIntOrNull($data['featuredMediaId'] ?? null),
            termIds: Arr::positiveInts($data['termIds'] ?? null),
            translations: $this->translations($data['translations'] ?? null),
            relatedPostIds: Arr::positiveInts($data['relatedPostIds'] ?? null),
            scheduledAt: Str::trimOrNull((string) ($data['scheduledAt'] ?? '')),
            version: $version > 0 ? $version : null,
            force: (bool) ($data['force'] ?? false),
            commentsEnabled: (bool) ($data['commentsEnabled'] ?? true),
        );
    }

    /** @return array<string, PostTranslationInput> */
    private function translations(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $translations = [];
        foreach ($raw as $locale => $payload) {
            if (is_array($payload)) {
                $translations[(string) $locale] = PostTranslationInput::fromArray($payload);
            }
        }

        return $translations;
    }

    private function positiveIntOrNull(mixed $raw): ?int
    {
        $value = (int) $raw;

        return $value > 0 ? $value : null;
    }
}
