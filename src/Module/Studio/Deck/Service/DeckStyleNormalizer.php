<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deck\Service;

use Aurora\Module\Studio\Deck\Enum\DeckFontPairEnum;
use Aurora\Module\Studio\Deck\Enum\DeckLogoPlacementEnum;

use function array_key_exists;
use function is_bool;
use function is_int;
use function is_string;
use function mb_strtolower;
use function mb_substr;
use function mb_trim;
use function preg_match;

/**
 * What a deck may say about its own appearance, and nothing else.
 *
 * The same arrangement `DeckManager::writeContent()` uses for a slide: the
 * keys are declared here, the payload is whitelisted against them on the way
 * in, and anything else is dropped rather than refused. A stale form posting a
 * key the module no longer has is not an error worth showing a reader.
 *
 * **An absent key means "inherit the theme", which is why nothing is written
 * with a default.** Storing the theme's own blue as the deck's accent would
 * freeze it: the deck would keep that blue the day the theme is retuned, and
 * nobody would know why. Only what somebody actually chose is stored.
 */
final readonly class DeckStyleNormalizer
{
    /** The three colours a deck may override, in the order the form shows them. */
    public const array COLOURS = ['background', 'ink', 'accent'];

    /** A footer is a line, not a paragraph: it sits in 2.4% of a slide's width. */
    private const int FOOTER_MAX = 120;

    /**
     * @param array<string, mixed> $style
     *
     * @return array<string, mixed>
     */
    public function normalize(array $style): array
    {
        $clean = [];

        foreach (self::COLOURS as $key) {
            $colour = $this->colour($style[$key] ?? null);

            if (null !== $colour) {
                $clean[$key] = $colour;
            }
        }

        if (is_string($style['fontPair'] ?? null) && DeckFontPairEnum::tryFrom($style['fontPair']) instanceof DeckFontPairEnum) {
            $clean['fontPair'] = $style['fontPair'];
        }

        // A zero or a negative id is not a document, it is a picker that was
        // cleared and posted the value an empty field holds.
        if (is_int($style['logoMediaId'] ?? null) && $style['logoMediaId'] > 0) {
            $clean['logoMediaId'] = $style['logoMediaId'];
        }

        $placement = is_string($style['logoPlacement'] ?? null)
            ? DeckLogoPlacementEnum::tryFrom($style['logoPlacement'])
            : null;

        if ($placement instanceof DeckLogoPlacementEnum && DeckLogoPlacementEnum::None !== $placement) {
            $clean['logoPlacement'] = $placement->value;
        }

        if (is_string($style['footerText'] ?? null)) {
            $footer = mb_trim($style['footerText']);

            if ('' !== $footer) {
                $clean['footerText'] = mb_substr($footer, 0, self::FOOTER_MAX);
            }
        }

        // Only the true is stored: the absence already says "no numbers", and a
        // stored false would be a second way to spell the same thing.
        if (array_key_exists('slideNumbers', $style) && is_bool($style['slideNumbers']) && $style['slideNumbers']) {
            $clean['slideNumbers'] = true;
        }

        return $clean;
    }

    /**
     * A colour a browser will actually draw, or null.
     *
     * Six hexadecimal digits and nothing else, because the value is written
     * into a CSS custom property: a string that is not a colour there does not
     * fail, it makes the property invalid and the slide falls back to the
     * theme's, which reads as "my choice was ignored" with nothing said.
     */
    private function colour(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $candidate = mb_strtolower(mb_trim($value));

        return 1 === preg_match('/^#[0-9a-f]{6}$/', $candidate) ? $candidate : null;
    }
}
