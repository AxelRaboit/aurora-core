<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Notes\Craft\Service;

use Aurora\Module\Notes\Craft\Service\CraftMarkdown;
use PHPUnit\Framework\TestCase;

/**
 * Ce que Craft écrit, et ce qu'une note en garde.
 *
 * Le Markdown de Craft entre presque tel quel dans une note : ce qui est
 * vérifié ici, c'est le « presque » - ses balises à lui, qui ressortiraient
 * en clair au milieu du texte, et son titre, qui ouvrirait la note une
 * seconde fois.
 */
final class CraftMarkdownTest extends TestCase
{
    private CraftMarkdown $markdown;

    protected function setUp(): void
    {
        $this->markdown = new CraftMarkdown();
    }

    /** Du Markdown ordinaire passe sans changer. */
    public function testPlainMarkdownPassesThrough(): void
    {
        $source = "## Le contexte\n\nTrois **choses** à faire.\n\n- Relire\n- [x] Envoyer\n\n| a | b |\n| --- | --- |\n| 1 | 2 |";

        self::assertSame($source, $this->markdown->clean($source, 'Un autre titre'));
    }

    /**
     * Craft enveloppe un document dans une page dont le titre est le sien. La
     * note le porte déjà : l'écrire une seconde fois en tête du texte est un
     * doublon, vu sur le premier import réel.
     */
    public function testTheDocumentTitleIsNotWrittenTwice(): void
    {
        self::assertSame(
            'Random note',
            $this->markdown->clean("<page><pageTitle>This is a test</pageTitle><content>\n\nRandom note\n\n</content></page>", 'This is a test'),
        );
        self::assertSame('Du texte.', $this->markdown->clean("#  Brief   Septembre\n\nDu texte.", 'brief septembre'));
    }

    /** Un titre de section qui n'est pas celui du document, lui, reste. */
    public function testAHeadingThatIsNotTheTitleSurvives(): void
    {
        self::assertSame("## Le contexte\n\nDu texte.", $this->markdown->clean("## Le contexte\n\nDu texte.", 'Brief septembre'));
    }

    /** Seulement en tête : un document qui répète son titre plus bas le fait exprès. */
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

    /** La forme courte, que l'aperçu des notes sait surligner. */
    public function testAHighlightBecomesTheShortForm(): void
    {
        self::assertSame('une ==date== à tenir', $this->markdown->clean('une <highlight color="yellow">date</highlight> à tenir', 'T'));
    }

    /** Un fil de commentaire est une conversation interne à Craft. */
    public function testACommentThreadLeavesItsWordBehind(): void
    {
        self::assertSame('la phrase commentée', $this->markdown->clean('la <comment id="c-1">phrase</comment> commentée', 'T'));
    }

    /** Ces renvois ne mènent nulle part hors de Craft : un lien mort est pire qu'un mot. */
    public function testCraftInternalLinksKeepTheirWordAndLoseTheirAddress(): void
    {
        self::assertSame(
            'voir le plan, hier et ailleurs, mais [le site](https://example.test) reste',
            $this->markdown->clean('voir [le plan](block://abc), [hier](date://2026-09-18) et [ailleurs](invalid:out_of_scope), mais [le site](https://example.test) reste', 'T'),
        );
    }

    /** Un encadré enveloppe des blocs : il devient l'encadré des notes, lignes vides comprises. */
    public function testACalloutBecomesTheNotesCallout(): void
    {
        self::assertSame(
            "> [!note]\n> Attention à la date.\n>\n> Elle bouge.\n\nLa suite.",
            $this->markdown->clean("<callout>\nAttention à la date.\n\nElle bouge.\n</callout>\n\nLa suite.", 'T'),
        );
    }

    /**
     * Sans fermeture, l'encadré ne mange pas la fin du document : la balise
     * s'efface et le texte reste.
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
     * Craft indente le corps d'un document dans son `<content>`, et deux
     * espaces valent chez lui un niveau d'imbrication : sans le retrait, une
     * liste à plat arrivait empilée sous sa première entrée.
     */
    public function testTheIndentationOfContentIsNotReadAsNesting(): void
    {
        self::assertSame(
            "### T\n\n- un\n- deux\n- trois",
            $this->markdown->clean("<page><pageTitle>T</pageTitle><content>\n    - un\n    - deux\n    - trois\n</content></page>", 'Document'),
        );
    }

    /** Le retrait commun seulement : l'imbrication voulue survit. */
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
