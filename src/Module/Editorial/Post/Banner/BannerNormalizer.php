<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Banner;

/**
 * Normalises the per-translation banner configuration.
 *
 * Every value that reaches the database goes through here, and anything not
 * explicitly allowed is dropped. That is a deliberate contrast with `blocks`,
 * which is written raw and only sanitised at render time — the reason two
 * block types have been shipping a shape the renderer cannot read without
 * anyone noticing. A banner that survives a round-trip is a banner the
 * renderer can trust.
 *
 * The shape is one layout and two slots, rather than an enumerated list of
 * layouts. "Text left, image right", "image only", "two images" and "two
 * texts" are then the same structure with different slot types, so adding a
 * seventh arrangement later costs nothing.
 *
 * @phpstan-type BannerSlot array{type: string, title: string, description: string, titleColor: ?string, descriptionColor: ?string, align: string, mediaId: ?int, alt: string}
 * @phpstan-type Banner array{enabled: bool, height: string, ratio: string, logoMediaId: ?int, background: array{color: ?string, mediaId: ?int, overlay: int}, slots: list<BannerSlot>}
 */
final readonly class BannerNormalizer
{
    public const string SLOT_NONE = 'none';

    public const string SLOT_TEXT = 'text';

    public const string SLOT_IMAGE = 'image';

    /** Both slots always exist; an unused one is `none` rather than absent. */
    private const int SLOT_COUNT = 2;

    private const array SLOT_TYPES = [self::SLOT_NONE, self::SLOT_TEXT, self::SLOT_IMAGE];

    private const array HEIGHTS = ['sm', 'md', 'lg', 'full'];

    private const array RATIOS = ['50-50', '33-67', '67-33'];

    private const array ALIGNMENTS = ['start', 'center', 'end'];

    private const string HEX_COLOR = '/^#[0-9a-fA-F]{6}$/';

    /**
     * @param mixed $raw whatever the client sent
     *
     * @return array<string, mixed> a banner that is safe to persist and render
     */
    public function normalize(mixed $raw): array
    {
        $data = is_array($raw) ? $raw : [];

        return [
            'enabled' => (bool) ($data['enabled'] ?? false),
            'height' => $this->oneOf($data['height'] ?? null, self::HEIGHTS, 'md'),
            'ratio' => $this->oneOf($data['ratio'] ?? null, self::RATIOS, '50-50'),
            'logoMediaId' => $this->id($data['logoMediaId'] ?? null),
            'background' => $this->background(is_array($data['background'] ?? null) ? $data['background'] : []),
            'slots' => $this->slots(is_array($data['slots'] ?? null) ? $data['slots'] : []),
        ];
    }

    /** An empty banner — what a translation starts life with. */
    public function empty(): array
    {
        return $this->normalize([]);
    }

    /** @param array<string, mixed> $data */
    private function background(array $data): array
    {
        return [
            'color' => $this->color($data['color'] ?? null),
            'mediaId' => $this->id($data['mediaId'] ?? null),
            // Percentage, so a background image can be darkened enough for
            // text to stay readable over it.
            'overlay' => max(0, min(100, (int) ($data['overlay'] ?? 0))),
        ];
    }

    /**
     * @param array<mixed> $raw
     *
     * @return list<array<string, mixed>>
     */
    private function slots(array $raw): array
    {
        $slots = [];

        for ($i = 0; $i < self::SLOT_COUNT; ++$i) {
            $slot = is_array($raw[$i] ?? null) ? $raw[$i] : [];
            $type = $this->oneOf($slot['type'] ?? null, self::SLOT_TYPES, self::SLOT_NONE);

            // Every key is present whatever the type. Switching a slot from
            // text to image and back in the editor would otherwise lose what
            // was typed, and the front would have to guard every read.
            $slots[] = [
                'type' => $type,
                'title' => $this->text($slot['title'] ?? null),
                'description' => $this->text($slot['description'] ?? null),
                'titleColor' => $this->color($slot['titleColor'] ?? null),
                'descriptionColor' => $this->color($slot['descriptionColor'] ?? null),
                'align' => $this->oneOf($slot['align'] ?? null, self::ALIGNMENTS, 'start'),
                'mediaId' => $this->id($slot['mediaId'] ?? null),
                'alt' => $this->text($slot['alt'] ?? null),
            ];
        }

        return $slots;
    }

    /** @param list<string> $allowed */
    private function oneOf(mixed $value, array $allowed, string $fallback): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $fallback;
    }

    /**
     * Rejects anything that is not a six-digit hex. The renderer drops these
     * straight into a `style` attribute, so a loose value here is an
     * injection point rather than a cosmetic problem.
     */
    private function color(mixed $value): ?string
    {
        return is_string($value) && 1 === preg_match(self::HEX_COLOR, $value) ? mb_strtolower($value) : null;
    }

    private function id(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? mb_trim($value) : '';
    }
}
