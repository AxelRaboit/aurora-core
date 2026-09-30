<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Service;

use Aurora\Core\Content\BlockHtmlSanitizer;
use Aurora\Module\Studio\Contract\Exception\UnrenderableBlockException;

use function array_key_exists;
use function array_keys;
use function in_array;
use function is_array;
use function preg_match_all;
use function sprintf;
use function str_replace;

/**
 * Renders a contract's blocks to the HTML that gets signed.
 *
 * Its own renderer rather than Editorial's, for two reasons that both matter.
 * Editorial's emits Tailwind classes tuned for the website, and this markup has
 * to survive a PDF engine and a decade of storage, so it is semantic and
 * self-contained. And importing Editorial would mean the Studio module
 * stops working wherever Editorial is not installed.
 *
 * The rule that makes it different from every other renderer in the codebase:
 * **an unknown block type throws.** Elsewhere a block nobody can render is
 * skipped, which is right for a web page - one missing widget rather than a
 * blank site. Here it would silently drop a clause from a document somebody is
 * about to sign, and no output at all is safer than output that is quietly
 * incomplete.
 */
final readonly class ContractDocumentRenderer
{
    public function __construct(private BlockHtmlSanitizer $sanitizer) {}

    /**
     * Typed loosely on purpose, like Editorial's renderer: the blocks come out
     * of a JSON column, so an entry being an array is something to check
     * rather than to assume.
     *
     * @param array<int, mixed>     $blocks
     * @param array<string, string> $values keyed by token without braces
     */
    public function render(array $blocks, array $values): string
    {
        $html = '';

        foreach ($blocks as $index => $block) {
            if (!is_array($block)) {
                throw UnrenderableBlockException::malformed($index);
            }

            $html .= $this->renderBlock($block, $index, $values);
        }

        return $html;
    }

    /**
     * Substitutes every token the values know about.
     *
     * A token with no value is left standing, which is what the two
     * signature-time ones rely on. Substitution runs on the block's text after
     * sanitising, so a value can never introduce markup: it is inserted as
     * text into already-clean HTML.
     *
     * @param array<string, string> $values
     */
    public function substitute(string $text, array $values): string
    {
        foreach ($values as $token => $value) {
            $text = str_replace(sprintf('{{%s}}', $token), $this->escape($value), $text);
        }

        return $text;
    }

    /**
     * The governing-language clause, as a section of the document itself.
     *
     * Inside the rendered HTML rather than around it, and that is the point:
     * the clause is part of what the hash covers and part of what the signer
     * reads. Printed by the page template instead, it would be chrome - right
     * today, missing from the PDF somebody keeps for ten years, and outside the
     * seal, which is the one place a clause about authority must not be.
     *
     * Its text comes from the catalogues rather than from the trame, so a
     * template author cannot forget it and cannot get it wrong. Escaped all the
     * same: a translation is still text arriving from a file.
     *
     * @param list<string> $paragraphs
     */
    public function governingLanguageSection(string $heading, array $paragraphs): string
    {
        $body = '';

        foreach ($paragraphs as $paragraph) {
            $body .= sprintf('<p>%s</p>', $this->escape($paragraph));
        }

        return sprintf(
            '<section class="contract-language"><h2>%s</h2>%s</section>',
            $this->escape($heading),
            $body,
        );
    }

    /**
     * @param array<string, mixed>  $block
     * @param array<string, string> $values
     */
    private function renderBlock(array $block, int $index, array $values): string
    {
        $type = (string) ($block['type'] ?? '');
        $data = is_array($block['data'] ?? null) ? $block['data'] : [];

        return match ($type) {
            'header' => $this->renderHeader($data, $values),
            'paragraph' => $this->renderParagraph($data, $values),
            'list' => $this->renderList($data, $values),
            'quote' => $this->renderQuote($data, $values),
            'table' => $this->renderTable($data, $values),
            'delimiter' => '<hr>',
            default => throw UnrenderableBlockException::unknownType($type, $index),
        };
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $values
     */
    private function renderHeader(array $data, array $values): string
    {
        // Two through four only. A contract's own title is rendered outside the
        // blocks, so a block claiming h1 would produce a second one - and a
        // level past four says nothing a reader can see.
        $level = (int) ($data['level'] ?? 2);
        $level = max(2, min(4, $level));

        return sprintf(
            '<h%d>%s</h%d>',
            $level,
            $this->text($data['text'] ?? '', $values),
            $level,
        );
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $values
     */
    private function renderParagraph(array $data, array $values): string
    {
        $text = $this->text($data['text'] ?? '', $values);

        // An empty paragraph is spacing somebody typed, and reproducing it as
        // an empty tag keeps the document looking like what was written.
        return sprintf('<p>%s</p>', $text);
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $values
     */
    private function renderList(array $data, array $values): string
    {
        $ordered = 'ordered' === ($data['style'] ?? 'unordered');
        $tag = $ordered ? 'ol' : 'ul';
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];

        $html = '';

        foreach ($items as $item) {
            // Editor.js writes items as strings in older documents and as
            // `{content: …}` in newer ones. Both shapes reach this from the
            // same editor, so both are read rather than one assumed.
            $content = is_array($item) ? ($item['content'] ?? '') : $item;
            $html .= sprintf('<li>%s</li>', $this->text($content, $values));
        }

        return sprintf('<%s>%s</%s>', $tag, $html, $tag);
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $values
     */
    private function renderQuote(array $data, array $values): string
    {
        $caption = $this->text($data['caption'] ?? '', $values);
        $body = sprintf('<p>%s</p>', $this->text($data['text'] ?? '', $values));

        if ('' !== $caption) {
            $body .= sprintf('<footer>%s</footer>', $caption);
        }

        return sprintf('<blockquote>%s</blockquote>', $body);
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $values
     */
    private function renderTable(array $data, array $values): string
    {
        $rows = is_array($data['content'] ?? null) ? $data['content'] : [];
        $withHeadings = true === ($data['withHeadings'] ?? false);
        $html = '';

        foreach ($rows as $rowIndex => $row) {
            if (!is_array($row)) {
                continue;
            }

            $cellTag = $withHeadings && 0 === $rowIndex ? 'th' : 'td';
            $cells = '';

            foreach ($row as $cell) {
                $cells .= sprintf('<%s>%s</%s>', $cellTag, $this->text($cell, $values), $cellTag);
            }

            $html .= sprintf('<tr>%s</tr>', $cells);
        }

        return sprintf('<table>%s</table>', $html);
    }

    /**
     * Clean HTML with the tokens filled in.
     *
     * In this order, always: the editor's inline markup is sanitised first,
     * then values are inserted as escaped text. The reverse would let a
     * customer's own field - a company name somebody typed - carry markup into
     * a signed document.
     *
     * @param array<string, string> $values
     */
    private function text(mixed $value, array $values): string
    {
        return $this->substitute($this->sanitizer->safe($value), $values);
    }

    /**
     * A title, which is plain text typed in a field, escaped before its
     * variables are filled. It used to go into the sealed HTML as typed: a
     * word between chevrons vanished, and markup reached the signing page and
     * the PDF, both of which print the document raw.
     *
     * @param array<string, string> $values
     */
    public function title(string $title, array $values): string
    {
        return $this->substitute($this->escape($title), $values);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * The tokens still standing that nothing will ever fill.
     *
     * Named rather than counted, and returned rather than tested: a freeze
     * refused because "a token is unknown" sends somebody hunting through
     * nineteen articles, while one refused because `{{client.siret}}` is
     * unknown is a typo they fix in ten seconds.
     *
     * @param array<string, string> $values   what was substituted
     * @param list<string>          $deferred what is meant to survive, filled at signature
     *
     * @return list<string>
     */
    public function unknownTokens(string $html, array $values, array $deferred): array
    {
        if (0 === preg_match_all('/\{\{([a-z0-9_.]+)\}\}/i', $html, $matches)) {
            return [];
        }

        $unknown = [];

        foreach ($matches[1] as $token) {
            if (array_key_exists($token, $values)) {
                continue;
            }

            if (in_array($token, $deferred, true)) {
                continue;
            }

            $unknown[$token] = true;
        }

        return array_keys($unknown);
    }
}
