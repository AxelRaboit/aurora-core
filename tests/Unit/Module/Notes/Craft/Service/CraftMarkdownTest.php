<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Notes\Craft\Service;

use Aurora\Module\Notes\Craft\Service\CraftMarkdown;
use PHPUnit\Framework\TestCase;

/**
 * What Craft writes, and what a note keeps of it.
 *
 * Craft's Markdown goes into a note almost as is: what is checked here is
 * the "almost" - its own tags, which would come out as raw text in the
 * middle of the content, and its title, which would open the note a second
 * time.
 */
final class CraftMarkdownTest extends TestCase
{
    private CraftMarkdown $markdown;

    protected function setUp(): void
    {
        $this->markdown = new CraftMarkdown();
    }

    /** Ordinary Markdown passes through unchanged. */
    public function testPlainMarkdownPassesThrough(): void
    {
        $source = "## Le contexte\n\nTrois **choses** à faire.\n\n- Relire\n- [x] Envoyer\n\n| a | b |\n| --- | --- |\n| 1 | 2 |";

        self::assertSame($source, $this->markdown->clean($source, 'Un autre titre'));
    }

    /**
     * Craft wraps a document in a page whose title is the document's own. The
     * note already carries it: writing it a second time at the top of the
     * text is a duplicate, seen on the first real import.
     */
    public function testTheDocumentTitleIsNotWrittenTwice(): void
    {
        self::assertSame(
            'Random note',
            $this->markdown->clean("<page><pageTitle>This is a test</pageTitle><content>\n\nRandom note\n\n</content></page>", 'This is a test'),
        );
        self::assertSame('Du texte.', $this->markdown->clean("#  Brief   Septembre\n\nDu texte.", 'brief septembre'));
    }

    /** A section heading that is not the document's title stays. */
    public function testAHeadingThatIsNotTheTitleSurvives(): void
    {
        self::assertSame("## Le contexte\n\nDu texte.", $this->markdown->clean("## Le contexte\n\nDu texte.", 'Brief septembre'));
    }

    /** Only at the top: a document that repeats its title further down does so on purpose. */
    public function testTheTitleFurtherDownStays(): void
    {
        self::assertSame("Intro.\n\n# Brief", $this->markdown->clean("Intro.\n\n# Brief", 'Brief'));
    }

    public function testANestedPageBecomesAHeadingAndItsContent(): void
    {
        self::assertSame(
            "### Le brief\n\nLe texte.",
            $this->markdown->clean("<page><pageTitle>Le brief</pageTitle><content>\n\nLe texte.\n\n</content></page>", 'Document'),
        );
    }

    /** The short form, which the notes preview knows how to highlight. */
    public function testAHighlightBecomesTheShortForm(): void
    {
        self::assertSame('une ==date== à tenir', $this->markdown->clean('une <highlight color="yellow">date</highlight> à tenir', 'T'));
    }

    /** A comment thread is a conversation internal to Craft. */
    public function testACommentThreadLeavesItsWordBehind(): void
    {
        self::assertSame('la phrase commentée', $this->markdown->clean('la <comment id="c-1">phrase</comment> commentée', 'T'));
    }

    /** These references lead nowhere outside Craft: a dead link is worse than a word. */
    public function testCraftInternalLinksKeepTheirWordAndLoseTheirAddress(): void
    {
        self::assertSame(
            'voir le plan, hier et ailleurs, mais [le site](https://example.test) reste',
            $this->markdown->clean('voir [le plan](block://abc), [hier](date://2026-09-18) et [ailleurs](invalid:out_of_scope), mais [le site](https://example.test) reste', 'T'),
        );
    }

    /** A callout wraps blocks: it becomes the notes callout, blank lines included. */
    public function testACalloutBecomesTheNotesCallout(): void
    {
        self::assertSame(
            "> [!note]\n> Attention à la date.\n>\n> Elle bouge.\n\nLa suite.",
            $this->markdown->clean("<callout>\nAttention à la date.\n\nElle bouge.\n</callout>\n\nLa suite.", 'T'),
        );
    }

    /**
     * Without a closing tag, the callout does not swallow the end of the
     * document: the tag goes away and the text stays.
     */
    public function testAnUnclosedCalloutDoesNotSwallowTheRest(): void
    {
        self::assertSame("Un mot\n\nUn paragraphe.", $this->markdown->clean("<callout>Un mot\n\nUn paragraphe.", 'T'));
    }

    public function testCollectionTagsRenderTheirContentWithoutTheirBoxes(): void
    {
        $text = $this->markdown->clean(
            '<collection><title>Clients</title><properties>Nom,Statut</properties>'
            .'<content><collectionItem><title>Camille</title></collectionItem></content></collection>',
            'T',
        );

        self::assertStringNotContainsString('collection', $text);
        self::assertStringContainsString('Clients', $text);
        self::assertStringContainsString('Camille', $text);
        self::assertStringNotContainsString('Statut', $text);
    }

    /**
     * Craft indents a document's body inside its `<content>`, and for Craft
     * two spaces are one nesting level: without removing the indent, a flat
     * list arrived stacked under its first item.
     */
    public function testTheIndentationOfContentIsNotReadAsNesting(): void
    {
        self::assertSame(
            "### T\n\n- un\n- deux\n- trois",
            $this->markdown->clean("<page><pageTitle>T</pageTitle><content>\n    - un\n    - deux\n    - trois\n</content></page>", 'Document'),
        );
    }

    /** Only the common indent: the intended nesting survives. */
    public function testRealNestingSurvivesTheDedent(): void
    {
        self::assertSame("- parent\n  - enfant", $this->markdown->clean("<content>\n    - parent\n      - enfant\n</content>", 'T'));
    }

    public function testWindowsLineEndingsAndRunsOfBlankLinesAreTidied(): void
    {
        self::assertSame("Un.\n\nDeux.", $this->markdown->clean("Un.\r\n\r\n\r\n\r\nDeux.\r\n", 'T'));
    }

    public function testAnEmptyDocumentGivesAnEmptyText(): void
    {
        self::assertSame('', $this->markdown->clean("\n\n   \n", 'T'));
    }
}
