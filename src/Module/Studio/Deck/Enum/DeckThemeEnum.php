<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deck\Enum;

/**
 * The look a deck is drawn in.
 *
 * **Themes declared in code rather than rows in a table**, which is the same
 * call `SlideLayoutEnum` makes about shapes and for the same reasons. A theme
 * is three colours and a pair of faces; a table for it would mean a CRUD
 * screen, a migration that seeds it, and a deck pointing at a theme somebody
 * deleted. Declared here, every install has the same five on the day it
 * upgrades, and a deck that names one can never dangle.
 *
 * What is *not* fixed is the deck's own palette: `DeckStyleNormalizer` lets a
 * deck override any of the three colours and the pair of faces, so a client's
 * own blue is a field rather than a new case here. The theme is the starting
 * point, not the ceiling.
 *
 * `Aurora` is the look every deck had before this enum existed, and it stays
 * the default so no deck changes appearance the day the column is added.
 */
enum DeckThemeEnum: string
{
    /** The back office's own surface. What every deck looked like before. */
    case Slate = 'slate';

    /** Near black, warm accent. The default for something shown in a room. */
    case Ink = 'ink';

    /** Light, quiet, printed. Reads as a document handed over. */
    case Paper = 'paper';

    /** Light and loud. One red that carries the whole deck. */
    case Bold = 'bold';

    /** Deep blue. The corporate register, without the grey. */
    case Midnight = 'midnight';

    /** Warm and printed. Terracotta on paper that has been left in the sun. */
    case Clay = 'clay';

    /** Almost white, one blue. The register of a school or a hospital. */
    case Chalk = 'chalk';

    /** Deep green and a yellow that carries. The loudest of the eight. */
    case Duo = 'duo';

    /**
     * The three colours the frame draws with.
     *
     * Three and not thirty: a background, the text on it, and one accent. Every
     * other tone in a slide is derived from these at render with `color-mix`,
     * so a rule, a card and an image placeholder always sit at a fixed distance
     * from the ground rather than being three more fields to keep in agreement.
     *
     * @return array{background: string, ink: string, accent: string}
     */
    public function palette(): array
    {
        return match ($this) {
            self::Slate => ['background' => '#161b22', 'ink' => '#e6e9ef', 'accent' => '#58a6ff'],
            self::Ink => ['background' => '#101317', 'ink' => '#f2efe9', 'accent' => '#e08b3e'],
            self::Paper => ['background' => '#faf8f4', 'ink' => '#1a1c20', 'accent' => '#1a6a5a'],
            self::Bold => ['background' => '#e8e4dc', 'ink' => '#17181a', 'accent' => '#c2371f'],
            self::Midnight => ['background' => '#0c1b3a', 'ink' => '#eef3fb', 'accent' => '#79b4ff'],
            self::Clay => ['background' => '#f7efe6', 'ink' => '#2a1f19', 'accent' => '#b4541f'],
            self::Chalk => ['background' => '#fdfdfb', 'ink' => '#14171c', 'accent' => '#2f4f9e'],
            self::Duo => ['background' => '#1c2b2a', 'ink' => '#f2f7f5', 'accent' => '#f2b33d'],
        };
    }

    /** The pair of faces the theme was drawn with, before any override. */
    public function fonts(): DeckFontPairEnum
    {
        return match ($this) {
            self::Slate, self::Midnight => DeckFontPairEnum::Sans,
            self::Ink => DeckFontPairEnum::Technical,
            self::Paper => DeckFontPairEnum::Serif,
            self::Bold, self::Clay => DeckFontPairEnum::Editorial,
            self::Chalk => DeckFontPairEnum::Sans,
            self::Duo => DeckFontPairEnum::Technical,
        };
    }

    /**
     * The wash and the texture a theme arrives with.
     *
     * **The five older themes carry none, and that is not an oversight.** A
     * theme that started carrying a gradient would repaint every deck already
     * drawn in it, which is the one thing this enum promised never to do. The
     * three added with this method are free to arrive composed, because no deck
     * has ever been drawn in them.
     *
     * A deck that picks its own wash overrides this, exactly as it overrides
     * the palette and the faces.
     */
    public function gradient(): DeckGradientEnum
    {
        return match ($this) {
            self::Slate, self::Ink, self::Paper, self::Bold, self::Midnight, self::Chalk => DeckGradientEnum::None,
            self::Clay => DeckGradientEnum::Corner,
            self::Duo => DeckGradientEnum::Halo,
        };
    }

    public function pattern(): DeckPatternEnum
    {
        return match ($this) {
            self::Slate, self::Ink, self::Paper, self::Bold, self::Midnight, self::Clay, self::Duo => DeckPatternEnum::None,
            self::Chalk => DeckPatternEnum::Grid,
        };
    }

    public function labelKey(): string
    {
        return 'backend.studio.decks.themes.'.$this->value;
    }
}
