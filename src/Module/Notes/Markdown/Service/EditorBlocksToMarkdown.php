<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Service;

use function array_map;
use function array_values;
use function count;
use function explode;
use function html_entity_decode;
use function implode;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function max;
use function mb_strlen;
use function mb_trim;
use function min;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function preg_replace_callback;
use function str_contains;
use function str_repeat;
use function str_replace;
use function strip_tags;

/**
 * Editor blocks (Editor.js), turned back into Markdown.
 *
 * **The reverse path of the Craft import, for a precise reason**: the notes
 * of a client space were written in blocks, like the body of a post, and the
 * Notes module writes in Markdown. The migration that moves them there
 * converts their body once, here.
 *
 * Pure: no database, no storage, no translation. What goes in is an array of
 * blocks as the editor saves it, what comes out is a text. That is what makes
 * it testable block by block, and callable from a migration that has no
 * service at hand.
 *
 * **What is lost is named.** Underline has no Markdown equivalent and becomes
 * plain text; a table without a header row takes its first row as header,
 * otherwise Markdown does not make it a table; a raw HTML block is kept, but
 * in a code block rather than rendered. A block of an unknown type renders
 * its text if it has one, and nothing otherwise: never JSON copied into a
 * note.
 */
final readonly class EditorBlocksToMarkdown
{
    /** The offset of one list level: enough for a bullet as for a number. */
    private const string LIST_INDENT = '    ';

    /** The callout types the notes preview can draw. */
    private const array CALLOUT_TYPES = ['note', 'tip', 'info', 'warning', 'caution', 'danger', 'success', 'question', 'example', 'quote', 'todo', 'failure', 'bug', 'abstract', 'summary', 'hint', 'faq'];

    /**
     * @param list<mixed> $blocks
     */
    public function convert(array $blocks): string
    {
        $parts = [];

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $markdown = $this->block(is_string($block['type'] ?? null) ? $block['type'] : '', is_array($block['data'] ?? null) ? $block['data'] : []);

            if ('' !== mb_trim($markdown)) {
                $parts[] = $markdown;
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * @param array<mixed> $data
     */
    private function block(string $type, array $data): string
    {
        return match ($type) {
            'paragraph' => $this->escapeLineStarts($this->inline($this->text($data, 'text'))),
            'header' => $this->header($data),
            'list' => $this->list($data),
            'checklist' => $this->checklist($data),
            'quote' => $this->quote($data),
            'code' => $this->code($this->text($data, 'code'), ''),
            'raw' => $this->code($this->text($data, 'html'), 'html'),
            'delimiter' => '---',
            'image' => $this->image($data),
            'table' => $this->table($data),
            'callout' => $this->callout($this->text($data, 'type'), $this->text($data, 'title'), $this->text($data, 'message')),
            'warning' => $this->callout('warning', $this->text($data, 'title'), $this->text($data, 'message')),
            'embed' => $this->embed($data),
            'mediaText' => $this->mediaText($data),
            default => $this->escapeLineStarts($this->inline($this->text($data, 'text'))),
        };
    }

    /**
     * @param array<mixed> $data
     */
    private function header(array $data): string
    {
        $level = is_int($data['level'] ?? null) ? $data['level'] : 2;
        $text = $this->inline($this->text($data, 'text'), multiline: false);

        return '' === $text ? '' : str_repeat('#', max(1, min(6, $level))).' '.$text;
    }

    /**
     * The two ways a list is written: the string entries of the old tool, and
     * the nested `content` / `meta` / `items` objects of the current one.
     *
     * @param array<mixed> $data
     */
    private function list(array $data): string
    {
        $style = $this->text($data, 'style');
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];

        return implode("\n", $this->listLines($items, $style, 0));
    }

    /**
     * @param array<mixed> $items
     *
     * @return list<string>
     */
    private function listLines(array $items, string $style, int $depth): array
    {
        $lines = [];
        $number = 0;

        foreach ($items as $item) {
            $content = is_string($item) ? $item : (is_array($item) ? $this->text($item, 'content') : '');
            $children = is_array($item) && is_array($item['items'] ?? null) ? $item['items'] : [];
            $checked = is_array($item) && is_array($item['meta'] ?? null) ? ($item['meta']['checked'] ?? null) : null;

            ++$number;
            $marker = match ($style) {
                'ordered' => $number.'.',
                'checklist' => true === $checked ? '- [x]' : '- [ ]',
                default => '-',
            };

            $lines[] = str_repeat(self::LIST_INDENT, $depth).$marker.' '.$this->inline($content, multiline: false);

            foreach ($this->listLines($children, $style, $depth + 1) as $child) {
                $lines[] = $child;
            }
        }

        return $lines;
    }

    /**
     * The old checkbox tool, before the list carried them.
     *
     * @param array<mixed> $data
     */
    private function checklist(array $data): string
    {
        $lines = [];

        foreach (is_array($data['items'] ?? null) ? $data['items'] : [] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $lines[] = (true === ($item['checked'] ?? false) ? '- [x] ' : '- [ ] ').$this->inline($this->text($item, 'text'), multiline: false);
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<mixed> $data
     */
    private function quote(array $data): string
    {
        $text = $this->inline($this->text($data, 'text'));

        if ('' === $text) {
            return '';
        }

        $lines = $this->quoted($text);
        $caption = $this->inline($this->text($data, 'caption'), multiline: false);

        if ('' !== $caption) {
            $lines .= "\n>\n> *".$caption.'*';
        }

        return $lines;
    }

    /**
     * A code block, whose fence is longer than anything it contains: three
     * backticks in the code would otherwise close the block in the middle.
     */
    private function code(string $code, string $language): string
    {
        if ('' === mb_trim($code)) {
            return '';
        }

        $longest = 0;
        if (preg_match_all('/`+/', $code, $runs) > 0) {
            foreach ($runs[0] as $run) {
                $longest = max($longest, mb_strlen($run));
            }
        }

        $fence = str_repeat('`', max(3, $longest + 1));

        return $fence.$language."\n".$code."\n".$fence;
    }

    /**
     * @param array<mixed> $data
     */
    private function image(array $data): string
    {
        $file = is_array($data['file'] ?? null) ? $data['file'] : [];
        $url = $this->text($file, 'url');

        if ('' === $url) {
            $url = $this->text($data, 'url');
        }

        if ('' === $url) {
            return '';
        }

        $caption = str_replace(['[', ']'], ['\\[', '\\]'], $this->plain($this->text($data, 'caption')));

        return '!['.$caption.']('.$this->destination($url).')';
    }

    /**
     * @param array<mixed> $data
     */
    private function table(array $data): string
    {
        $rows = [];

        foreach (is_array($data['content'] ?? null) ? $data['content'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rows[] = array_map(
                fn (mixed $cell): string => str_replace('|', '\\|', $this->inline(is_string($cell) ? $cell : '', multiline: false)),
                array_values($row),
            );
        }

        if ([] === $rows) {
            return '';
        }

        $width = max(array_map(count(...), $rows));

        if (0 === $width) {
            return '';
        }

        $lines = [];

        foreach ($rows as $index => $cells) {
            while (count($cells) < $width) {
                $cells[] = '';
            }

            $lines[] = '| '.implode(' | ', $cells).' |';

            if (0 === $index) {
                $lines[] = '|'.str_repeat(' --- |', $width);
            }
        }

        return implode("\n", $lines);
    }

    /** A callout, in the syntax the notes preview recognizes: `> [!type] Titre`. */
    private function callout(string $type, string $title, string $message): string
    {
        $type = in_array($type, self::CALLOUT_TYPES, true) ? $type : 'note';
        $title = $this->inline($title, multiline: false);
        $message = $this->inline($message);

        if ('' === $title && '' === $message) {
            return '';
        }

        $head = '> [!'.$type.']'.('' === $title ? '' : ' '.$title);

        return '' === $message ? $head : $head."\n".$this->quoted($message);
    }

    /**
     * @param array<mixed> $data
     */
    private function embed(array $data): string
    {
        $source = $this->text($data, 'source');

        if ('' === $source) {
            return '';
        }

        $caption = $this->plain($this->text($data, 'caption'));

        return '['.('' === $caption ? $source : $caption).']('.$this->destination($source).')';
    }

    /**
     * @param array<mixed> $data
     */
    private function mediaText(array $data): string
    {
        $image = $this->image(['file' => is_array($data['image'] ?? null) ? $data['image'] : []]);
        $text = $this->escapeLineStarts($this->inline($this->text($data, 'text')));

        return mb_trim($image."\n\n".$text);
    }

    /**
     * The inline HTML the blocks carry, in Markdown.
     *
     * Literal code is set aside first and put back at the end, decoded
     * (behind two private use characters, which `strip_tags` leaves in
     * place when it erases the null byte):
     * without that, two asterisks in a snippet would become bold, and a
     * `&lt;` would stay written as is.
     *
     * Spaces stuck inside a tag are moved out of the mark: `<b> mot</b>`
     * would give `** mot**`, which Markdown does not read as bold.
     */
    private function inline(string $html, bool $multiline = true): string
    {
        $codes = [];
        $text = preg_replace_callback(
            '#<code\b[^>]*>(.*?)</code>#su',
            static function (array $match) use (&$codes): string {
                $codes[] = html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');

                return "\u{E000}".(count($codes) - 1)."\u{E001}";
            },
            $html,
        ) ?? $html;

        foreach ([
            '(?:b|strong)' => '**',
            '(?:i|em)' => '*',
            '(?:s|del|strike)' => '~~',
            'mark' => '==',
        ] as $tag => $marker) {
            $text = preg_replace_callback(
                '#<'.$tag.'\b[^>]*>(.*?)</'.$tag.'>#su',
                static function (array $match) use ($marker): string {
                    if ('' === mb_trim($match[1])) {
                        return $match[1];
                    }

                    preg_match('/^(\s*)(.*?)(\s*)$/su', $match[1], $parts);

                    return $parts[1].$marker.$parts[2].$marker.$parts[3];
                },
                $text,
            ) ?? $text;
        }

        $text = preg_replace_callback(
            '#<a\b[^>]*\bhref="([^"]*)"[^>]*>(.*?)</a>#su',
            fn (array $match): string => '['.$match[2].']('.$this->destination(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')).')',
            $text,
        ) ?? $text;

        $text = preg_replace('#<br\s*/?>#iu', $multiline ? "  \n" : ' ', $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{a0}", ' ', $text);

        if (!$multiline) {
            $text = preg_replace('/\s*\n\s*/u', ' ', $text) ?? $text;
        }

        $text = preg_replace_callback(
            '/\x{E000}(\d+)\x{E001}/u',
            static function (array $match) use ($codes): string {
                $code = $codes[(int) $match[1]];
                $fence = str_contains($code, '`') ? '``' : '`';

                return $fence.$code.$fence;
            },
            $text,
        ) ?? $text;

        return mb_trim($text);
    }

    /** The text alone, without any mark: for an image or embed caption. */
    private function plain(string $html): string
    {
        return mb_trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** An address that a space or a parenthesis does not cut. */
    private function destination(string $url): string
    {
        return 1 === preg_match('/[\s()]/u', $url) ? '<'.$url.'>' : $url;
    }

    private function quoted(string $text): string
    {
        return implode("\n", array_map(
            static fn (string $line): string => '' === $line ? '>' : '> '.$line,
            explode("\n", $text),
        ));
    }

    /**
     * A paragraph that starts like a heading, a list or a quote would not
     * stay a paragraph: the leading mark is escaped.
     */
    private function escapeLineStarts(string $text): string
    {
        return preg_replace('/^(\s*)(#{1,6}\s|>|[-+*]\s|\d+[.)]\s)/mu', '$1\\\\$2', $text) ?? $text;
    }

    /**
     * @param array<mixed> $data
     */
    private function text(array $data, string $key): string
    {
        return is_string($data[$key] ?? null) ? $data[$key] : '';
    }
}
