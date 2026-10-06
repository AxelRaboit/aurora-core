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
 * Des blocs d'éditeur (Editor.js), remis en Markdown.
 *
 * **Le chemin inverse de l'import Craft, pour une raison précise** : les notes
 * d'un espace client étaient écrites en blocs, comme le corps d'une
 * publication, et le module Notes écrit en Markdown. La migration qui les y
 * fait passer convertit leur corps une fois, ici.
 *
 * Pur : ni base, ni stockage, ni traduction. Ce qui entre est un tableau de
 * blocs tel que l'éditeur l'enregistre, ce qui sort est un texte. C'est ce qui
 * le rend testable bloc par bloc, et appelable depuis une migration qui ne
 * dispose d'aucun service.
 *
 * **Ce qui se perd est nommé.** Le soulignement n'a pas d'équivalent Markdown
 * et devient du texte simple ; un tableau sans ligne d'en-tête prend sa
 * première ligne pour en-tête, faute de quoi le Markdown n'en fait pas un
 * tableau ; un bloc de HTML brut est gardé, mais dans un bloc de code plutôt
 * que rendu. Un bloc d'un type inconnu rend son texte s'il en a un, et rien
 * sinon : jamais de JSON recopié dans une note.
 */
final readonly class EditorBlocksToMarkdown
{
    /** Le décalage d'un niveau de liste : assez pour une puce comme pour un numéro. */
    private const string LIST_INDENT = '    ';

    /** Les types d'encadré que l'aperçu des notes sait dessiner. */
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
     * Les deux écritures d'une liste : les entrées en chaînes de l'ancien
     * outil, et les objets `content` / `meta` / `items` de l'actuel, imbriqués.
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
     * L'ancien outil de cases à cocher, avant que la liste ne les porte.
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
     * Un bloc de code, dont la clôture est plus longue que tout ce qu'il
     * contient : trois accents graves dans le code fermeraient sinon le bloc
     * au milieu.
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

    /** Un encadré, dans l'écriture que l'aperçu des notes reconnaît : `> [!type] Titre`. */
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
     * Le HTML en ligne que les blocs portent, en Markdown.
     *
     * Le code littéral est mis de côté d'abord et remis à la fin, décodé
     * (derrière deux caractères d'usage privé, que `strip_tags` laisse en
     * place quand il efface l'octet nul) :
     * sans cela, deux astérisques dans un extrait deviendraient du gras, et
     * un `&lt;` y resterait écrit tel quel.
     *
     * Les espaces collés à l'intérieur d'une balise sont sortis de la marque :
     * `<b> mot</b>` donnerait `** mot**`, que le Markdown ne lit pas comme du
     * gras.
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

    /** Le texte seul, sans aucune marque : pour une légende d'image ou d'intégration. */
    private function plain(string $html): string
    {
        return mb_trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** Une adresse qu'une espace ou une parenthèse ne coupe pas. */
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
     * Un paragraphe qui commence comme un titre, une liste ou une citation
     * resterait un paragraphe : la marque du début est échappée.
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
