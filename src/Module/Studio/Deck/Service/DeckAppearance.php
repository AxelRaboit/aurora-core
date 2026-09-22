<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deck\Service;

use Aurora\Module\Studio\Deck\Entity\DeckInterface;
use Aurora\Module\Studio\Deck\Enum\DeckFontPairEnum;
use Aurora\Module\Studio\Deck\Enum\DeckGradientEnum;
use Aurora\Module\Studio\Deck\Enum\DeckLogoPlacementEnum;
use Aurora\Module\Studio\Deck\Enum\DeckPatternEnum;
use Aurora\Module\Studio\Deck\Enum\DeckTransitionEnum;

use function in_array;
use function is_int;
use function is_string;

/**
 * A deck's theme and its overrides, resolved into what the frame draws with.
 *
 * **Resolved here rather than in the browser**, because four different pages
 * draw the same slide: the editor's preview, the thumbnails, the full-screen
 * player, the print page and the public share link. Each of them would have to
 * merge the theme with the deck's overrides the same way, and the day the two
 * disagreed the printed deck would come out in colours the editor never showed.
 * One resolution, sent with the deck.
 */
final readonly class DeckAppearance
{
    public function __construct(private DeckPicture $pictures) {}

    /**
     * The whole appearance, flat, ready to become CSS custom properties.
     *
     * @return array<string, mixed>
     */
    public function resolve(DeckInterface $deck): array
    {
        $theme = $deck->getTheme();
        $style = $deck->getStyle();
        $palette = $theme->palette();

        $fontPair = is_string($style['fontPair'] ?? null)
            ? DeckFontPairEnum::tryFrom($style['fontPair']) ?? $theme->fonts()
            : $theme->fonts();

        $placement = is_string($style['logoPlacement'] ?? null)
            ? DeckLogoPlacementEnum::tryFrom($style['logoPlacement']) ?? DeckLogoPlacementEnum::None
            : DeckLogoPlacementEnum::None;

        $gradient = is_string($style['gradient'] ?? null)
            ? DeckGradientEnum::tryFrom($style['gradient']) ?? DeckGradientEnum::None
            : DeckGradientEnum::None;

        $pattern = is_string($style['pattern'] ?? null)
            ? DeckPatternEnum::tryFrom($style['pattern']) ?? DeckPatternEnum::None
            : DeckPatternEnum::None;

        $logo = $this->pictures->byId(is_int($style['logoMediaId'] ?? null) ? $style['logoMediaId'] : null);

        // A placement without a picture is not a placement. The two are set in
        // the same panel, but a logo deleted from the library later would
        // otherwise leave every slide reserving a corner for nothing.
        if (null === $logo) {
            $placement = DeckLogoPlacementEnum::None;
        }

        return [
            'theme' => $theme->value,
            'background' => is_string($style['background'] ?? null) ? $style['background'] : $palette['background'],
            'ink' => is_string($style['ink'] ?? null) ? $style['ink'] : $palette['ink'],
            'accent' => is_string($style['accent'] ?? null) ? $style['accent'] : $palette['accent'],
            'gradient' => $gradient->value,
            'pattern' => $pattern->value,
            'margins' => is_string($style['margins'] ?? null) && in_array($style['margins'], DeckStyleNormalizer::MARGINS, true)
                ? $style['margins']
                : 'normal',
            'hairline' => true === ($style['hairline'] ?? false),
            'titleCase' => is_string($style['titleCase'] ?? null) && in_array($style['titleCase'], DeckStyleNormalizer::TITLE_CASES, true)
                ? $style['titleCase']
                : 'normal',
            'bullets' => is_string($style['bullets'] ?? null) && in_array($style['bullets'], DeckStyleNormalizer::BULLETS, true)
                ? $style['bullets']
                : 'disc',
            'fontPair' => $fontPair->value,
            'headingFont' => $fontPair->heading(),
            'bodyFont' => $fontPair->body(),
            'logoUrl' => $logo['url'] ?? null,
            'logoAlt' => $logo['alt'] ?? '',
            'logoPlacement' => $placement->value,
            'footerText' => is_string($style['footerText'] ?? null) ? $style['footerText'] : null,
            'slideNumbers' => true === ($style['slideNumbers'] ?? false),
            'transition' => is_string($style['transition'] ?? null)
                ? (DeckTransitionEnum::tryFrom($style['transition']) ?? DeckTransitionEnum::Fade)->value
                : DeckTransitionEnum::Fade->value,
        ];
    }
}
