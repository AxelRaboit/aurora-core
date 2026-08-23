<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Dto;

use Aurora\Core\Support\Str;

/**
 * Sub-DTO of {@see PostInput}, one per locale. Stays final readonly and
 * has no factory of its own: only the root DTO a controller consumes is
 * a substitution point.
 */
final readonly class PostTranslationInput
{
    /**
     * @param array<string, mixed>      $customFields
     * @param array<string, mixed>|null $jsonLd
     * @param array<string, mixed>      $banner       raw; normalised at the write boundary by BannerNormalizer
     * @param array<string, mixed>      $grid         raw; normalised at the write boundary by GridNormalizer
     * @param array<string, mixed>      $gallery      raw; normalised at the write boundary by GalleryNormalizer
     */
    public function __construct(
        public ?string $title,
        public ?string $slug,
        public ?string $description,
        public ?string $metaTitle,
        public ?string $metaDescription,
        public array $customFields,
        public ?int $ogImageMediaId = null,
        public ?string $canonicalUrl = null,
        public bool $noindex = false,
        public ?string $focusKeyword = null,
        public ?array $jsonLd = null,
        public array $banner = [],
        public array $grid = [],
        public array $gallery = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            title: Str::trimOrNull((string) ($data['title'] ?? '')),
            slug: Str::trimOrNull((string) ($data['slug'] ?? '')),
            description: Str::trimOrNull((string) ($data['description'] ?? '')),
            metaTitle: Str::trimOrNull((string) ($data['metaTitle'] ?? '')),
            metaDescription: Str::trimOrNull((string) ($data['metaDescription'] ?? '')),
            customFields: is_array($data['customFields'] ?? null) ? $data['customFields'] : [],
            ogImageMediaId: self::positiveIntOrNull($data['ogImageMediaId'] ?? null),
            canonicalUrl: Str::trimOrNull((string) ($data['canonicalUrl'] ?? '')),
            noindex: (bool) ($data['noindex'] ?? false),
            focusKeyword: Str::trimOrNull((string) ($data['focusKeyword'] ?? '')),
            jsonLd: self::decodeJsonLd($data['jsonLd'] ?? null),
            banner: is_array($data['banner'] ?? null) ? $data['banner'] : [],
            grid: is_array($data['grid'] ?? null) ? $data['grid'] : [],
            gallery: is_array($data['gallery'] ?? null) ? $data['gallery'] : [],
        );
    }

    /**
     * The SEO panel posts JSON-LD as an object once parsed, or as the raw
     * text the editor typed. Malformed text is dropped rather than stored:
     * a broken script tag on the page helps nobody.
     *
     * @return array<string, mixed>|null
     */
    private static function decodeJsonLd(mixed $raw): ?array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (!is_string($raw) || '' === mb_trim($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private static function positiveIntOrNull(mixed $raw): ?int
    {
        $value = (int) $raw;

        return $value > 0 ? $value : null;
    }
}
