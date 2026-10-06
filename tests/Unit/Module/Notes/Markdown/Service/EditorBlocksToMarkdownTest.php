<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Notes\Markdown\Service;

use Aurora\Module\Notes\Markdown\Service\EditorBlocksToMarkdown;
use PHPUnit\Framework\TestCase;

/**
 * The body of a client space note, in blocks, turned into the Markdown of the
 * Notes module.
 *
 * The conversion is only used once, in the migration that moves these notes,
 * and that is precisely why it is tested block by block: what it misses only
 * shows afterwards, in a note that can no longer be converted again.
 */
final class EditorBlocksToMarkdownTest extends TestCase
{
    private EditorBlocksToMarkdown $convert;

    protected function setUp(): void
    {
        $this->convert = new EditorBlocksToMarkdown();
    }

    public function testParagraphsAreSeparatedByABlankLine(): void
    {
        self::assertSame(
            "Premier.\n\nSecond.",
            $this->convert->convert([
                ['type' => 'paragraph', 'data' => ['text' => 'Premier.']],
                ['type' => 'paragraph', 'data' => ['text' => 'Second.']],
            ]),
        );
    }

    public function testInlineMarksBecomeMarkdown(): void
    {
        self::assertSame(
            'Le client veut **éviter le vert**, *vite*, ~~hier~~, ==surtout==, `a < b` et [le site](https://example.test/a).',
            $this->convert->convert([['type' => 'paragraph', 'data' => [
                'text' => 'Le client veut <b>éviter le vert</b>, <i>vite</i>, <s>hier</s>, <mark class="cdx-marker">surtout</mark>, <code class="inline-code">a &lt; b</code> et <a href="https://example.test/a">le site</a>.',
            ]]]),
        );
    }

    /** `<b> mot</b>` would give `** mot**`, which Markdown does not read as bold. */
    public function testSpacesInsideAMarkGoOutsideIt(): void
    {
        self::assertSame(
            'Un **mot** ici.',
            $this->convert->convert([['type' => 'paragraph', 'data' => ['text' => 'Un<b> mot </b>ici.']]]),
        );
    }

    public function testEntitiesAndNonBreakingSpacesAreDecoded(): void
    {
        self::assertSame(
            "Qu'on « dise » & fasse",
            $this->convert->convert([['type' => 'paragraph', 'data' => ['text' => 'Qu&#039;on «&nbsp;dise&nbsp;» &amp; fasse']]]),
        );
    }

    public function testALineBreakIsKeptAsAHardBreak(): void
    {
        self::assertSame(
            "Ligne une  \nLigne deux",
            $this->convert->convert([['type' => 'paragraph', 'data' => ['text' => 'Ligne une<br>Ligne deux']]]),
        );
    }

    /** A paragraph that starts like a list stays a paragraph. */
    public function testAParagraphThatLooksLikeAListStaysAParagraph(): void
    {
        self::assertSame(
            '\\- pas une liste',
            $this->convert->convert([['type' => 'paragraph', 'data' => ['text' => '- pas une liste']]]),
        );
        self::assertSame(
            '\\1. pas une liste non plus',
            $this->convert->convert([['type' => 'paragraph', 'data' => ['text' => '1. pas une liste non plus']]]),
        );
    }

    public function testHeadersKeepTheirLevel(): void
    {
        self::assertSame(
            "## Le contexte\n\n#### Détail",
            $this->convert->convert([
                ['type' => 'header', 'data' => ['text' => 'Le contexte', 'level' => 2]],
                ['type' => 'header', 'data' => ['text' => 'Détail', 'level' => 4]],
            ]),
        );
    }

    public function testNestedListsAreIndented(): void
    {
        self::assertSame(
            "1. Relire\n    1. Le titre\n2. Envoyer",
            $this->convert->convert([['type' => 'list', 'data' => [
                'style' => 'ordered',
                'meta' => [],
                'items' => [
                    ['content' => 'Relire', 'meta' => [], 'items' => [
                        ['content' => 'Le titre', 'meta' => [], 'items' => []],
                    ]],
                    ['content' => 'Envoyer', 'meta' => [], 'items' => []],
                ],
            ]]]),
        );
    }

    /** The old form, where a list item was a plain string. */
    public function testALegacyListOfStringsIsRead(): void
    {
        self::assertSame(
            "- Un\n- **Deux**",
            $this->convert->convert([['type' => 'list', 'data' => ['style' => 'unordered', 'items' => ['Un', '<b>Deux</b>']]]]),
        );
    }

    public function testChecklistsKeepTheirTicks(): void
    {
        self::assertSame(
            "- [x] Fait\n- [ ] À faire",
            $this->convert->convert([['type' => 'list', 'data' => [
                'style' => 'checklist',
                'items' => [
                    ['content' => 'Fait', 'meta' => ['checked' => true], 'items' => []],
                    ['content' => 'À faire', 'meta' => ['checked' => false], 'items' => []],
                ],
            ]]]),
        );
        self::assertSame(
            "- [x] Ancien\n- [ ] Outil",
            $this->convert->convert([['type' => 'checklist', 'data' => ['items' => [
                ['text' => 'Ancien', 'checked' => true],
                ['text' => 'Outil', 'checked' => false],
            ]]]]),
        );
    }

    public function testAQuoteKeepsItsCaption(): void
    {
        self::assertSame(
            "> Ce qu'il a dit.\n>\n> *Le client*",
            $this->convert->convert([['type' => 'quote', 'data' => ['text' => "Ce qu'il a dit.", 'caption' => 'Le client']]]),
        );
    }

    /** Three backticks in the code do not close the block halfway. */
    public function testACodeFenceIsLongerThanWhatItHolds(): void
    {
        self::assertSame(
            "````\nun ``` dedans\n````",
            $this->convert->convert([['type' => 'code', 'data' => ['code' => 'un ``` dedans']]]),
        );
    }

    public function testAnImageKeepsItsAddressAndCaption(): void
    {
        self::assertSame(
            '![Le studio](/suite/ged/files/12/photo.png)',
            $this->convert->convert([['type' => 'image', 'data' => [
                'file' => ['url' => '/suite/ged/files/12/photo.png', 'documentId' => 12],
                'caption' => '<i>Le studio</i>',
            ]]]),
        );
    }

    public function testATableTakesItsFirstRowAsHeader(): void
    {
        self::assertSame(
            "| Jour | Réseau |\n| --- | --- |\n| Mardi | Insta\\|gram |",
            $this->convert->convert([['type' => 'table', 'data' => [
                'withHeadings' => true,
                'content' => [['Jour', 'Réseau'], ['Mardi', 'Insta|gram']],
            ]]]),
        );
    }

    public function testACalloutBecomesTheNotesCallout(): void
    {
        self::assertSame(
            "> [!warning] Attention\n> Pas le mardi.",
            $this->convert->convert([['type' => 'callout', 'data' => ['type' => 'warning', 'title' => 'Attention', 'message' => 'Pas le mardi.']]]),
        );
        self::assertSame(
            "> [!note]\n> Inconnu devient note.",
            $this->convert->convert([['type' => 'callout', 'data' => ['type' => 'surprise', 'title' => '', 'message' => 'Inconnu devient note.']]]),
        );
    }

    public function testADelimiterAndRawHtml(): void
    {
        self::assertSame(
            "---\n\n```html\n<div>brut</div>\n```",
            $this->convert->convert([
                ['type' => 'delimiter', 'data' => []],
                ['type' => 'raw', 'data' => ['html' => '<div>brut</div>']],
            ]),
        );
    }

    /** An unknown block renders its text if it has any; never JSON in a note. */
    public function testAnUnknownBlockGivesItsTextOrNothing(): void
    {
        self::assertSame(
            'Gardé.',
            $this->convert->convert([
                ['type' => 'mystery', 'data' => ['text' => 'Gardé.']],
                ['type' => 'mystery', 'data' => ['items' => [1, 2]]],
                'pas un bloc',
                ['type' => 'paragraph', 'data' => ['text' => '   ']],
            ]),
        );
    }

    public function testNoBlocksGiveAnEmptyText(): void
    {
        self::assertSame('', $this->convert->convert([]));
    }
}
