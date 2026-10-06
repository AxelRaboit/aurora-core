<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Slides;

use Aurora\Module\Studio\Deck\Enum\DeckFontPairEnum;
use Aurora\Module\Studio\Deck\Enum\DeckGradientEnum;
use Aurora\Module\Studio\Deck\Enum\DeckLogoPlacementEnum;
use Aurora\Module\Studio\Deck\Enum\DeckLookEnum;
use Aurora\Module\Studio\Deck\Enum\DeckPatternEnum;
use Aurora\Module\Studio\Deck\Enum\DeckThemeEnum;
use Aurora\Module\Studio\Deck\Enum\DeckTransitionEnum;
use Aurora\Module\Studio\Deck\Enum\SlideLayoutEnum;
use Aurora\Module\Studio\Deck\Service\DeckFonts;
use Aurora\Module\Studio\Deck\Service\DeckStyleNormalizer;
use Aurora\Module\Studio\Deck\Service\FreeSlideNormalizer;

use function array_map;

/**
 * What the slide editor offers, whoever owns the slides: the layouts and
 * their slots, the themes and everything the appearance panel picks from.
 *
 * One list, handed to the editor from here: two lists of themes would be two
 * lists that disagree the first time one of them gains a value.
 */
final readonly class SlideEditorOptions
{
    public function __construct(private DeckFonts $fonts) {}

    /**
     * Everything the editor reads before anybody picks anything.
     *
     * The layouts come along because the editor needs to know which fields a
     * shape offers before anybody picks it.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return [
            'layouts' => $this->layouts(),
            'commonSlots' => SlideLayoutEnum::commonSlots(),
            'listSlots' => SlideLayoutEnum::listSlots(),
            'freeOptions' => FreeSlideNormalizer::options(),
            'uploadedFonts' => $this->fonts->all(),
            'themes' => $this->themeOptions(),
            'fontPairs' => $this->fontPairOptions(),
            'logoPlacements' => $this->logoPlacementOptions(),
            'looks' => $this->lookOptions(),
            'gradients' => $this->gradientOptions(),
            'patterns' => $this->patternOptions(),
            'margins' => DeckStyleNormalizer::MARGINS,
            'titleCases' => DeckStyleNormalizer::TITLE_CASES,
            'bulletShapes' => DeckStyleNormalizer::BULLETS,
            'transitions' => $this->transitionOptions(),
        ];
    }

    /** @return list<array{value: string, labelKey: string, slots: list<string>}> */
    public function layouts(): array
    {
        return array_map(
            static fn (SlideLayoutEnum $layout): array => [
                'value' => $layout->value,
                'labelKey' => $layout->labelKey(),
                'slots' => $layout->slots(),
            ],
            SlideLayoutEnum::cases(),
        );
    }

    /**
     * The themes, each carrying the colours it starts from.
     *
     * The palette travels with the option so the picker can draw the theme
     * rather than name it: five words in a select say nothing about what they
     * look like, and the whole point of the list is the look.
     *
     * The pair of faces travels with it for the same reason as the palette: the
     * panel previews a theme before it is saved, and without the theme's own
     * pair it would preview the new colours in the *previous* theme's faces.
     *
     * @return list<array{value: string, labelKey: string, palette: array{background: string, ink: string, accent: string}, fontPair: string, gradient: string, pattern: string}>
     */
    private function themeOptions(): array
    {
        return array_map(
            static fn (DeckThemeEnum $theme): array => [
                'value' => $theme->value,
                'labelKey' => $theme->labelKey(),
                'palette' => $theme->palette(),
                'fontPair' => $theme->fonts()->value,
                'gradient' => $theme->gradient()->value,
                'pattern' => $theme->pattern()->value,
            ],
            DeckThemeEnum::cases(),
        );
    }

    /** @return list<array{value: string, labelKey: string, heading: string, body: string}> */
    private function fontPairOptions(): array
    {
        return array_map(
            static fn (DeckFontPairEnum $pair): array => [
                'value' => $pair->value,
                'labelKey' => $pair->labelKey(),
                'heading' => $pair->heading(),
                'body' => $pair->body(),
            ],
            DeckFontPairEnum::cases(),
        );
    }

    /** @return list<array{value: string, labelKey: string}> */
    private function gradientOptions(): array
    {
        return array_map(
            static fn (DeckGradientEnum $gradient): array => [
                'value' => $gradient->value,
                'labelKey' => $gradient->labelKey(),
            ],
            DeckGradientEnum::cases(),
        );
    }

    /**
     * The looks, each carrying everything it writes.
     *
     * The whole combination travels with the option, the way a theme's palette
     * already does: the panel has to preview a look before anybody commits to
     * it, and a preview that needed a round trip would make trying four looks
     * four saves.
     *
     * @return list<array{value: string, labelKey: string, descriptionKey: string, theme: string, style: array<string, bool|string>}>
     */
    private function lookOptions(): array
    {
        return array_map(
            static fn (DeckLookEnum $look): array => [
                'value' => $look->value,
                'labelKey' => $look->labelKey(),
                'descriptionKey' => $look->descriptionKey(),
                'theme' => $look->theme()->value,
                'style' => $look->style(),
            ],
            DeckLookEnum::cases(),
        );
    }

    /** @return list<array{value: string, labelKey: string}> */
    private function patternOptions(): array
    {
        return array_map(
            static fn (DeckPatternEnum $pattern): array => [
                'value' => $pattern->value,
                'labelKey' => $pattern->labelKey(),
            ],
            DeckPatternEnum::cases(),
        );
    }

    /** @return list<array{value: string, labelKey: string}> */
    private function transitionOptions(): array
    {
        return array_map(
            static fn (DeckTransitionEnum $transition): array => [
                'value' => $transition->value,
                'labelKey' => $transition->labelKey(),
            ],
            DeckTransitionEnum::cases(),
        );
    }

    /** @return list<array{value: string, labelKey: string}> */
    private function logoPlacementOptions(): array
    {
        return array_map(
            static fn (DeckLogoPlacementEnum $placement): array => [
                'value' => $placement->value,
                'labelKey' => $placement->labelKey(),
            ],
            DeckLogoPlacementEnum::cases(),
        );
    }
}
