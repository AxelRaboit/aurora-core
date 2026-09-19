<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Studio\SpaceNote\Service;

use Aurora\Module\Studio\SpaceNote\Service\MarkdownToBlocks;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function json_encode;

/**
 * Ce que Craft écrit, et ce que l'éditeur accepte d'ouvrir.
 *
 * Les deux bouts sont stricts pour des raisons différentes. Craft rend du
 * Markdown avec ses propres extensions - encadrés, bascules, cases à cocher -
 * et l'éditeur d'Aurora ne monte qu'une poignée d'outils, avec des formes
 * précises. Une conversion qui tombe entre les deux donne une note qui
 * s'affiche et ne s'édite pas, ce qui est la pire des deux moitiés.
 */
final class MarkdownToBlocksTest extends TestCase
{
    private MarkdownToBlocks $convert;

    protected function setUp(): void
    {
        $this->convert = new MarkdownToBlocks();
    }

    /**
     * L'éditeur ne monte que les niveaux deux, trois et quatre. Un `#` de
     * Craft écrit tel quel donnerait un bloc qu'il refuse d'ouvrir.
     */
    public function testHeadingLevelsAreClampedToWhatTheEditorMounts(): void
    {
        $blocks = $this->convert->convert("# Un\n\n## Deux\n\n### Trois\n\n##### Cinq");

        self::assertSame([2, 2, 3, 4], array_column(array_column($blocks, 'data'), 'level'));
        self::assertSame(['Un', 'Deux', 'Trois', 'Cinq'], array_column(array_column($blocks, 'data'), 'text'));
    }

    public function testConsecutiveLinesMakeOneParagraph(): void
    {
        $blocks = $this->convert->convert("Une phrase\nqui continue.\n\nUne autre.");

        self::assertCount(2, $blocks);
        self::assertSame('paragraph', $blocks[0]['type']);
        self::assertSame('Une phrase qui continue.', $blocks[0]['data']['text']);
        self::assertSame('Une autre.', $blocks[1]['data']['text']);
    }

    /**
     * Chaque entrée porte son `meta` et ses `items`, faute de quoi la liste
     * s'affiche et ne s'édite pas.
     */
    public function testAnUnorderedListCarriesTheKeysTheToolReads(): void
    {
        $blocks = $this->convert->convert("- un\n- deux");

        self::assertSame('list', $blocks[0]['type']);
        self::assertSame('unordered', $blocks[0]['data']['style']);
        self::assertSame('un', $blocks[0]['data']['items'][0]['content']);
        self::assertArrayHasKey('meta', $blocks[0]['data']['items'][0]);
        self::assertSame([], $blocks[0]['data']['items'][0]['items']);
    }

    public function testANumberedListIsOrdered(): void
    {
        $blocks = $this->convert->convert("1. un\n2. deux");

        self::assertSame('ordered', $blocks[0]['data']['style']);
        self::assertCount(2, $blocks[0]['data']['items']);
    }

    /** Les cases de Craft deviennent une liste à cocher, état compris. */
    public function testCheckboxesBecomeAChecklistThatRemembersWhatWasTicked(): void
    {
        $blocks = $this->convert->convert("- [x] fait\n- [ ] à faire");

        self::assertSame('checklist', $blocks[0]['data']['style']);
        self::assertSame(['checked' => true], $blocks[0]['data']['items'][0]['meta']);
        self::assertSame(['checked' => false], $blocks[0]['data']['items'][1]['meta']);
    }

    /**
     * Une bascule de Craft rend son contenu indenté de deux espaces : l'aplatir
     * ferait d'un document replié une liste sans hiérarchie.
     */
    public function testIndentedItemsStayUnderTheirParent(): void
    {
        $blocks = $this->convert->convert("- parent\n  - enfant\n  - autre\n- voisin");

        $items = $blocks[0]['data']['items'];

        self::assertCount(2, $items);
        self::assertSame('parent', $items[0]['content']);
        self::assertCount(2, $items[0]['items']);
        self::assertSame('enfant', $items[0]['items'][0]['content']);
        self::assertSame('voisin', $items[1]['content']);
    }

    public function testAQuoteJoinsItsLines(): void
    {
        $blocks = $this->convert->convert("> une citation\n> sur deux lignes\n\nla suite");

        self::assertSame('quote', $blocks[0]['type']);
        self::assertSame('une citation sur deux lignes', $blocks[0]['data']['text']);
        self::assertSame('paragraph', $blocks[1]['type']);
    }

    /** Aucun outil d'encadré n'est monté : le texte reste, la boîte change. */
    public function testACraftCalloutBecomesAQuote(): void
    {
        $blocks = $this->convert->convert('<callout>Attention à la date</callout>');

        self::assertSame('quote', $blocks[0]['type']);
        self::assertSame('Attention à la date', $blocks[0]['data']['text']);
    }

    /** Rien de ce qu'un bloc de code contient n'est interprété. */
    public function testAFencedBlockKeepsItsContentVerbatim(): void
    {
        $blocks = $this->convert->convert("```php\n\$a = **pas du gras**;\n# pas un titre\n```");

        self::assertSame('code', $blocks[0]['type']);
        self::assertSame("\$a = **pas du gras**;\n# pas un titre", $blocks[0]['data']['code']);
    }

    public function testAnUnclosedFenceStillEndsTheDocument(): void
    {
        $blocks = $this->convert->convert("```\nligne");

        self::assertCount(1, $blocks);
        self::assertSame('ligne', $blocks[0]['data']['code']);
    }

    public function testARuleBecomesADelimiter(): void
    {
        $blocks = $this->convert->convert("avant\n\n---\n\naprès");

        self::assertSame(['paragraph', 'delimiter', 'paragraph'], array_column($blocks, 'type'));
    }

    /**
     * Seule sur sa ligne c'est une figure ; au milieu d'une phrase, elle reste
     * dans le paragraphe.
     */
    public function testAnImageAloneOnItsLineIsABlock(): void
    {
        $blocks = $this->convert->convert("![Le studio](https://craft.example/a.png)\n\nvoir ![ici](https://x/b.png) plutôt");

        self::assertSame('image', $blocks[0]['type']);
        self::assertSame('https://craft.example/a.png', $blocks[0]['data']['file']['url']);
        self::assertSame('Le studio', $blocks[0]['data']['caption']);
        self::assertSame('paragraph', $blocks[1]['type']);
    }

    public function testATableKeepsItsHeadingRow(): void
    {
        $blocks = $this->convert->convert("| Nom | Rôle |\n| --- | --- |\n| Camille | Marketing |");

        self::assertSame('table', $blocks[0]['type']);
        self::assertTrue($blocks[0]['data']['withHeadings']);
        self::assertSame([['Nom', 'Rôle'], ['Camille', 'Marketing']], $blocks[0]['data']['content']);
    }

    public function testInlineMarkupBecomesTheHtmlTheBlocksAllow(): void
    {
        $blocks = $this->convert->convert('du **gras**, de l\'*italique*, du ~~barré~~ et du `code`');

        self::assertSame(
            'du <b>gras</b>, de l\'<i>italique</i>, du <s>barré</s> et du <code>code</code>',
            $blocks[0]['data']['text'],
        );
    }

    /**
     * L'apostrophe reste elle-même : c'est du texte de contenu, et une note
     * française pleine de `&#039;` serait illisible. Le guillemet double, lui,
     * est échappé - il sortirait de l'attribut `href`.
     */
    public function testApostrophesSurviveButDoubleQuotesAreEscaped(): void
    {
        $blocks = $this->convert->convert('l\'atelier dit "bonjour"');

        self::assertSame('l\'atelier dit &quot;bonjour&quot;', $blocks[0]['data']['text']);
    }

    public function testALinkKeepsItsAddress(): void
    {
        $blocks = $this->convert->convert('voir [le brief](https://exemple.fr/brief)');

        self::assertSame('voir <a href="https://exemple.fr/brief">le brief</a>', $blocks[0]['data']['text']);
    }

    /**
     * Ce qui arrive ici vient d'un document écrit par quelqu'un, et la note
     * sera lue par un client.
     */
    public function testHtmlInTheSourceIsEscapedRatherThanPassedThrough(): void
    {
        $blocks = $this->convert->convert('un <script>alert(1)</script> et un <b>gras écrit à la main</b>');

        self::assertStringNotContainsString('<script>', $blocks[0]['data']['text']);
        self::assertStringContainsString('&lt;script&gt;', $blocks[0]['data']['text']);
        self::assertStringNotContainsString('<b>gras écrit', $blocks[0]['data']['text']);
    }

    /** Deux astérisques dans un extrait de code ne sont pas du gras. */
    public function testCodeSpansAreNotReadAsMarkup(): void
    {
        $blocks = $this->convert->convert('la variable `**ptr` pointe');

        self::assertSame('la variable <code>**ptr</code> pointe', $blocks[0]['data']['text']);
    }

    /** Le `meta` d'une entrée doit sortir en objet JSON, pas en tableau vide. */
    public function testAnEmptyMetaEncodesAsAnObject(): void
    {
        $blocks = $this->convert->convert('- une entrée');
        $decoded = json_decode((string) json_encode($blocks), true);

        self::assertSame([], $decoded[0]['data']['items'][0]['meta']);
        self::assertStringContainsString('"meta":{}', (string) json_encode($blocks));
    }

    /**
     * Les balises de Craft, telles que sa spécification les documente.
     *
     * Laissées telles quelles, elles ressortiraient en `&lt;page&gt;` au
     * milieu de la note d'un client, ce qui est la pire des sorties : ni le
     * contenu, ni rien.
     */
    public function testANestedPageBecomesAHeadingAndItsContent(): void
    {
        $blocks = $this->convert->convert(
            "<page><pageTitle>Le brief</pageTitle><content>\n\nLe texte.\n\n</content></page>",
        );

        self::assertSame(['header', 'paragraph'], array_column($blocks, 'type'));
        self::assertSame('Le brief', $blocks[0]['data']['text']);
        self::assertSame(3, $blocks[0]['data']['level']);
        self::assertSame('Le texte.', $blocks[1]['data']['text']);
    }

    public function testAHighlightBecomesAMark(): void
    {
        $blocks = $this->convert->convert('une <highlight color="yellow">date</highlight> à tenir');

        self::assertSame('une <mark>date</mark> à tenir', $blocks[0]['data']['text']);
    }

    /** Un fil de commentaire est une conversation interne à Craft. */
    public function testACommentThreadLeavesItsWordBehind(): void
    {
        $blocks = $this->convert->convert('la <comment id="c-1">phrase</comment> commentée');

        self::assertSame('la phrase commentée', $blocks[0]['data']['text']);
    }

    /**
     * Ces renvois ne mènent nulle part hors de Craft, et le client n'y a pas
     * de compte : un lien mort dans sa note est pire qu'un mot.
     */
    public function testCraftInternalLinksKeepTheirWordAndLoseTheirAddress(): void
    {
        $blocks = $this->convert->convert(
            'voir [le plan](block://abc), [hier](date://2026-09-18) et [ailleurs](invalid:out_of_scope)',
        );

        self::assertSame('voir le plan, hier et ailleurs', $blocks[0]['data']['text']);
    }

    /** Un encadré enveloppe des blocs : il tient sur plusieurs lignes. */
    public function testAMultiLineCalloutBecomesOneQuote(): void
    {
        $blocks = $this->convert->convert(
            "<callout>\nAttention à la date.\n\nElle bouge.\n</callout>\n\nLa suite.",
        );

        self::assertSame(['quote', 'paragraph'], array_column($blocks, 'type'));
        self::assertSame('Attention à la date. Elle bouge.', $blocks[0]['data']['text']);
        self::assertSame('La suite.', $blocks[1]['data']['text']);
    }

    /**
     * Sans fermeture, l'encadré ne mange pas la fin du document : une note
     * amputée est pire qu'un encadré rendu à plat.
     */
    public function testAnUnclosedCalloutDoesNotSwallowTheRest(): void
    {
        $blocks = $this->convert->convert("<callout>Un mot\n\nUn paragraphe.");

        self::assertSame(['quote', 'paragraph'], array_column($blocks, 'type'));
        self::assertSame('Un paragraphe.', $blocks[1]['data']['text']);
    }

    /** Une citation suivie d'un paragraphe sans ligne vide entre les deux. */
    public function testAQuoteDoesNotEatTheLineThatEndsIt(): void
    {
        $blocks = $this->convert->convert("> une citation\nun paragraphe");

        self::assertSame(['quote', 'paragraph'], array_column($blocks, 'type'));
        self::assertSame('un paragraphe', $blocks[1]['data']['text']);
    }

    public function testCollectionTagsRenderTheirContentWithoutTheirBoxes(): void
    {
        $blocks = $this->convert->convert(
            '<collection><title>Clients</title><properties>Nom,Statut</properties>'
            .'<content><collectionItem><title>Camille</title></collectionItem></content></collection>',
        );

        $text = $blocks[0]['data']['text'];

        self::assertStringNotContainsString('collection', $text);
        self::assertStringContainsString('Clients', $text);
        self::assertStringContainsString('Camille', $text);
        self::assertStringNotContainsString('Statut', $text);
    }

    public function testAnEmptyDocumentGivesNoBlocks(): void
    {
        self::assertSame([], $this->convert->convert("\n\n   \n"));
    }
}
