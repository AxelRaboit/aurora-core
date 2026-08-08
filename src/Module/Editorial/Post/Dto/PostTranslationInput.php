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
     * @param list<array{id?: string, type: string, data: array<string, mixed>}> $blocks       Editor.js native shape
     * @param array<string, mixed>                                               $customFields
     * @param array<string, mixed>|null                                          $jsonLd
     * @param array<string, mixed>                                               $banner       raw; normalised at the write boundary by BannerNormalizer
     */
    public function __construct(
        public ?string $title,
        public ?string $slug,
        public ?string $description,
        public array $blocks,
        public ?string $metaTitle,
        public ?string $metaDescription,
        public array $customFields,
        public ?int $ogImageMediaId = null,
        public ?string $canonicalUrl = null,
        public bool $noindex = false,
        public ?string $focusKeyword = null,
        public ?array $jsonLd = null,
        public array $banner = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            title: Str::trimOrNull((string) ($data['title'] ?? '')),
            slug: Str::trimOrNull((string) ($data['slug'] ?? '')),
            description: Str::trimOrNull((string) ($data['description'] ?? '')),
            blocks: is_array($data['blocks'] ?? null) ? array_values($data['blocks']) : [],
            metaTitle: Str::trimOrNull((string) ($data['metaTitle'] ?? '')),
            metaDescription: Str::trimOrNull((string) ($data['metaDescription'] ?? '')),
            customFields: is_array($data['customFields'] ?? null) ? $data['customFields'] : [],
            ogImageMediaId: self::positiveIntOrNull($data['ogImageMediaId'] ?? null),
            canonicalUrl: Str::trimOrNull((string) ($data['canonicalUrl'] ?? '')),
            noindex: (bool) ($data['noindex'] ?? false),
            focusKeyword: Str::trimOrNull((string) ($data['focusKeyword'] ?? '')),
            jsonLd: self::decodeJsonLd($data['jsonLd'] ?? null),
            banner: is_array($data['banner'] ?? null) ? $data['banner'] : [],
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
