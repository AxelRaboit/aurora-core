<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Service;

use Aurora\Core\Content\BlockHtmlSanitizer;
use Aurora\Core\Content\BlockRendererInterface;
use Aurora\Core\Content\RawHtmlSanitizer;
use Aurora\Core\Support\Num;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Renders Editor.js blocks to HTML on the server, so a page arrives as
 * static markup rather than waiting on JavaScript - which is what search
 * engines and readers without it get.
 *
 * The shapes here must match what the editor writes, not what seems
 * reasonable. Where the two drifted apart, the page rendered blank and
 * nothing complained; see renderCallout.
 */
final readonly class BlocksRenderer
{
    /**
     * The icons a callout can carry, as the inside of a 24x24 stroked SVG.
     *
     * The same names, in the same order, as `calloutIcons.js`, which draws
     * the picker in the editor. A closed list rather than a free field: the
     * value ends up in the page, so only markup written here ever does.
     */
    private const array CALLOUT_ICONS = [
        'info' => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
        'check-circle' => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
        'alert-triangle' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        'clock' => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/>',
        'star' => '<path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z"/>',
        'lightbulb' => '<path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/>',
        'message-circle' => '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>',
        'pause-circle' => '<circle cx="12" cy="12" r="10"/><path d="M10 15V9"/><path d="M14 15V9"/>',
    ];

    /** @var list<string> */
    public const array CALLOUT_ICON_NAMES = [
        'info', 'check-circle', 'alert-triangle', 'clock', 'calendar', 'star', 'lightbulb', 'message-circle', 'pause-circle',
    ];

    /**
     * The colours a label can wear. A closed list, mirrored by `LabelBlock.js`:
     * the name becomes a class, so nothing typed reaches the markup.
     *
     * @var list<string>
     */
    public const array LABEL_TONES = ['dark', 'accent', 'rose', 'indigo', 'lime', 'amber', 'sky', 'emerald'];

    /**
     * The networks a social list knows, each as the inside of a 24x24 stroked
     * SVG - the Lucide pictograms the site already uses for its own links,
     * plus TikTok, which Lucide does not draw. Mirrored by `socialNetworks.js`
     * for the editor, which draws the same marks while one types.
     */
    private const array SOCIAL_ICONS = [
        'instagram' => '<rect width="20" height="20" x="2" y="2" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" x2="17.51" y1="6.5" y2="6.5"/>',
        'facebook' => '<path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/>',
        'linkedin' => '<path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4v-7a6 6 0 0 1 6-6z"/><rect width="4" height="12" x="2" y="9"/><circle cx="4" cy="4" r="2"/>',
        'tiktok' => '<path d="M9 12a4 4 0 1 0 4 4V3c.5 2.7 2.6 4.6 5 5"/>',
        'youtube' => '<path d="M2.5 17a24.12 24.12 0 0 1 0-10 2 2 0 0 1 1.4-1.4 49.56 49.56 0 0 1 16.2 0A2 2 0 0 1 21.5 7a24.12 24.12 0 0 1 0 10 2 2 0 0 1-1.4 1.4 49.55 49.55 0 0 1-16.2 0A2 2 0 0 1 2.5 17"/><path d="m10 15 5-3-5-3z"/>',
        'x' => '<path d="M4 4l16 16"/><path d="M20 4 4 20"/>',
        'website' => '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>',
    ];

    /** @var list<string> */
    public const array SOCIAL_NETWORK_NAMES = ['instagram', 'facebook', 'linkedin', 'tiktok', 'youtube', 'x', 'website'];

    /**
     * @param iterable<BlockRendererInterface> $blockRenderers module-contributed renderers
     *                                                         for block types this one does not know
     */
    public function __construct(
        private BlockHtmlSanitizer $sanitizer,
        private RawHtmlSanitizer $rawSanitizer,
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
            'raw' => $this->renderRaw($data),
            'delimiter' => '<hr class="my-8 border-line">',
            'image' => $this->renderImage($data),
            'embed' => $this->renderEmbed($data),
            'table' => $this->renderTable($data),
            'callout' => $this->renderCallout($data),
            'twoColumn' => $this->renderTwoColumn($data, $locale),
            'mediaText' => $this->renderMediaText($data),
            'label' => $this->renderLabel($data),
            'socials' => $this->renderSocials($data),
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
    /**
     * Le bloc « code source » : du HTML ecrit a la main, rendu tel quel.
     *
     * Il passe par RawHtmlSanitizer et non par le filtre du texte courant, qui
     * le viderait de tout ce qui justifie son existence - tableaux, figures,
     * lecteurs integres. Ce second filtre est nettement plus large, mais ferme
     * aux memes choses : scripts, gestionnaires d'evenements, URL `javascript:`,
     * formulaires, et les cadres vers un hote non liste.
     *
     * A ne pas confondre avec le bloc `code`, juste au-dessus, qui echappe tout
     * pour montrer du code plutot que l'executer.
     */
    private function renderRaw(array $data): string
    {
        return $this->rawSanitizer->safe($data['html'] ?? '');
    }

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

        // The heading row in a `<thead>`, the rest in a `<tbody>`: the
        // typography plugin styles `thead th` and `tbody td` and nothing in
        // between, so a `<th>` left among the body rows came out without the
        // cells' padding and sat a few pixels left of its own column.
        $head = '';
        $body = '';
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $tag = ($withHeadings && 0 === $index) ? 'th' : 'td';
            $cells = '';
            foreach ($row as $cell) {
                $cells .= sprintf('<%s>%s</%s>', $tag, $this->safe($cell), $tag);
            }

            if ('th' === $tag) {
                $head .= '<tr>'.$cells.'</tr>';
            } else {
                $body .= '<tr>'.$cells.'</tr>';
            }
        }

        return '<div class="content-table"><table>'.('' !== $head ? '<thead>'.$head.'</thead>' : '').'<tbody>'.$body.'</tbody></table></div>';
    }

    /**
     * The tool saves `{type, title, message}` and the stylesheet keys its
     * colours on `.callout--info`. An earlier version of this read `text`
     * and emitted `.callout-info`, so a callout written in the suite came
     * out as an uncoloured empty box - and only once published, since the
     * editor's own preview had both right.
     *
     * @param array<string, mixed> $data
     */
    private function renderCallout(array $data): string
    {
        $title = $this->safe($data['title'] ?? '');
        $message = $this->safe($data['message'] ?? '');
        $type = $this->attr($data['type'] ?? 'info');
        $words = ('' !== $title ? sprintf('<strong>%s</strong>', $title) : '')
            .('' !== $message ? sprintf('<p>%s</p>', $message) : '');

        // The icon is a name from a closed list, looked up here: whatever
        // else arrives in the field is dropped, and the callout keeps the
        // shape it had before icons existed.
        $icon = $data['icon'] ?? null;
        if (!is_string($icon) || !isset(self::CALLOUT_ICONS[$icon])) {
            return sprintf('<aside class="callout callout--%s">%s</aside>', $type, $words);
        }

        return sprintf(
            '<aside class="callout callout--%s callout--icon"><span class="callout__icon" aria-hidden="true">'
            .'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">%s</svg>'
            .'</span><div class="callout__body">%s</div></aside>',
            $type,
            self::CALLOUT_ICONS[$icon],
            $words,
        );
    }

    /**
     * A short word set on a pill, the way a report marks a section: « Réseaux
     * sociaux » on black, a competitor's name on its colour. Tilted when the
     * author asked, a few degrees, the hand-placed look of a sticker.
     *
     * @param array<string, mixed> $data
     */
    private function renderLabel(array $data): string
    {
        $text = $this->safe($data['text'] ?? '');
        if ('' === mb_trim(strip_tags($text))) {
            return '';
        }

        $tone = in_array($data['tone'] ?? null, self::LABEL_TONES, true) ? $data['tone'] : self::LABEL_TONES[0];
        $tilt = true === ($data['tilt'] ?? false) ? ' label-pill--tilt' : '';

        return sprintf('<p class="label-pill-row"><span class="label-pill label-pill--%s%s">%s</span></p>', $tone, $tilt, $text);
    }

    /**
     * The accounts a brand is found on, each with its network's mark: the
     * opening page of an audit, which says where we looked before saying
     * what we saw. A row whose network is not in the list is dropped, and a
     * link is drawn only for an http(s) address.
     *
     * @param array<string, mixed> $data
     */
    private function renderSocials(array $data): string
    {
        $rows = '';

        foreach (is_array($data['items'] ?? null) ? $data['items'] : [] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $network = $item['network'] ?? null;
            $handle = mb_trim(strip_tags((string) ($item['handle'] ?? '')));
            if (!is_string($network)) {
                continue;
            }

            if (!isset(self::SOCIAL_ICONS[$network])) {
                continue;
            }

            if ('' === $handle) {
                continue;
            }

            $url = mb_trim((string) ($item['url'] ?? ''));
            $name = 1 === preg_match('#^https?://#i', $url)
                ? sprintf('<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', $this->attr($url), $this->attr($handle))
                : $this->attr($handle);

            $rows .= sprintf(
                '<li class="social-list__item social-list__item--%s"><span class="social-list__icon" aria-hidden="true">'
                .'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">%s</svg>'
                .'</span><span class="social-list__handle">%s</span></li>',
                $network,
                self::SOCIAL_ICONS[$network],
                $name,
            );
        }

        return '' === $rows ? '' : sprintf('<ul class="social-list">%s</ul>', $rows);
    }

    /** @param array<string, mixed> $data */
    /**
     * The editor saves each column as a string of inline HTML - the
     * `innerHTML` of a contenteditable. This required an *array* of nested
     * blocks and emitted nothing otherwise, so every two-column block ever
     * written published as `<div class="two-column"><div></div><div></div></div>`:
     * the markup was there, the text was not, and nothing failed loudly.
     *
     * Both shapes are read now. The array branch stays because a module block
     * renderer may legitimately hand nested blocks, and because dropping it
     * would break anything that already does.
     *
     * @param array<string, mixed> $data
     */
    private function renderTwoColumn(array $data, string $locale): string
    {
        return sprintf(
            '<div class="two-column"><div>%s</div><div>%s</div></div>',
            $this->column($data['left'] ?? null, $locale),
            $this->column($data['right'] ?? null, $locale),
        );
    }

    private function column(mixed $value, string $locale): string
    {
        if (is_array($value)) {
            return $this->render($value, $locale);
        }

        return $this->safe($value);
    }

    /**
     * Same mismatch as the two-column block: this looked for the url under an
     * `image` key the editor has never written - it saves `url` at the top
     * level, beside `caption` and `flip`. Every media-text block therefore
     * published its text with no picture at all.
     *
     * `flip` and `caption` are rendered too. They were saved, and silently
     * dropped: an author choosing "image on the right" got it on the left.
     *
     * @param array<string, mixed> $data
     */
    private function renderMediaText(array $data): string
    {
        // The nested shape is still read: it is what a module renderer would
        // reasonably hand over, and it costs one coalesce.
        $image = is_array($data['image'] ?? null) ? $data['image'] : [];
        $url = $this->attr($data['url'] ?? $image['url'] ?? '');
        $caption = $this->safe($data['caption'] ?? '');

        $figure = '' !== $url
            ? sprintf(
                '<figure><img src="%s" alt="" loading="lazy">%s</figure>',
                $url,
                '' !== $caption ? sprintf('<figcaption>%s</figcaption>', $caption) : '',
            )
            : '';

        return sprintf(
            '<div class="media-text%s">%s<div>%s</div></div>',
            true === ($data['flip'] ?? false) ? ' media-text--flip' : '',
            $figure,
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
