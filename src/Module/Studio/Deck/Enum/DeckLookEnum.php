<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deck\Enum;

/**
 * A whole appearance, under one name.
 *
 * **The answer to a panel that asks eight questions of somebody who is not a
 * designer.** Theme, wash, texture, faces, margins, title case, bullet shape:
 * each is a reasonable question on its own, and together they are a form to
 * fill in before a deck can look like anything. A look answers all of them at
 * once, with a combination that was chosen rather than assembled.
 *
 * **Applied and forgotten.** Picking a look writes its theme and its style
 * keys into the deck and nothing records which look it was. A deck that stored
 * the name would be a deck whose appearance changed the day somebody edited
 * this enum, and a reader who nudged one colour afterwards would be left with
 * a deck claiming a look it no longer has. Here the look is a starting point,
 * the same way a theme is: once applied, every key is the deck's own and can
 * be changed one by one.
 *
 * Four, and each is a register rather than a taste: a deck shown in a room, a
 * deck that argues, a deck that is read, and a deck that is mostly pictures.
 */
enum DeckLookEnum: string
{
    /** Shown on a wall. Dark, loud titles, the wash rising under the words. */
    case Scene = 'scene';

    /** It argues. Light and warm, hatched, arrows rather than discs. */
    case Atelier = 'atelier';

    /** It is read, not watched. Quiet, squared, wide margins, ruled. */
    case Dossier = 'dossier';

    /** The pictures carry it. Near black, tight margins, nothing in the way. */
    case Gallery = 'gallery';

    public function theme(): DeckThemeEnum
    {
        return match ($this) {
            self::Scene, self::Gallery => DeckThemeEnum::Ink,
            self::Atelier => DeckThemeEnum::Bold,
            self::Dossier => DeckThemeEnum::Paper,
        };
    }

    /**
     * The keys the look writes, in the shape `DeckStyleNormalizer` expects.
     *
     * Only what the look actually decides. A key left out here is a key the
     * deck keeps, which is how applying a look over a deck that already chose
     * its own accent leaves that accent alone.
     *
     * @return array<string, bool|string>
     */
    public function style(): array
    {
        return match ($this) {
            self::Scene => [
                'gradient' => DeckGradientEnum::Bottom->value,
                'titleCase' => 'upper',
                'bullets' => 'arrow',
                'margins' => 'normal',
            ],
            self::Atelier => [
                'pattern' => DeckPatternEnum::Diagonals->value,
                'titleCase' => 'upper',
                'bullets' => 'dash',
                'margins' => 'normal',
                'rules' => true,
            ],
            self::Dossier => [
                'pattern' => DeckPatternEnum::Grid->value,
                'titleCase' => 'normal',
                'bullets' => 'dash',
                'margins' => 'wide',
                'rules' => true,
            ],
            self::Gallery => [
                'gradient' => DeckGradientEnum::None->value,
                'pattern' => DeckPatternEnum::None->value,
                'titleCase' => 'normal',
                'bullets' => 'disc',
                'margins' => 'tight',
            ],
        };
    }

    public function labelKey(): string
    {
        return 'backend.studio.decks.looks.'.$this->value;
    }

    public function descriptionKey(): string
    {
        return 'backend.studio.decks.look_descriptions.'.$this->value;
    }
}
