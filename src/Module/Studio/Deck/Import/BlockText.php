<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deck\Import;

use function html_entity_decode;
use function mb_trim;
use function preg_replace;
use function strip_tags;

use const ENT_HTML5;
use const ENT_QUOTES;

/**
 * The words inside a block, as a slide spells them.
 *
 * Editor.js stores inline formatting as HTML, and a slide stores it as the
 * three marks `emphasis.js` understands. Translating rather than stripping is
 * the point of the importer: somebody who bolded a word while writing meant it
 * bolded, and losing it at the door would make the import a downgrade.
 *
 * **Everything else goes, and the text it wrapped stays.** A link is ink nobody
 * can click from the back of a room, a highlight is a colour the deck's theme
 * never chose, a font size set in the editor is a decision the slide's own
 * scaling has to be free to overrule. The sentence survives all three.
 *
 * Stripped here rather than trusted to the renderer: this text is on its way
 * into the database, and the public share page reads it back out.
 */
final readonly class BlockText
{
    /** What survives, and what it becomes. */
    private const array MARKS = [
        '/<\/?(?:b|strong)\b[^>]*>/i' => '**',
        '/<\/?(?:i|em)\b[^>]*>/i' => '*',
        '/<\/?code\b[^>]*>/i' => '`',
    ];

    public function plain(mixed $html): string
    {
        if (!is_string($html)) {
            return '';
        }

        $text = $html;

        foreach (self::MARKS as $pattern => $mark) {
            $text = (string) preg_replace($pattern, $mark, $text);
        }

        // `<br>` is a line inside one block, and a slide has no line breaks
        // inside a slot: a space is what the sentence meant.
        $text = (string) preg_replace('/<br\s*\/?>/i', ' ', $text);

        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return mb_trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
