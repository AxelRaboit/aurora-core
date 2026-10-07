<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\Post\Service;

use Aurora\Core\Content\BlockHtmlSanitizer;
use Aurora\Core\Content\BlockRendererInterface;
use Aurora\Core\Content\RawHtmlSanitizer;
use Aurora\Module\Editorial\Post\Service\BlocksRenderer;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;

/**
 * The block shapes this renderer reads are written by Editor.js, not chosen
 * here. When the two drifted apart the page came out blank and nothing
 * failed - the editor's own preview kept working, so it only showed once
 * published. These tests pin the shapes to what the tools actually save.
 */
final class BlocksRendererTest extends TestCase
{
    public function testRendersAHeaderAtItsLevelAndClampsOutOfRangeOnes(): void
    {
        self::assertSame('<h3>Titre</h3>', $this->render([
            ['type' => 'header', 'data' => ['level' => 3, 'text' => 'Titre']],
        ]));

        self::assertSame('<h6>Trop bas</h6>', $this->render([
            ['type' => 'header', 'data' => ['level' => 99, 'text' => 'Trop bas']],
        ]));
    }

    /**
     * The callout tool saves {type, title, message} and the stylesheet keys
     * its colours on `.callout--info`. Reading `text` or emitting
     * `.callout-info` produces an empty, uncoloured box.
     */
    public function testRendersACalloutInTheShapeTheToolSaves(): void
    {
        $html = $this->render([
            ['type' => 'callout', 'data' => ['type' => 'warning', 'title' => 'Attention', 'message' => 'Ceci compte']],
        ]);

        self::assertStringContainsString('class="callout callout--warning"', $html);
        self::assertStringContainsString('<strong>Attention</strong>', $html);
        self::assertStringContainsString('<p>Ceci compte</p>', $html);
    }

    /** A chosen icon sits beside the words, drawn in the callout's colour. */
    public function testRendersACalloutIconBesideItsText(): void
    {
        $html = $this->render([
            ['type' => 'callout', 'data' => ['type' => 'accent', 'icon' => 'calendar', 'title' => 'Disponible', 'message' => 'Dès octobre']],
        ]);

        self::assertStringContainsString('class="callout callout--accent callout--icon"', $html);
        self::assertStringContainsString('<span class="callout__icon" aria-hidden="true"><svg', $html);
        self::assertStringContainsString('<div class="callout__body"><strong>Disponible</strong><p>Dès octobre</p></div>', $html);
    }

    /**
     * The icon is a name picked from a closed list, never markup: anything
     * else is dropped and the callout keeps the shape it had before icons.
     */
    public function testACalloutIgnoresAnIconItDoesNotKnow(): void
    {
        $html = $this->render([
            ['type' => 'callout', 'data' => ['type' => 'info', 'icon' => '<script>alert(1)</script>', 'title' => 'T', 'message' => 'M']],
        ]);

        self::assertSame('<aside class="callout callout--info"><strong>T</strong><p>M</p></aside>', $html);
    }

    /** The icons the editor offers, in the order it offers them. */
    public function testTheCalloutIconsAreTheOnesTheEditorOffers(): void
    {
        self::assertSame(
            ['info', 'check-circle', 'alert-triangle', 'clock', 'calendar', 'star', 'lightbulb', 'message-circle', 'pause-circle'],
            BlocksRenderer::CALLOUT_ICON_NAMES,
        );

        // Every name the list announces has a drawing, and no drawing goes unannounced.
        $drawings = (new ReflectionClassConstant(BlocksRenderer::class, 'CALLOUT_ICONS'))->getValue();
        self::assertIsArray($drawings);
        self::assertSame(BlocksRenderer::CALLOUT_ICON_NAMES, array_keys($drawings));
    }

    public function testALabelIsAPillInItsToneAndTiltsWhenAsked(): void
    {
        self::assertSame(
            '<p class="label-pill-row"><span class="label-pill label-pill--rose label-pill--tilt">Studio Grenadine</span></p>',
            $this->render([['type' => 'label', 'data' => ['text' => 'Studio Grenadine', 'tone' => 'rose', 'tilt' => true]]]),
        );
    }

    /** The tone becomes a class: an unknown one falls back to black. */
    public function testALabelIgnoresAToneItDoesNotKnowAndDrawsNothingEmpty(): void
    {
        self::assertSame(
            '<p class="label-pill-row"><span class="label-pill label-pill--dark">Réseaux</span></p>',
            $this->render([['type' => 'label', 'data' => ['text' => 'Réseaux', 'tone' => 'x" onclick="y']]]),
        );
        self::assertSame('', $this->render([['type' => 'label', 'data' => ['text' => '  ']]]));
    }

    public function testASocialListDrawsEachKnownNetworkWithItsMark(): void
    {
        $html = $this->render([['type' => 'socials', 'data' => ['items' => [
            ['network' => 'instagram', 'handle' => '@the.familystudio', 'url' => 'https://instagram.com/the.familystudio'],
            ['network' => 'myspace', 'handle' => 'oublié'],
            ['network' => 'linkedin', 'handle' => 'Family Studio', 'url' => 'javascript:alert(1)'],
            ['network' => 'facebook', 'handle' => ''],
        ]]]]);

        self::assertSame(2, mb_substr_count($html, '<li '));
        self::assertStringContainsString('social-list__item--instagram', $html);
        self::assertStringContainsString('href="https://instagram.com/the.familystudio"', $html);
        // A handle without a safe address stays words, not a link.
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringNotContainsString('oublié', $html);
    }

    public function testASocialListWithNothingToShowDrawsNothing(): void
    {
        self::assertSame('', $this->render([['type' => 'socials', 'data' => ['items' => []]]]));
    }

    /** Every network the list announces has a drawing, and none goes unannounced. */
    public function testTheSocialNetworksAreTheOnesTheEditorOffers(): void
    {
        $drawings = (new ReflectionClassConstant(BlocksRenderer::class, 'SOCIAL_ICONS'))->getValue();
        self::assertIsArray($drawings);
        self::assertSame(BlocksRenderer::SOCIAL_NETWORK_NAMES, array_keys($drawings));
    }

    /** @editorjs/list v1 stored plain strings, v2 stores {content, meta}. */
    public function testRendersBothListShapes(): void
    {
        self::assertSame('<ul><li>un</li><li>deux</li></ul>', $this->render([
            ['type' => 'list', 'data' => ['style' => 'unordered', 'items' => ['un', 'deux']]],
        ]));

        self::assertSame('<ol><li>un</li></ol>', $this->render([
            ['type' => 'list', 'data' => ['style' => 'ordered', 'items' => [['content' => 'un']]]],
        ]));
    }

    /**
     * The heading row goes in a `<thead>`: the typography styles read
     * `thead th` and `tbody td`, and a `<th>` among the body rows came out
     * without padding, a few pixels left of its column.
     */
    public function testRendersATableWithItsHeadingRowApart(): void
    {
        self::assertSame(
            '<div class="content-table"><table><thead><tr><th></th><th>Minimal</th></tr></thead><tbody><tr><td>Réseaux</td><td>2</td></tr></tbody></table></div>',
            $this->render([['type' => 'table', 'data' => ['withHeadings' => true, 'content' => [['', 'Minimal'], ['Réseaux', '2']]]]]),
        );

        self::assertSame(
            '<div class="content-table"><table><tbody><tr><td>a</td></tr></tbody></table></div>',
            $this->render([['type' => 'table', 'data' => ['content' => [['a']]]]]),
        );
    }

    public function testRendersAChecklistWithItsCheckedState(): void
    {
        $html = $this->render([
            ['type' => 'list', 'data' => ['style' => 'checklist', 'items' => [
                ['content' => 'fait', 'meta' => ['checked' => true]],
                ['content' => 'à faire', 'meta' => ['checked' => false]],
            ]]],
        ]);

        self::assertStringContainsString('<input type="checkbox" disabled checked> fait', $html);
        self::assertStringContainsString('<input type="checkbox" disabled> à faire', $html);
    }

    /** Code is read, not interpreted: it is escaped whole rather than sanitized. */
    public function testEscapesCodeRatherThanSanitizingIt(): void
    {
        self::assertSame(
            '<pre><code>&lt;b&gt;gras&lt;/b&gt;</code></pre>',
            $this->render([['type' => 'code', 'data' => ['code' => '<b>gras</b>']]]),
        );
    }

    /**
     * The shape the editor actually saves: each column is the `innerHTML` of a
     * contenteditable, a string. This renderer required an array and emitted
     * nothing otherwise, so every two-column block ever written published as
     * two empty divs - and the test below passed the whole time, because it
     * asserted the nested shape nobody produces.
     */
    public function testRendersTwoColumnsInTheShapeTheToolSaves(): void
    {
        $html = $this->render([
            ['type' => 'twoColumn', 'data' => [
                'left' => '<b>gauche</b>',
                'right' => 'droite',
            ]],
        ]);

        self::assertSame('<div class="two-column"><div><b>gauche</b></div><div>droite</div></div>', $html);
    }

    public function testAColumnGoesThroughTheSanitizer(): void
    {
        $html = $this->render([
            ['type' => 'twoColumn', 'data' => ['left' => '<a href="javascript:alert(1)">x</a>', 'right' => '']],
        ]);

        self::assertStringNotContainsString('javascript:', $html);
    }

    /**
     * The nested shape stays readable: a module block renderer may hand over
     * real blocks, and dropping it would break anything that already does.
     */
    public function testNestsTwoColumnContent(): void
    {
        $html = $this->render([
            ['type' => 'twoColumn', 'data' => [
                'left' => [['type' => 'paragraph', 'data' => ['text' => 'gauche']]],
                'right' => [['type' => 'paragraph', 'data' => ['text' => 'droite']]],
            ]],
        ]);

        self::assertSame('<div class="two-column"><div><p>gauche</p></div><div><p>droite</p></div></div>', $html);
    }

    /**
     * Same class of mismatch: this looked for the url under an `image` key the
     * editor has never written - it saves `url` at the top level, beside
     * `caption` and `flip`. Every media-text block published its text with no
     * picture at all.
     */
    public function testRendersMediaTextInTheShapeTheToolSaves(): void
    {
        $html = $this->render([
            ['type' => 'mediaText', 'data' => [
                'url' => '/uploads/ged/photo.png',
                'text' => 'Le texte',
                'caption' => 'La légende',
            ]],
        ]);

        self::assertStringContainsString('src="/uploads/ged/photo.png"', $html);
        self::assertStringContainsString('Le texte', $html);
        self::assertStringContainsString('<figcaption>La légende</figcaption>', $html);
    }

    /** An option the editor offered and the page ignored. */
    public function testTheFlippedLayoutReachesTheMarkup(): void
    {
        $html = $this->render([
            ['type' => 'mediaText', 'data' => ['url' => '/x.png', 'text' => 'y', 'flip' => true]],
        ]);

        self::assertStringContainsString('media-text--flip', $html);

        $straight = $this->render([
            ['type' => 'mediaText', 'data' => ['url' => '/x.png', 'text' => 'y']],
        ]);

        self::assertStringNotContainsString('media-text--flip', $straight);
    }

    public function testMediaTextWithNoPictureStillRendersItsText(): void
    {
        $html = $this->render([['type' => 'mediaText', 'data' => ['text' => 'Seul le texte']]]);

        self::assertStringContainsString('Seul le texte', $html);
        self::assertStringNotContainsString('<img', $html);
    }

    public function testSkipsAnImageWithNoUrlRatherThanEmittingABrokenTag(): void
    {
        self::assertSame('', $this->render([['type' => 'image', 'data' => ['file' => []]]]));
    }

    /** A reader should get a page missing a section, not a stack trace. */
    public function testDropsABlockTypeNobodyClaims(): void
    {
        self::assertSame('', $this->render([['type' => 'productGrid', 'data' => []]]));
    }

    public function testHandsAnUnknownTypeToTheModuleThatClaimsIt(): void
    {
        $renderer = new class implements BlockRendererInterface {
            public function getType(): string
            {
                return 'productGrid';
            }

            public function render(array $data, string $locale): string
            {
                return '<div class="grid">'.$locale.'</div>';
            }
        };

        self::assertSame(
            '<div class="grid">fr</div>',
            $this->render([['type' => 'productGrid', 'data' => []]], [$renderer]),
        );
    }

    public function testIgnoresAnEntryThatIsNotABlock(): void
    {
        self::assertSame('<p>ok</p>', $this->render([
            'rubbish',
            ['type' => 'paragraph', 'data' => ['text' => 'ok']],
        ]));
    }

    public function testARawBlockRendersItsHtmlThroughTheWiderFilter(): void
    {
        // The point of the block: what the regular text filter would strip
        // gets through here, without letting scripts through for all that.
        $out = $this->render([
            ['type' => 'raw', 'data' => ['html' => '<table class="t"><tr><td>1</td></tr></table><script>alert(1)</script>']],
        ]);

        self::assertStringContainsString('<table class="t">', $out);
        self::assertStringNotContainsString('script', $out);
    }

    /**
     * @param array<int, mixed>            $blocks
     * @param list<BlockRendererInterface> $extensions
     */
    private function render(array $blocks, array $extensions = []): string
    {
        return (new BlocksRenderer(new BlockHtmlSanitizer(), new RawHtmlSanitizer(), $extensions))->render($blocks, 'fr');
    }
}
