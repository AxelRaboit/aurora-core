<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deck\Service;

use DOMDocument;
use DOMElement;
use DOMNode;

use function array_key_exists;
use function explode;
use function implode;
use function in_array;
use function is_string;
use function iterator_to_array;
use function mb_strtolower;
use function mb_trim;
use function preg_match;
use function str_contains;
use function strip_tags;

use const LIBXML_HTML_NODEFDTD;
use const LIBXML_HTML_NOIMPLIED;
use const LIBXML_NOERROR;
use const LIBXML_NOWARNING;

/**
 * The markup a text box on a free slide may keep.
 *
 * A text box is edited in place, in the slide, with the browser's own editing:
 * bold, italic, a colour on a word, a highlighter, a list. What comes back is
 * whatever `contenteditable` wrote, which is a different dialect in each
 * browser and is, above all, HTML a reader posted. It lands in `v-html` on the
 * public share page, so it is filtered here, on the way in, the way
 * `BlockHtmlSanitizer` filters an article's fields.
 *
 * **A separate list from the article's**, because the two editors write
 * different things: no links (a box links as a whole, through its own
 * setting), no classes (nothing in a slide matches on them), and a `style` on
 * a span that may carry the two colour properties and nothing else.
 *
 * Unknown tags lose the tag and keep their text, as in the article sanitizer:
 * a box with one word unstyled is better than a box with one word missing.
 */
final readonly class FreeTextSanitizer
{
    /** Tags that survive. Only `span` keeps an attribute, and only `style`. */
    private const array ALLOWED = [
        'b' => [],
        'strong' => [],
        'i' => [],
        'em' => [],
        'u' => [],
        's' => [],
        'strike' => [],
        'br' => [],
        'div' => [],
        'p' => [],
        'ul' => [],
        'ol' => [],
        'li' => [],
        'span' => ['style'],
    ];

    /** The two properties a span may set: a word's colour and its highlight. */
    private const array STYLE_PROPERTIES = ['color', 'background-color'];

    /**
     * A colour as the browser writes it back: hexadecimal, or `rgb()` with
     * three or four plain numbers. Nothing that could hold a `url()` or a
     * `var()` with a fallback.
     */
    private const string COLOUR = '/^(?:#[0-9a-f]{3,8}|rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(?:,\s*(?:0|1|0?\.\d+)\s*)?\))$/i';

    private const string ROOT_ID = 'aurora-free-text-root';

    public function safe(mixed $value): string
    {
        if (!is_string($value) || '' === mb_trim($value)) {
            return '';
        }

        $document = new DOMDocument();

        // The meta forces UTF-8 and the wrapper is what the content is read
        // back out of; both for the reasons `BlockHtmlSanitizer` gives.
        $loaded = @$document->loadHTML(
            '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">'
                .'<div id="'.self::ROOT_ID.'">'.$value.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING,
        );

        $root = $document->getElementById(self::ROOT_ID);

        if (!$loaded || !$root instanceof DOMElement) {
            return strip_tags($value);
        }

        $this->clean($root);

        $html = '';
        foreach ($root->childNodes as $child) {
            $html .= $document->saveHTML($child);
        }

        return $html;
    }

    private function clean(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            $this->clean($child);

            $tag = mb_strtolower($child->nodeName);

            if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'template'], true)) {
                $child->parentNode?->removeChild($child);

                continue;
            }

            if (!array_key_exists($tag, self::ALLOWED)) {
                $this->unwrap($child);

                continue;
            }

            foreach (iterator_to_array($child->attributes) as $attribute) {
                $name = mb_strtolower($attribute->name);

                if (!in_array($name, self::ALLOWED[$tag], true)) {
                    $child->removeAttribute($attribute->name);

                    continue;
                }

                $style = $this->style($attribute->value);

                if (null === $style) {
                    $child->removeAttribute($attribute->name);

                    continue;
                }

                $child->setAttribute($attribute->name, $style);
            }

            // A span that says nothing is a span doing nothing.
            if ('span' === $tag && !$child->hasAttribute('style')) {
                $this->unwrap($child);
            }
        }
    }

    /**
     * The colour declarations of a style attribute, and nothing else.
     *
     * The string lands in a `style` attribute on a public page, so a loose
     * value here is an injection point rather than a cosmetic problem: every
     * value is matched against the colour pattern, never merely trimmed.
     */
    private function style(string $value): ?string
    {
        $kept = [];

        foreach (explode(';', $value) as $declaration) {
            if (!str_contains($declaration, ':')) {
                continue;
            }

            [$property, $colour] = explode(':', $declaration, 2);
            $property = mb_strtolower(mb_trim($property));
            $colour = mb_trim($colour);

            if (in_array($property, self::STYLE_PROPERTIES, true) && 1 === preg_match(self::COLOUR, $colour)) {
                $kept[] = $property.': '.$colour;
            }
        }

        return [] === $kept ? null : implode('; ', $kept);
    }

    private function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;

        if (!$parent instanceof DOMNode) {
            return;
        }

        foreach (iterator_to_array($element->childNodes) as $child) {
            $parent->insertBefore($child, $element);
        }

        $parent->removeChild($element);
    }
}
