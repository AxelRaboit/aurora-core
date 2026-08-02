<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Service;

use Aurora\Core\Content\BlockHtmlSanitizer;
use Aurora\Core\Content\BlockRendererInterface;
use Aurora\Core\Support\Num;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Renders Editor.js blocks to HTML on the server, so a page arrives as
 * static markup rather than waiting on JavaScript — which is what search
 * engines and readers without it get.
 *
 * The shapes here must match what the editor writes, not what seems
 * reasonable. Where the two drifted apart, the page rendered blank and
 * nothing complained; see renderCallout.
 */
final readonly class BlocksRenderer
{
    /**
     * @param iterable<BlockRendererInterface> $blockRenderers module-contributed renderers
     *                                                         for block types this one does not know
     */
    public function __construct(
        private BlockHtmlSanitizer $sanitizer,
        #[AutowireIterator('aurora.content_block_renderer')]
        private iterable $blockRenderers,
    ) {}

    /**
     * Typed loosely on purpose: blocks come out of a JSON column and from
     * nested `twoColumn` payloads, so an entry being an array is something
     * to check rather than assume.
     *
     * @param array<int, mixed> $blocks
     */
    public function render(array $blocks, string $locale): string
    {
        $output = '';
        foreach ($blocks as $block) {
            if (is_array($block)) {
                $output .= $this->renderBlock($block, $locale);
            }
        }

        return $output;
    }

    /** @param array<string, mixed> $block */
    private function renderBlock(array $block, string $locale): string
    {
        $type = (string) ($block['type'] ?? '');
        $data = is_array($block['data'] ?? null) ? $block['data'] : [];

        return match ($type) {
            'header' => $this->renderHeader($data),
            'paragraph' => $this->renderParagraph($data),
            'list' => $this->renderList($data),
            'quote' => $this->renderQuote($data),
            'code' => $this->renderCode($data),
            'delimiter' => '<hr class="my-8 border-line">',
            'image' => $this->renderImage($data),
            'embed' => $this->renderEmbed($data),
            'table' => $this->renderTable($data),
            'callout' => $this->renderCallout($data),
            'twoColumn' => $this->renderTwoColumn($data, $locale),
            'mediaText' => $this->renderMediaText($data),
            default => $this->renderExtensionBlock($type, $data, $locale),
        };
    }

    /**
     * A block type this renderer does not know is offered to the modules.
     * Returning '' for an unclaimed one is deliberate: a reader should get a
     * page missing one section, not a stack trace.
     *
     * @param array<string, mixed> $data
     */
    private function renderExtensionBlock(string $type, array $data, string $locale): string
    {
        foreach ($this->blockRenderers as $renderer) {
            if ($renderer->getType() === $type) {
                return $renderer->render($data, $locale);
            }
        }

        return '';
    }

    /** @param array<string, mixed> $data */
    private function renderHeader(array $data): string
    {
        $level = Num::clamp((int) ($data['level'] ?? 2), 1, 6);

        return sprintf('<h%d>%s</h%d>', $level, $this->safe($data['text'] ?? ''), $level);
    }

    /** @param array<string, mixed> $data */
    private function renderParagraph(array $data): string
    {
        return sprintf('<p>%s</p>', $this->safe($data['text'] ?? ''));
    }

    /** @param array<string, mixed> $data */
    private function renderList(array $data): string
    {
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        $style = (string) ($data['style'] ?? 'unordered');

        if ('checklist' === $style) {
            return $this->renderChecklist($items);
        }

        $tag = 'ordered' === $style ? 'ol' : 'ul';
        $html = '';
        foreach ($items as $item) {
            // @editorjs/list v1 stored plain strings, v2 stores {content, meta}.
            // Both shapes reach us from posts written at different times.
            $content = is_string($item) ? $item : (is_array($item) ? ($item['content'] ?? null) : null);
            if (null !== $content) {
                $html .= sprintf('<li>%s</li>', $this->safe($content));
            }
        }

        return sprintf('<%s>%s</%s>', $tag, $html, $tag);
    }

    /** @param array<int, mixed> $items */
    private function renderChecklist(array $items): string
    {
        $html = '<ul class="checklist">';
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $html .= sprintf(
                '<li><input type="checkbox" disabled%s> %s</li>',
                ($item['meta']['checked'] ?? false) ? ' checked' : '',
                $this->safe($item['content'] ?? ''),
            );
        }

        return $html.'</ul>';
    }

    /** @param array<string, mixed> $data */
    private function renderQuote(array $data): string
    {
        $caption = $this->safe($data['caption'] ?? '');

        return sprintf(
            '<blockquote><p>%s</p>%s</blockquote>',
            $this->safe($data['text'] ?? ''),
            '' !== $caption ? sprintf('<cite>%s</cite>', $caption) : '',
        );
    }

    /** @param array<string, mixed> $data */
    private function renderCode(array $data): string
    {
        // Escaped whole rather than sanitized: code is meant to be read, not
        // interpreted, and the sanitizer would let its markup through.
        return sprintf(
            '<pre><code>%s</code></pre>',
            htmlspecialchars((string) ($data['code'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        );
    }

    /** @param array<string, mixed> $data */
    private function renderImage(array $data): string
    {
        $file = is_array($data['file'] ?? null) ? $data['file'] : [];
        $url = $this->attr($file['url'] ?? '');
        if ('' === $url) {
            return '';
        }

        $caption = $this->safe($data['caption'] ?? '');

        return sprintf(
            '<figure><img src="%s" alt="%s" loading="lazy">%s</figure>',
            $url,
            $this->attr($data['caption'] ?? ''),
            '' !== $caption ? sprintf('<figcaption>%s</figcaption>', $caption) : '',
        );
    }

    /** @param array<string, mixed> $data */
    private function renderEmbed(array $data): string
    {
        $url = $this->attr($data['embed'] ?? '');

        return '' === $url ? '' : sprintf(
            '<div class="embed"><iframe src="%s" frameborder="0" allowfullscreen loading="lazy"></iframe></div>',
            $url,
        );
    }

    /** @param array<string, mixed> $data */
    private function renderTable(array $data): string
    {
        $rows = is_array($data['content'] ?? null) ? $data['content'] : [];
        $withHeadings = (bool) ($data['withHeadings'] ?? false);

        $html = '<table>';
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $tag = ($withHeadings && 0 === $index) ? 'th' : 'td';
            $cells = '';
            foreach ($row as $cell) {
                $cells .= sprintf('<%s>%s</%s>', $tag, $this->safe($cell), $tag);
            }

            $html .= '<tr>'.$cells.'</tr>';
        }

        return $html.'</table>';
    }

    /**
     * The tool saves `{type, title, message}` and the stylesheet keys its
     * colours on `.callout--info`. An earlier version of this read `text`
     * and emitted `.callout-info`, so a callout written in the backend came
     * out as an uncoloured empty box — and only once published, since the
     * editor's own preview had both right.
     *
     * @param array<string, mixed> $data
     */
    private function renderCallout(array $data): string
    {
        $title = $this->safe($data['title'] ?? '');
        $message = $this->safe($data['message'] ?? '');

        return sprintf(
            '<aside class="callout callout--%s">%s%s</aside>',
            $this->attr($data['type'] ?? 'info'),
            '' !== $title ? sprintf('<strong>%s</strong>', $title) : '',
            '' !== $message ? sprintf('<p>%s</p>', $message) : '',
        );
    }

    /** @param array<string, mixed> $data */
    private function renderTwoColumn(array $data, string $locale): string
    {
        return sprintf(
            '<div class="two-column"><div>%s</div><div>%s</div></div>',
            is_array($data['left'] ?? null) ? $this->render($data['left'], $locale) : '',
            is_array($data['right'] ?? null) ? $this->render($data['right'], $locale) : '',
        );
    }

    /** @param array<string, mixed> $data */
    private function renderMediaText(array $data): string
    {
        $image = is_array($data['image'] ?? null) ? $data['image'] : [];
        $url = $this->attr($image['url'] ?? '');

        return sprintf(
            '<div class="media-text">%s<div>%s</div></div>',
            '' !== $url ? sprintf('<figure><img src="%s" alt="" loading="lazy"></figure>', $url) : '',
            $this->safe($data['text'] ?? ''),
        );
    }

    /**
     * Editor.js lets an author write light inline HTML (b, i, a, code…).
     * Goes through the shared sanitizer so every block renderer, here and
     * in the modules, allows exactly the same set.
     */
    private function safe(mixed $value): string
    {
        return $this->sanitizer->safe($value);
    }

    /** Attribute values take no markup at all. */
    private function attr(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
