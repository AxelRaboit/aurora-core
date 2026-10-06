<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Service;

use Aurora\Module\Configuration\Theme\Service\AppearanceValues;

use function in_array;
use function is_array;

/**
 * What a deliverable repaints for itself alone, and the only way into its
 * page's `<style>`.
 *
 * **Every colour is checked here, on write.** They end up in a public
 * `<style>` tag: an unchecked value there would be arbitrary CSS. A colour
 * that is not a hexadecimal is therefore made null, and null means "the
 * theme's", never "none".
 *
 * The keys are the publications' for the surfaces (background, header,
 * footer), because the theme resolves them and already knows them; the
 * deliverable adds the heading colour and the key figure colour, which a
 * document in the client's colours has every reason to change.
 */
final class DeliverableAppearance
{
    /** The colours a deliverable can choose, and what they repaint. */
    public const array COLORS = [
        'backgroundColor',
        'headerColor',
        'footerColor',
        'accentColor',
        'headingColor',
        'figureColor',
    ];

    /**
     * How the large headings are drawn: the theme's, or in bold capitals, a
     * report's page title ("ANALYSE DE L'ENGAGEMENT").
     */
    public const array HEADING_STYLES = ['theme', 'display'];

    /**
     * How the document is read: a page you scroll, or a presentation you step
     * through section by section.
     */
    public const array DISPLAYS = ['page', 'slides'];

    /**
     * @return array{backgroundColor: ?string, headerColor: ?string, footerColor: ?string, accentColor: ?string, headingColor: ?string, figureColor: ?string, highlight: ?string, highlightColor: ?string, titleVisible: bool, headingStyle: string, display: string, readerPdf: bool}
     */
    public static function normalize(mixed $raw): array
    {
        $data = is_array($raw) ? $raw : [];

        $appearance = [];
        foreach (self::COLORS as $key) {
            $appearance[$key] = AppearanceValues::color($data[$key] ?? null);
        }

        // "Personnalisé" without a colour means nothing: back to the theme.
        $highlight = AppearanceValues::highlight($data['highlight'] ?? null, $data['highlightColor'] ?? null);
        $highlightColor = AppearanceValues::color($data['highlightColor'] ?? null);

        return [
            ...$appearance,
            'highlight' => $highlight,
            'highlightColor' => $highlightColor,
            // The title and the summary at the top of the page. True by
            // default: a deliverable that opens on a banner block turns it off
            // itself.
            'titleVisible' => false !== ($data['titleVisible'] ?? true),
            'headingStyle' => in_array($data['headingStyle'] ?? null, self::HEADING_STYLES, true) ? $data['headingStyle'] : self::HEADING_STYLES[0],
            'display' => in_array($data['display'] ?? null, self::DISPLAYS, true) ? $data['display'] : self::DISPLAYS[0],
            // The reader's PDF: off until the author allows it.
            // Only a plain `true` turns it on, never a string or a 1.
            'readerPdf' => true === ($data['readerPdf'] ?? false),
        ];
    }
}
