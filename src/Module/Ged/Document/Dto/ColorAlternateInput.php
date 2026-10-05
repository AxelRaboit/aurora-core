<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Dto;

use Symfony\Component\Validator\Constraints as Assert;

use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function is_array;
use function is_string;
use function mb_strtolower;
use function mb_trim;

/**
 * What the library asks for when it declines a visual in another colour.
 *
 * Colours are six-digit hex and nothing else: they reach GD as numbers, and
 * the screen offers a picker, never free text.
 */
final readonly class ColorAlternateInput
{
    /** How many colours can be spared at once: a handful is what makes sense. */
    public const int MAX_SPARED = 8;

    /** @param list<string> $spare */
    public function __construct(
        #[Assert\NotBlank(message: 'suite.ged.documents.recolor.errors.color_required')]
        #[Assert\Regex(pattern: '/^#[0-9a-f]{6}$/', message: 'suite.ged.documents.recolor.errors.color_invalid')]
        public string $color = '',
        #[Assert\NotBlank(message: 'suite.ged.documents.recolor.errors.label_required')]
        #[Assert\Length(max: 40)]
        public string $label = '',
        #[Assert\Regex(pattern: '/^#[0-9a-f]{6}$/', message: 'suite.ged.documents.recolor.errors.color_invalid')]
        public ?string $sourceColor = null,
        #[Assert\All([new Assert\Regex(pattern: '/^#[0-9a-f]{6}$/', message: 'suite.ged.documents.recolor.errors.color_invalid')])]
        public array $spare = [],
        public bool $protectDetail = true,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        $hex = static fn (mixed $value): ?string => is_string($value) && '' !== mb_trim($value) ? mb_strtolower(mb_trim($value)) : null;
        $spare = is_array($payload['spare'] ?? null) ? $payload['spare'] : [];

        return new self(
            color: $hex($payload['color'] ?? null) ?? '',
            label: is_string($payload['label'] ?? null) ? mb_trim($payload['label']) : '',
            sourceColor: $hex($payload['sourceColor'] ?? null),
            spare: array_slice(array_values(array_filter(array_map($hex, $spare))), 0, self::MAX_SPARED),
            protectDetail: false !== ($payload['protectDetail'] ?? true),
        );
    }
}
