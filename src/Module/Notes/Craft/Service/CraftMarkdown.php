<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Craft\Service;

use function array_map;
use function explode;
use function implode;
use function mb_ltrim;
use function mb_strlen;
use function mb_strtolower;
use function mb_substr;
use function mb_trim;
use function min;
use function preg_match;
use function preg_replace;
use function preg_replace_callback;
use function str_replace;

/**
 * The Markdown Craft renders, turned back into note Markdown.
 *
 * **Almost nothing to convert, and that is the reason for the move.** The
 * notes of a client space were written in editor blocks; each Craft line
 * therefore had to be translated into a structure that was not its own. The
 * Notes module writes in Markdown: what Craft renders goes in almost as is,
 * and only its own extensions are left to undo.
 *
 * Pure: no network, no storage. Images are not downloaded here, their address
 * comes out as it went in, and {@see CraftNoteImporter} replaces it with the
 * address of the file stored in the notes space.
 */
final readonly class CraftMarkdown
{
    /**
     * Craft's Markdown, stripped of its tags, without the document title if
     * it opens the text.
     */
    public function clean(string $markdown, string $title): string
    {
        $markdown = $this->craftTags(str_replace(["\r\n", "\r"], "\n", $markdown));
        $markdown = $this->withoutRepeatedTitle($markdown, $title);

        // The erased tags leave runs of empty lines behind them: two are
        // always enough to separate two blocks.
        return mb_trim(preg_replace("/\n{3,}/", "\n\n", $markdown) ?? $markdown);
    }

    /**
     * The tags Craft adds to the Markdown, translated or unwrapped.
     *
     * The list comes from the specification the connection publishes itself
     * (`GET /openapi.json`, section "Craft Markdown Extensions"), and not from
     * a guess: a nested page, a callout, a highlight and a comment thread each
     * have their tag, and they arrive in the rendered Markdown.
     *
     * **Unwrap rather than throw away.** Left as they are, they would come out
     * as tags in the middle of the note, which is the worst of outputs. The
     * title of a nested page therefore becomes a heading, a callout becomes the
     * notes callout (`> [!note]`), a highlight the short form that the notes
     * preview can read, and the rest gives back its content and disappears.
     *
     * Craft's internal references - `block://`, `date://`, and the
     * `invalid:out_of_scope` that a link outside the connection renders - lose
     * their address and keep their text: they are links that lead nowhere
     * outside Craft, and a dead link in a note is worse than a word.
     */
    private function craftTags(string $markdown): string
    {
        // **The indentation, first, and only inside the `<content>`.**
        // Craft indents the document body there, and two spaces mean one
        // nesting level to it: a flat list arrived stacked under its first
        // entry. Here rather than on the whole document, because the
        // `<page>` and the `<pageTitle>` stay at column zero - an
        // indentation computed on them would always be zero.
        $markdown = preg_replace_callback(
            '#<content>(.*?)</content>#su',
            fn (array $match): string => "\n".$this->dedent($match[1])."\n",
            $markdown,
        ) ?? $markdown;

        // The title of a nested page, as a level three heading: it sits
        // under the note's title, which is the document itself.
        $markdown = preg_replace('#<pageTitle>(.*?)</pageTitle>#su', "\n### $1\n", $markdown) ?? $markdown;

        // A callout wraps blocks, empty lines included: each of its lines
        // goes into the quote that the preview draws as a callout.
        // Without a closing tag, the tag is only erased further down: a
        // malformed document must not swallow everything that follows it.
        $markdown = preg_replace_callback(
            '#<callout>(.*?)</callout>#su',
            fn (array $match): string => "\n".$this->callout($match[1])."\n",
            $markdown,
        ) ?? $markdown;

        // Highlight: brought back to the short form Craft documents as its
        // equivalent, and that the notes preview can already read.
        $markdown = preg_replace('#<highlight[^>]*>(.*?)</highlight>#su', '==$1==', $markdown) ?? $markdown;

        // A comment thread is a conversation internal to Craft. The word
        // stays, the thread does not follow.
        $markdown = preg_replace('#<comment[^>]*>(.*?)</comment>#su', '$1', $markdown) ?? $markdown;

        // The list of a collection's columns is not text: it is a table
        // header without its table.
        $markdown = preg_replace('#<properties>.*?</properties>#su', '', $markdown) ?? $markdown;

        // The rest gives back its content and is erased.
        $markdown = preg_replace(
            '#</?(?:page|card|content|caption|collection|collectionItem|itemsPreview|property|title|callout)(?:\s[^>]*)?>#u',
            '',
            $markdown,
        ) ?? $markdown;

        return preg_replace('#\[([^\]]*)\]\((?:block|date)://[^)]*\)|\[([^\]]*)\]\(invalid:[^)]*\)#u', '$1$2', $markdown)
            ?? $markdown;
    }

    /** A Craft callout, in the notes callout syntax. */
    private function callout(string $inner): string
    {
        $lines = explode("\n", mb_trim($this->dedent($inner)));

        return "> [!note]\n".implode("\n", array_map(
            static fn (string $line): string => '' === mb_trim($line) ? '>' : '> '.$line,
            $lines,
        ));
    }

    /**
     * The document title, written once and not twice.
     *
     * Craft wraps a document in a page whose `<pageTitle>` carries its title,
     * and the conversion turns it into a section heading - which is right for
     * a nested page, and redundant for the document itself: the note already
     * carries it as its title. Seen on the first real import, not on an
     * example.
     *
     * Compared after normalizing whitespace and case, and only in first
     * position: a document that repeats its title further down does it on
     * purpose.
     */
    private function withoutRepeatedTitle(string $markdown, string $title): string
    {
        $text = mb_ltrim($markdown);

        if (1 !== preg_match('/^#{1,6}[ \t]+(.*?)[ \t#]*(?:\n|$)/u', $text, $match)) {
            return $markdown;
        }

        if ($this->normalised($match[1]) !== $this->normalised($title)) {
            return $markdown;
        }

        return mb_substr($text, mb_strlen($match[0]));
    }

    private function normalised(string $text): string
    {
        return mb_strtolower(mb_trim(preg_replace('/\s+/u', ' ', $text) ?? $text));
    }

    /**
     * The common indentation of a block of lines, and nothing more.
     *
     * *Intended* nesting survives, since it is relative, and a code block
     * keeps its formatting, since every line loses the same amount.
     */
    private function dedent(string $markdown): string
    {
        $lines = explode("\n", $markdown);
        $common = null;

        foreach ($lines as $line) {
            if ('' === mb_trim($line)) {
                continue;
            }

            $indent = mb_strlen($line) - mb_strlen(mb_ltrim($line, ' '));
            $common = null === $common ? $indent : min($common, $indent);
        }

        if (null === $common || 0 === $common) {
            return $markdown;
        }

        return implode("\n", array_map(
            static fn (string $line): string => mb_substr($line, $common),
            $lines,
        ));
    }
}
