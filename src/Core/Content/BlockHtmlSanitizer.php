<?php

declare(strict_types=1);

namespace Aurora\Core\Content;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Sanitizes the light HTML Editor.js allows inside text fields.
 *
 * Attribute-aware, which `strip_tags` is not: it keeps a tag whole or drops it
 * whole. The previous version allowed `<a>` and therefore also allowed
 * `<a href="javascript:alert(1)">`, and allowed `<b>` and therefore also
 * `<b onmouseover="alert(1)">` - every handler an author, or anyone who could
 * reach the field, cared to write. Every attribute here is named, and anything
 * unnamed is dropped.
 *
 * `<span>` is allowed for the three inline tools that need it - text colour,
 * background colour and font size - but only carrying the class one of them
 * sets and only the one style property that class implies. That is the whole
 * reason this stopped being a `strip_tags` call: those tools have been
 * shipping styles that were stripped on the public page, so the colours an
 * author picked were visible in the editor and nowhere else.
 *
 * Unknown tags lose the tag and keep their text. A reader should get a
 * paragraph with a word unstyled, not a paragraph with a word missing.
 *
 * Shared by the core block renderer and any module-contributed one, so the
 * rules stay identical everywhere.
 */
final readonly class BlockHtmlSanitizer
{
    /**
     * Tags that survive, with the attributes each may carry. Anything absent
     * from this map loses its tag; anything absent from a tag's list loses the
     * attribute.
     *
     * @var array<string, list<string>>
     */
    private const array ALLOWED = [
        'a' => ['href', 'title'],
        'b' => [],
        'strong' => [],
        'i' => [],
        'em' => [],
        'u' => [],
        's' => [],
        'br' => [],
        'code' => [],
        'mark' => [],
        'span' => ['class', 'style'],
    ];

    /**
     * The inline tools that produce a span, and the single style property each
     * is allowed to set. A class outside this map drops the span; a property
     * outside its entry is dropped.
     *
     * @var array<string, string>
     */
    private const array SPAN_TOOLS = [
        'cdx-text-color' => 'color',
        'cdx-text-bg' => 'background-color',
        'cdx-font-size' => 'font-size',
    ];

    /**
     * Schemes a link may use. Same whitelist as the banner's, and for the same
     * reason: `javascript:` and `data:` are why this is a list of what is
     * allowed rather than a list of what is not.
     */
    private const array URL_PREFIXES = ['/', '#', 'http://', 'https://', 'mailto:', 'tel:'];

    private const string HEX_COLOR = '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i';

    /** Relative units only - what the font-size tool offers. */
    private const string FONT_SIZE = '/^\d+(?:\.\d+)?(?:em|rem|%)$/';

    /** The wrapper the fragment is parsed inside and read back out of. */
    private const string ROOT_ID = 'aurora-sanitizer-root';

    public function safe(mixed $value): string
    {
        if (!is_string($value) || '' === mb_trim($value)) {
            return '';
        }

        $document = new DOMDocument();

        // Two pieces of scaffolding, both needed.
        //
        // The meta forces UTF-8: libxml assumes Latin-1 otherwise and turns
        // every accent to mojibake, silently, on a French-first application.
        //
        // The wrapper is what the content is read back out of. Serialising the
        // document's own children instead loses the whitespace *between* top
        // level tags - `<b>a</b> <em>b</em>` comes back as `<b>a</b><em>b</em>`
        // and two words run together.
        $loaded = @$document->loadHTML(
            '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">'
                .'<div id="'.self::ROOT_ID.'">'.$value.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING,
        );

        $root = $document->getElementById(self::ROOT_ID);

        if (!$loaded || !$root instanceof DOMElement) {
            // Unparseable markup keeps its text and loses its tags rather than
            // reaching a page raw.
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
        // Snapshot: unwrapping a child rewrites the live child list underneath
        // an iterator that is walking it.
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            $this->clean($child);

            $tag = mb_strtolower($child->nodeName);

            // script/style/iframe lose their contents too: the text inside
            // them is code, not something a reader was meant to see.
            if (in_array($tag, ['script', 'style', 'iframe'], true)) {
                $child->parentNode?->removeChild($child);

                continue;
            }

            if (!array_key_exists($tag, self::ALLOWED)) {
                $this->unwrap($child);

                continue;
            }

            if (!$this->cleanAttributes($child, $tag)) {
                $this->unwrap($child);
            }
        }
    }

    /**
     * @return bool false when the element cannot be kept at all - a span whose
     *              class names no known tool, or a link with no usable target
     */
    private function cleanAttributes(DOMElement $element, string $tag): bool
    {
        $keep = true;

        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = mb_strtolower($attribute->name);

            if (!in_array($name, self::ALLOWED[$tag], true)) {
                $element->removeAttribute($attribute->name);

                continue;
            }

            $value = match (true) {
                'a' === $tag && 'href' === $name => $this->url($attribute->value),
                'span' === $tag && 'class' === $name => $this->spanClass($attribute->value),
                'span' === $tag && 'style' === $name => $this->spanStyle($attribute->value, $element->getAttribute('class')),
                default => $attribute->value,
            };

            if (null === $value) {
                $element->removeAttribute($attribute->name);

                if ('a' === $tag || 'span' === $tag) {
                    $keep = false;
                }

                continue;
            }

            $element->setAttribute($attribute->name, $value);
        }

        // A span carrying nothing is a span doing nothing.
        if ('span' === $tag && !$element->hasAttribute('class')) {
            return false;
        }

        return $keep;
    }

    /** Replaces an element with its own children, keeping the text. */
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

    private function url(string $value): ?string
    {
        $url = mb_trim($value);

        if ('' === $url) {
            return null;
        }

        foreach (self::URL_PREFIXES as $prefix) {
            if (str_starts_with(mb_strtolower($url), $prefix)) {
                return $url;
            }
        }

        return null;
    }

    private function spanClass(string $value): ?string
    {
        $class = mb_trim($value);

        return array_key_exists($class, self::SPAN_TOOLS) ? $class : null;
    }

    /**
     * Keeps the single declaration the class is entitled to, and validates its
     * value. The string lands in a `style` attribute, so a loose value here is
     * an injection point rather than a cosmetic problem.
     */
    private function spanStyle(string $value, string $class): ?string
    {
        $property = self::SPAN_TOOLS[mb_trim($class)] ?? null;

        if (null === $property) {
            return null;
        }

        foreach (explode(';', $value) as $declaration) {
            $parts = explode(':', $declaration, 2);

            if (2 !== count($parts)) {
                continue;
            }

            if (mb_strtolower(mb_trim($parts[0])) !== $property) {
                continue;
            }

            $candidate = mb_trim($parts[1]);
            $valid = 'font-size' === $property
                ? 1 === preg_match(self::FONT_SIZE, $candidate)
                : 1 === preg_match(self::HEX_COLOR, $candidate);

            if ($valid) {
                return sprintf('%s: %s', $property, mb_strtolower($candidate));
            }
        }

        return null;
    }
}
