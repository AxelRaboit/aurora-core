<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deck\Service;

use Aurora\Module\Studio\Deck\Enum\DeckFontPairEnum;
use Aurora\Module\Studio\Deck\Enum\DeckGradientEnum;
use Aurora\Module\Studio\Deck\Enum\DeckLogoPlacementEnum;
use Aurora\Module\Studio\Deck\Enum\DeckPatternEnum;
use Aurora\Module\Studio\Deck\Enum\DeckTransitionEnum;

use function array_key_exists;
use function in_array;
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

    /** @var list<string> */
    public const array MARGINS = ['tight', 'normal', 'wide'];

    /** @var list<string> */
    public const array TITLE_CASES = ['normal', 'upper'];

    /** @var list<string> */
    public const array BULLETS = ['disc', 'dash', 'arrow', 'number', 'check'];

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

        // The default here is `fade` rather than the absence of a key, so the
        // one stored is the one somebody chose - including when they chose the
        // cut. Storing nothing for `fade` would make "I picked fade" and "I
        // never looked at this" the same row.
        if (is_string($style['transition'] ?? null) && DeckTransitionEnum::tryFrom($style['transition']) instanceof DeckTransitionEnum) {
            $clean['transition'] = $style['transition'];
        }

        // Stored only when it draws something, on the same reasoning as
        // `logoPlacement`: the absence of the key already spells the flat
        // ground, and a stored `none` would be a second way to say it.
        $gradient = is_string($style['gradient'] ?? null)
            ? DeckGradientEnum::tryFrom($style['gradient'])
            : null;

        if ($gradient instanceof DeckGradientEnum && DeckGradientEnum::None !== $gradient) {
            $clean['gradient'] = $gradient->value;
        }

        // Three widths of margin, the middle one being what every deck had
        // before. Stored even when it is the middle one: unlike the wash, a
        // margin is not an effect somebody added, it is a choice about the
        // frame, and "I looked at this and kept the usual one" is worth
        // keeping apart from "I never opened the panel".
        if (is_string($style['margins'] ?? null) && in_array($style['margins'], self::MARGINS, true)) {
            $clean['margins'] = $style['margins'];
        }

        if (is_string($style['titleCase'] ?? null) && in_array($style['titleCase'], self::TITLE_CASES, true)) {
            $clean['titleCase'] = $style['titleCase'];
        }

        if (is_string($style['bullets'] ?? null) && in_array($style['bullets'], self::BULLETS, true)) {
            $clean['bullets'] = $style['bullets'];
        }

        if (array_key_exists('rules', $style) && is_bool($style['rules']) && $style['rules']) {
            $clean['rules'] = true;
        }

        // Only the true, like `slideNumbers`.
        if (array_key_exists('hairline', $style) && is_bool($style['hairline']) && $style['hairline']) {
            $clean['hairline'] = true;
        }

        // Same shape as the gradient, and for the same reason: the bare
        // ground is the absence of the key.
        $pattern = is_string($style['pattern'] ?? null)
            ? DeckPatternEnum::tryFrom($style['pattern'])
            : null;

        if ($pattern instanceof DeckPatternEnum && DeckPatternEnum::None !== $pattern) {
            $clean['pattern'] = $pattern->value;
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
