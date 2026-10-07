<?php

declare(strict_types=1);

namespace Aurora\Core\Content;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

use function in_array;

/**
 * Cleans the HTML of the "source code" block, more permissive than
 * {@see BlockHtmlSanitizer} but still closed to scripts.
 *
 * Two filters coexist because two needs coexist. Running text must accept
 * only what the toolbar produces: a handful of inline tags, nothing else. The
 * source block exists, on the contrary, to write what the editor cannot do: a
 * layout, a complex table, an embedded player. Applying the running-text
 * filter to it would empty it, and therefore make it useless.
 *
 * What never gets through, whatever the permissiveness:
 *
 * - `<script>`, and `<style>` which could repaint the whole page;
 * - `<form>` and its fields, which would invite people to type a password on
 *   a public page with nothing to give it away;
 * - `<object>`, `<embed>`, `<link>`, `<meta>`, `<base>`;
 * - any `on*` attribute, so any event handler;
 * - `javascript:` URLs, and `data:` URLs except images.
 *
 * `<iframe>` elements are only accepted towards the listed hosts: a frame to
 * anywhere is a whole page you do not control, set inside your own.
 */
final class RawHtmlSanitizer
{
    /** Attributes accepted on any tag. */
    private const array GLOBAL_ATTRIBUTES = ['class', 'style', 'id', 'title', 'lang', 'dir', 'role'];

    /** Tag => its own attributes, on top of the global ones. */
    private const array ALLOWED = [
        'div' => [], 'section' => [], 'article' => [], 'aside' => [], 'header' => [], 'footer' => [],
        'p' => [], 'br' => [], 'hr' => [],
        'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
        'ul' => [], 'ol' => ['start', 'reversed'], 'li' => ['value'],
        'dl' => [], 'dt' => [], 'dd' => [],
        'blockquote' => ['cite'], 'pre' => [], 'code' => [],
        'span' => [], 'small' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [],
        'u' => [], 's' => [], 'mark' => [], 'sub' => [], 'sup' => [], 'abbr' => [],
        'table' => [], 'thead' => [], 'tbody' => [], 'tfoot' => [], 'caption' => [],
        'tr' => [], 'th' => ['colspan', 'rowspan', 'scope'], 'td' => ['colspan', 'rowspan'],
        'colgroup' => ['span'], 'col' => ['span'],
        'figure' => [], 'figcaption' => [],
        'a' => ['href', 'target', 'rel'],
        'img' => ['src', 'alt', 'width', 'height', 'loading', 'decoding'],
        'picture' => [], 'source' => ['src', 'srcset', 'type', 'media'],
        'iframe' => ['src', 'width', 'height', 'allow', 'allowfullscreen', 'loading', 'referrerpolicy'],

        // SVG subset sufficient for a drawn icon, and nothing more. Deliberately
        // absent: `use`, which references an external document; `foreignObject`,
        // which would bring arbitrary HTML back in the middle of the SVG; `image`,
        // `style`, and every animation tag.
        'svg' => ['viewBox', 'width', 'height', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'xmlns', 'aria-hidden', 'focusable', 'preserveAspectRatio'],
        'g' => ['fill', 'stroke', 'stroke-width', 'transform', 'opacity'],
        'path' => ['d', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'fill-rule', 'clip-rule', 'transform', 'opacity'],
        'circle' => ['cx', 'cy', 'r', 'fill', 'stroke', 'stroke-width', 'transform', 'opacity'],
        'ellipse' => ['cx', 'cy', 'rx', 'ry', 'fill', 'stroke', 'stroke-width', 'transform', 'opacity'],
        'rect' => ['x', 'y', 'width', 'height', 'rx', 'ry', 'fill', 'stroke', 'stroke-width', 'transform', 'opacity'],
        'line' => ['x1', 'y1', 'x2', 'y2', 'stroke', 'stroke-width', 'stroke-linecap', 'transform', 'opacity'],
        'polyline' => ['points', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'transform', 'opacity'],
        'polygon' => ['points', 'fill', 'stroke', 'stroke-width', 'transform', 'opacity'],
    ];

    /**
     * SVG attributes whose case matters, restored afterwards.
     *
     * The PHP HTML parser lowercases every attribute name, which is correct in
     * HTML and wrong in SVG: a `viewbox` is ignored by browsers, and the icon
     * loses its framing without any error reporting it. The fix happens at
     * serialisation, since `setAttribute` lowercases again anyway.
     */
    private const array SVG_CASED_ATTRIBUTES = [
        'viewbox' => 'viewBox',
        'preserveaspectratio' => 'preserveAspectRatio',
    ];

    /**
     * Hosts accepted in an `<iframe>`.
     *
     * Deliberately short. A frame loads a whole page with its own scripts: the
     * list must remain that of the services we chose to trust, not a convenience
     * that gets widened request after request.
     *
     * Public since 0.9.189 because the `Content-Security-Policy` reads it for its
     * `frame-src`. One list, two enforcements: this one removes the frame from
     * the saved HTML, the policy prevents the browser from loading another one.
     * Two lists would have diverged, and the divergence would have shown the day
     * one of them allowed what the other refuses.
     */
    public const array IFRAME_HOSTS = [
        'www.youtube.com', 'www.youtube-nocookie.com', 'youtube.com',
        'player.vimeo.com',
        'www.dailymotion.com',
        'www.google.com',
        'codepen.io',
        'open.spotify.com',
        'w.soundcloud.com',
    ];

    /** URL schemes accepted outside images. */
    private const array URL_PREFIXES = ['/', '#', 'http://', 'https://', 'mailto:', 'tel:'];

    public function safe(mixed $value): string
    {
        $html = is_string($value) ? mb_trim($value) : '';
        if ('' === $html) {
            return '';
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // The fragment is wrapped so that DOMDocument invents neither <html> nor
        // <body> for it, and the header forces the UTF-8 that the parser would
        // otherwise assume to be latin-1.
        $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="aurora-raw">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('aurora-raw');
        if (!$root instanceof DOMElement) {
            return '';
        }

        $this->clean($root, $document);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $document->saveHTML($child);
        }

        foreach (self::SVG_CASED_ATTRIBUTES as $lowered => $cased) {
            $out = str_replace(' '.$lowered.'=', ' '.$cased.'=', $out);
        }

        return $out;
    }

    private function clean(DOMNode $node, DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);
        /** @var iterable<DOMElement> $elements */
        $elements = iterator_to_array($xpath->query('.//*', $node) ?: []);

        foreach ($elements as $element) {
            $tag = mb_strtolower($element->nodeName);

            if (!array_key_exists($tag, self::ALLOWED)) {
                // The tag is removed but its text is kept: a reader must get a paragraph
                // without formatting, not a truncated paragraph. Except for tags whose
                // content is not text meant to be read.
                $this->unwrapOrRemove($element, $tag);
                continue;
            }

            if ('iframe' === $tag && !$this->allowedFrame($element->getAttribute('src'))) {
                $element->parentNode?->removeChild($element);
                continue;
            }

            $this->cleanAttributes($element, $tag);
        }
    }

    private function unwrapOrRemove(DOMElement $element, string $tag): void
    {
        $parent = $element->parentNode;
        if (!$parent instanceof DOMNode) {
            return;
        }

        // The content of a script or a stylesheet is not prose: unwrapping it would
        // show code to the reader.
        if (in_array($tag, [
            'script', 'style', 'link', 'meta', 'base', 'object', 'embed',
            'form', 'input', 'button', 'select', 'textarea',
            // On the SVG side: `use` points elsewhere, `foreignObject` reopens HTML,
            // animations can trigger behaviours.
            'use', 'foreignobject', 'image', 'animate', 'animatetransform', 'animatemotion', 'set', 'script',
        ], true)) {
            $parent->removeChild($element);

            return;
        }

        while ($element->firstChild instanceof DOMNode) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }

    private function cleanAttributes(DOMElement $element, string $tag): void
    {
        $allowed = [...self::GLOBAL_ATTRIBUTES, ...self::ALLOWED[$tag]];

        /** @var list<DOMAttr> $attributes */
        $attributes = iterator_to_array($element->attributes ?? []);

        foreach ($attributes as $attribute) {
            $name = mb_strtolower($attribute->nodeName);

            if (!in_array($name, array_map(mb_strtolower(...), $allowed), true)) {
                $element->removeAttribute($attribute->nodeName);
                continue;
            }

            if (in_array($name, ['href', 'src', 'cite'], true)) {
                $url = $this->url($attribute->value, 'img' === $tag || 'source' === $tag);
                if (null === $url) {
                    $element->removeAttribute($attribute->nodeName);
                    continue;
                }

                $element->setAttribute($attribute->nodeName, $url);
            }
        }

        // A link that opens elsewhere without `rel` leaves the opening page
        // reachable by the target. We set it rather than refusing `target`.
        if ('a' === $tag && '' !== $element->getAttribute('target')) {
            $element->setAttribute('rel', 'noopener noreferrer');
        }
    }

    private function allowedFrame(string $source): bool
    {
        $host = parse_url($source, PHP_URL_HOST);

        return is_string($host) && in_array(mb_strtolower($host), self::IFRAME_HOSTS, true);
    }

    private function url(string $value, bool $imageContext): ?string
    {
        $url = mb_trim($value);
        if ('' === $url) {
            return null;
        }

        $lower = mb_strtolower($url);

        // Inline images are a legitimate use; other `data:` URLs mostly serve to
        // smuggle script in.
        if ($imageContext && str_starts_with($lower, 'data:image/')) {
            return $url;
        }

        foreach (self::URL_PREFIXES as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return $url;
            }
        }

        return null;
    }
}
