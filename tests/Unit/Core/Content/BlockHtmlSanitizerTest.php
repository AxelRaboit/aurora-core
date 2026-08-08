<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Core\Content;

use Aurora\Core\Content\BlockHtmlSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The sanitizer is the last thing between what an author typed and what a
 * reader's browser executes, so these are written from the attacker's side as
 * much as the author's.
 *
 * It used to be a `strip_tags` call, which keeps a tag whole or drops it
 * whole: `<a>` was allowed, so `<a href="javascript:alert(1)">` was allowed,
 * and `<b>` was allowed, so `<b onmouseover="…">` was too.
 */
final class BlockHtmlSanitizerTest extends TestCase
{
    private BlockHtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new BlockHtmlSanitizer();
    }

    // ── What must not survive ─────────────────────────────────────────────

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function hostileMarkup(): iterable
    {
        yield 'a javascript href' => ['<a href="javascript:alert(1)">clic</a>', 'javascript:'];
        yield 'a case-shuffled javascript href' => ['<a href="JaVaScRiPt:alert(1)">clic</a>', 'javascript:'];
        yield 'a data href' => ['<a href="data:text/html,<script>">clic</a>', 'data:'];
        yield 'an inline click handler' => ['<a href="/ok" onclick="alert(1)">clic</a>', 'onclick'];
        yield 'a handler on a formatting tag' => ['<b onmouseover="alert(1)">gras</b>', 'onmouseover'];
        yield 'a handler on a span' => ['<span class="cdx-text-color" onload="alert(1)">x</span>', 'onload'];
        yield 'a style outside the tools' => ['<span class="cdx-text-color" style="position: fixed">x</span>', 'position'];
        yield 'an unknown class carrying a style' => ['<span class="evil" style="color:#ff0000">x</span>', 'style'];
        yield 'a colour that is not a hex' => ['<span class="cdx-text-color" style="color: red">x</span>', 'red'];
        yield 'a font size in absolute units' => ['<span class="cdx-font-size" style="font-size: 900px">x</span>', '900px'];
    }

    #[DataProvider('hostileMarkup')]
    public function testHostileMarkupDoesNotSurvive(string $input, string $forbidden): void
    {
        self::assertStringNotContainsString(
            $forbidden,
            $this->sanitizer->safe($input),
            'this reaches a reader\'s browser',
        );
    }

    public function testScriptsLoseTheirContentsAndNotJustTheirTags(): void
    {
        $html = $this->sanitizer->safe('<script>alert(1)</script>bonjour');

        self::assertSame('bonjour', $html, 'the text inside a script is code, not something to read');
    }

    /** Dropping a tag must not drop the words it wrapped. */
    public function testAnUnknownTagKeepsItsText(): void
    {
        self::assertSame('bloc', $this->sanitizer->safe('<div>bloc</div>'));
        self::assertSame(
            '<b>gras</b>',
            $this->sanitizer->safe('<b onmouseover="x">gras</b>'),
            'the attribute goes, the tag stays: the author did ask for bold',
        );
    }

    // ── What must survive ─────────────────────────────────────────────────

    /**
     * The three inline tools all wrap their selection in a span. The old
     * allowlist had no `span`, so text colour, background colour and font
     * size were visible in the editor and stripped on the page.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function inlineTools(): iterable
    {
        yield 'text colour' => [
            '<span class="cdx-text-color" style="color: #ff0000">rouge</span>',
            'color: #ff0000',
        ];
        yield 'background colour' => [
            '<span class="cdx-text-bg" style="background-color: #ffff00">surligné</span>',
            'background-color: #ffff00',
        ];
        yield 'font size' => [
            '<span class="cdx-font-size" style="font-size: 1.25em">grand</span>',
            'font-size: 1.25em',
        ];
        yield 'a short hex' => [
            '<span class="cdx-text-color" style="color:#f00">rouge</span>',
            'color: #f00',
        ];
    }

    #[DataProvider('inlineTools')]
    public function testTheInlineToolsReachThePage(string $input, string $expected): void
    {
        $html = $this->sanitizer->safe($input);

        self::assertStringContainsString($expected, $html);
        self::assertStringContainsString('</span>', $html);
    }

    public function testSafeLinksAndFormattingAreKept(): void
    {
        self::assertSame(
            '<a href="/fr/page" title="Aller">lien</a>',
            $this->sanitizer->safe('<a href="/fr/page" title="Aller">lien</a>'),
        );

        self::assertSame(
            '<b>gras</b> <em>italique</em> <code>code</code>',
            $this->sanitizer->safe('<b>gras</b> <em>italique</em> <code>code</code>'),
        );
    }

    public function testEveryAcceptedSchemeSurvives(): void
    {
        foreach (['/page', '#ancre', 'https://example.org', 'mailto:a@b.c', 'tel:+33100000000'] as $href) {
            self::assertStringContainsString(
                sprintf('href="%s"', $href),
                $this->sanitizer->safe(sprintf('<a href="%s">x</a>', $href)),
            );
        }
    }

    /**
     * DOMDocument reads Latin-1 unless told otherwise, which turns every
     * accent into mojibake — on a French-first application, silently.
     */
    public function testAccentsAndEntitiesComeOutIntact(): void
    {
        self::assertSame(
            'Éléphant à Nîmes &amp; Cie — <b>déjà</b>',
            $this->sanitizer->safe('Éléphant à Nîmes &amp; Cie — <b>déjà</b>'),
        );
    }

    public function testNestingIsPreserved(): void
    {
        self::assertSame(
            '<b>gras et <a href="/x">lié</a></b>',
            $this->sanitizer->safe('<b>gras et <a href="/x">lié</a></b>'),
        );
    }

    public function testNonStringsAndEmptyValuesAreEmpty(): void
    {
        self::assertSame('', $this->sanitizer->safe(null));
        self::assertSame('', $this->sanitizer->safe(42));
        self::assertSame('', $this->sanitizer->safe('   '));
    }

    public function testSanitizingTwiceChangesNothing(): void
    {
        $once = $this->sanitizer->safe(
            '<span class="cdx-text-color" style="color:#FF0000">rouge</span> et <a href="/x">lien</a>',
        );

        self::assertSame($once, $this->sanitizer->safe($once));
    }
}
