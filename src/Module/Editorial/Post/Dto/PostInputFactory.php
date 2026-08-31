<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Dto;

use Aurora\Core\Support\Arr;
use Aurora\Core\Support\Str;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\Post\Enum\ThumbnailFitEnum;
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
            thumbnailId: $this->positiveIntOrNull($data['thumbnailId'] ?? null),
            termIds: Arr::positiveInts($data['termIds'] ?? null),
            translations: $this->translations($data['translations'] ?? null),
            relatedPostIds: Arr::positiveInts($data['relatedPostIds'] ?? null),
            scheduledAt: Str::trimOrNull((string) ($data['scheduledAt'] ?? '')),
            unpublishAt: Str::trimOrNull((string) ($data['unpublishAt'] ?? '')),
            version: $version > 0 ? $version : null,
            force: (bool) ($data['force'] ?? false),
            commentsEnabled: (bool) ($data['commentsEnabled'] ?? true),
            titleVisible: (bool) ($data['titleVisible'] ?? true),
            // On the post, not the translation: one design for every language.
            bannerLayout: is_array($data['bannerLayout'] ?? null) ? $data['bannerLayout'] : [],
            gridLayout: is_array($data['gridLayout'] ?? null) ? $data['gridLayout'] : [],
            galleryLayout: is_array($data['galleryLayout'] ?? null) ? $data['galleryLayout'] : [],
            thumbnailFit: Str::trimOrNull((string) ($data['thumbnailFit'] ?? '')) ?? ThumbnailFitEnum::Cover->value,
            thumbnailFocalX: $this->fractionOrNull($data['thumbnailFocalX'] ?? null),
            thumbnailFocalY: $this->fractionOrNull($data['thumbnailFocalY'] ?? null),
            headerColor: $this->colorOrNull($data['headerColor'] ?? null),
            footerColor: $this->colorOrNull($data['footerColor'] ?? null),
            backgroundColor: $this->colorOrNull($data['backgroundColor'] ?? null),
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

    /**
     * A focal coordinate is a fraction of the picture. Anything outside 0..1
     * is not a position on it, so it becomes "no override" rather than being
     * clamped into one nobody chose.
     */
    private function fractionOrNull(mixed $raw): ?float
    {
        if (!is_numeric($raw)) {
            return null;
        }

        $value = (float) $raw;

        return $value >= 0.0 && $value <= 1.0 ? $value : null;
    }

    private function positiveIntOrNull(mixed $raw): ?int
    {
        $value = (int) $raw;

        return $value > 0 ? $value : null;
    }

    /**
     * A hex color must be exactly 7 characters: #RRGGBB.
     */
    private function colorOrNull(mixed $raw): ?string
    {
        $value = Str::trimOrNull((string) ($raw ?? ''));

        if (null === $value) {
            return null;
        }

        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? $value : null;
    }
}
