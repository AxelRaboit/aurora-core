<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\Post\Grid;

use Aurora\Core\Content\ContentValueNormalizer;
use Aurora\Module\Editorial\Post\Grid\GridNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * The grid's contract, and the place where the split between what is shared
 * and what is translated is actually enforced.
 */
final class GridNormalizerTest extends TestCase
{
    private GridNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new GridNormalizer(new ContentValueNormalizer());
    }

    public function testGarbageInputStillProducesAUsableLayout(): void
    {
        $layout = $this->normalizer->normalizeLayout('pas un tableau');

        self::assertFalse($layout['enabled']);
        self::assertSame([], $layout['zones']);
        self::assertSame(4, $layout['snap'], 'twelfths, the step most layouts are described in');
    }

    // ── Which half holds what ─────────────────────────────────────────────

    /**
     * A zone that is text in one language and a video in another is not one
     * zone, and no two readers would see the same page.
     */
    public function testTheTypeIsSharedAndCannotBeTranslated(): void
    {
        $layout = $this->normalizer->normalizeLayout([
            'zones' => [['id' => 'a1', 'type' => 'video']],
        ]);

        $content = $this->normalizer->normalizeContent(
            ['zones' => ['a1' => ['type' => 'text', 'url' => 'https://youtu.be/x']]],
            $layout,
        );

        self::assertSame('video', $layout['zones'][0]['type']);
        self::assertArrayNotHasKey('type', $content['zones']['a1']);
    }

    /**
     * The picture is the same picture; describing it is writing. Exactly the
     * line the banner draws.
     */
    public function testTheMediaIsSharedAndItsAltTextIsNot(): void
    {
        $layout = $this->normalizer->normalizeLayout([
            'zones' => [['id' => 'a1', 'type' => 'media', 'mediaId' => 12]],
        ]);

        $content = $this->normalizer->normalizeContent(
            ['zones' => ['a1' => ['alt' => 'Une photo du chantier']]],
            $layout,
        );

        self::assertSame(12, $layout['zones'][0]['mediaId']);
        self::assertSame('Une photo du chantier', $content['zones']['a1']['alt']);
        self::assertArrayNotHasKey('mediaId', $content['zones']['a1']);
    }

    /**
     * The linked post has its own translations, so the renderer picks the
     * right one. Asking an editor to re-pick it per language is the drift this
     * split exists to prevent.
     */
    public function testALinkedPublicationIsShared(): void
    {
        $layout = $this->normalizer->normalizeLayout([
            'zones' => [['id' => 'a1', 'type' => 'post', 'postId' => 42]],
        ]);

        self::assertSame(42, $layout['zones'][0]['postId']);
        self::assertArrayNotHasKey(
            'postId',
            $this->normalizer->normalizeContent([], $layout)['zones']['a1'],
        );
    }

    /** A localised video has a localised address. */
    public function testTheVideoAddressIsPerLanguage(): void
    {
        $layout = $this->normalizer->normalizeLayout([
            'zones' => [['id' => 'a1', 'type' => 'video']],
        ]);

        $french = $this->normalizer->normalizeContent(
            ['zones' => ['a1' => ['url' => 'https://vimeo.com/fr']]],
            $layout,
        );
        $english = $this->normalizer->normalizeContent(
            ['zones' => ['a1' => ['url' => 'https://vimeo.com/en']]],
            $layout,
        );

        self::assertSame('https://vimeo.com/fr', $french['zones']['a1']['url']);
        self::assertSame('https://vimeo.com/en', $english['zones']['a1']['url']);
    }

    /** The value lands in an iframe or an anchor, so it goes through the whitelist. */
    public function testAVideoAddressWithAnUnsafeSchemeIsRefused(): void
    {
        $layout = $this->normalizer->normalizeLayout(['zones' => [['id' => 'a1', 'type' => 'video']]]);

        $content = $this->normalizer->normalizeContent(
            ['zones' => ['a1' => ['url' => 'javascript:alert(1)']]],
            $layout,
        );

        self::assertNull($content['zones']['a1']['url']);
    }

    // ── Zones ─────────────────────────────────────────────────────────────

    public function testAZoneOfUnknownTypeIsDroppedRatherThanDefaulted(): void
    {
        $layout = $this->normalizer->normalizeLayout([
            'zones' => [
                ['id' => 'a1', 'type' => 'text'],
                ['id' => 'a2', 'type' => 'carousel'],
                'pas un tableau',
            ],
        ]);

        self::assertCount(1, $layout['zones']);
        self::assertSame('text', $layout['zones'][0]['type']);
    }

    public function testEveryKeyIsPresentWhateverTheType(): void
    {
        $zone = $this->normalizer->normalizeLayout([
            'zones' => [['id' => 'a1', 'type' => 'text']],
        ])['zones'][0];

        self::assertSame(
            ['id', 'type', 'span', 'ratio', 'mediaId', 'postId', 'children'],
            array_keys($zone),
            'switching a zone type in the editor must not lose what was picked',
        );
    }

    public function testAZoneThatSaysNothingIsFullWidthOnEveryBreakpoint(): void
    {
        $span = $this->normalizer->normalizeLayout([
            'zones' => [['id' => 'a1', 'type' => 'text']],
        ])['zones'][0]['span'];

        self::assertSame(48, $span['base']);
        self::assertNull($span['md'], 'absent steps inherit the one below');
        self::assertNull($span['lg']);
    }

    public function testSpansAreClampedToTheGrid(): void
    {
        $span = $this->normalizer->normalizeLayout([
            'zones' => [['id' => 'a1', 'type' => 'text', 'span' => ['base' => 0, 'md' => 900, 'lg' => '24']]],
        ])['zones'][0]['span'];

        self::assertSame(1, $span['base']);
        self::assertSame(48, $span['md']);
        self::assertSame(24, $span['lg'], 'a numeric string is accepted and cast');
    }

    public function testTheZoneCountIsCapped(): void
    {
        $layout = $this->normalizer->normalizeLayout([
            'zones' => array_fill(0, 200, ['type' => 'text']),
        ]);

        self::assertCount(60, $layout['zones']);
    }

    public function testTwoZonesCannotShareAnId(): void
    {
        $zones = $this->normalizer->normalizeLayout([
            'zones' => [['id' => 'dup', 'type' => 'text'], ['id' => 'dup', 'type' => 'media']],
        ])['zones'];

        self::assertSame('dup', $zones[0]['id']);
        self::assertNotSame('dup', $zones[1]['id'], 'or both would read one translation\'s content');
    }

    // ── The snap ──────────────────────────────────────────────────────────

    public function testTheSnapIsOneOfTheOfferedSteps(): void
    {
        foreach ([4, 2, 1] as $snap) {
            self::assertSame($snap, $this->normalizer->normalizeLayout(['snap' => $snap])['snap']);
        }

        self::assertSame(4, $this->normalizer->normalizeLayout(['snap' => 7])['snap']);
        self::assertSame(4, $this->normalizer->normalizeLayout(['snap' => 'douze'])['snap']);
    }

    /**
     * A picture alone on its row has no imposed height, so `fill` has nothing
     * to fill — it is stored all the same, because the arrangement is written
     * for the large screen and the template is where "nothing to fill" is
     * decided, not the normaliser.
     */
    public function testFillIsAShapeLikeTheOthers(): void
    {
        $layout = $this->normalizer->normalizeLayout([
            'zones' => [['id' => 'a1', 'type' => 'media', 'ratio' => 'fill']],
        ]);

        self::assertSame(GridNormalizer::RATIO_FILL, $layout['zones'][0]['ratio']);
    }

    public function testTheRatioIsOneOfTheOfferedShapes(): void
    {
        foreach (GridNormalizer::RATIOS as $ratio) {
            $layout = $this->normalizer->normalizeLayout([
                'zones' => [['id' => 'a1', 'type' => 'media', 'ratio' => $ratio]],
            ]);

            self::assertSame($ratio, $layout['zones'][0]['ratio']);
        }
    }

    /**
     * The default has to be the behaviour already on the published pages. A
     * zone stored before this field existed carries no ratio at all, and it
     * must keep rendering at its own proportions rather than silently gaining
     * a crop.
     */
    public function testAZoneThatNamesNoRatioKeepsItsOwnProportions(): void
    {
        $layout = $this->normalizer->normalizeLayout([
            'zones' => [
                ['id' => 'a1', 'type' => 'media'],
                ['id' => 'a2', 'type' => 'media', 'ratio' => 'panoramique'],
            ],
        ]);

        self::assertSame(GridNormalizer::RATIO_NATURAL, $layout['zones'][0]['ratio']);
        self::assertSame(GridNormalizer::RATIO_NATURAL, $layout['zones'][1]['ratio']);
    }

    /**
     * Cropping is design, and the design is written once — the same argument
     * that puts the span on the post rather than on the translation.
     */
    public function testTheRatioIsSharedAndCannotBeTranslated(): void
    {
        $layout = $this->normalizer->normalizeLayout([
            'zones' => [['id' => 'a1', 'type' => 'media', 'ratio' => '1x1']],
        ]);

        $content = $this->normalizer->normalizeContent(
            ['zones' => ['a1' => ['ratio' => '16x9']]],
            $layout,
        );

        self::assertSame('1x1', $layout['zones'][0]['ratio']);
        self::assertArrayNotHasKey('ratio', $content['zones']['a1']);
    }

    // ── Stacks ────────────────────────────────────────────────────────────

    public function testAStackKeepsItsChildrenAndOtherZonesGetNone(): void
    {
        $layout = $this->normalizer->normalizeLayout([
            'zones' => [
                ['id' => 'left', 'type' => 'media'],
                ['id' => 'right', 'type' => 'stack', 'children' => [
                    ['id' => 'top', 'type' => 'media', 'span' => ['lg' => 24]],
                    ['id' => 'bottom', 'type' => 'media', 'span' => ['lg' => 24]],
                ]],
            ],
        ]);

        self::assertSame([], $layout['zones'][0]['children'], 'a media zone holds nothing');
        self::assertCount(2, $layout['zones'][1]['children']);
        self::assertSame(['top', 'bottom'], array_column($layout['zones'][1]['children'], 'id'));
    }

    /**
     * Depth stops at one. Nesting further turns a page into a layout tree,
     * where what a zone renders as can no longer be read off the list — and
     * every consumer of this shape would have to recurse without bound.
     */
    public function testAStackInsideAStackIsRefused(): void
    {
        $layout = $this->normalizer->normalizeLayout([
            'zones' => [
                ['id' => 'outer', 'type' => 'stack', 'children' => [
                    ['id' => 'inner', 'type' => 'stack', 'children' => [
                        ['id' => 'deep', 'type' => 'text'],
                    ]],
                    ['id' => 'ok', 'type' => 'text'],
                ]],
            ],
        ]);

        self::assertSame(
            ['ok'],
            array_column($layout['zones'][0]['children'], 'id'),
            'the nested stack is dropped, not flattened into a text zone',
        );
    }

    /**
     * Content is keyed by id in one flat map. Two zones sharing an id would
     * share their words in every language at once, so uniqueness has to hold
     * across the whole tree and not merely per level.
     */
    public function testIdsAreUniqueAcrossTheWholeTreeAndNotJustPerLevel(): void
    {
        $layout = $this->normalizer->normalizeLayout([
            'zones' => [
                ['id' => 'same', 'type' => 'text'],
                ['id' => 'holder', 'type' => 'stack', 'children' => [
                    ['id' => 'same', 'type' => 'text'],
                ]],
            ],
        ]);

        $ids = [
            $layout['zones'][0]['id'],
            $layout['zones'][1]['children'][0]['id'],
        ];

        self::assertSame('same', $ids[0]);
        self::assertNotSame($ids[0], $ids[1]);
    }

    public function testAStackedChildGetsItsOwnContentEntry(): void
    {
        $layout = $this->normalizer->normalizeLayout([
            'zones' => [
                ['id' => 'holder', 'type' => 'stack', 'children' => [
                    ['id' => 'child', 'type' => 'media'],
                ]],
            ],
        ]);

        $content = $this->normalizer->normalizeContent(
            ['zones' => ['child' => ['alt' => 'Vue depuis la treille']]],
            $layout,
        );

        self::assertArrayHasKey('holder', $content['zones']);
        self::assertSame('Vue depuis la treille', $content['zones']['child']['alt']);
    }

    public function testTheChildCountIsCapped(): void
    {
        $children = array_map(
            static fn (int $i): array => ['id' => 'c'.$i, 'type' => 'text'],
            range(1, 20),
        );

        $layout = $this->normalizer->normalizeLayout([
            'zones' => [['id' => 'holder', 'type' => 'stack', 'children' => $children]],
        ]);

        self::assertCount(6, $layout['zones'][0]['children']);
    }

    // ── Content ───────────────────────────────────────────────────────────

    public function testContentForAZoneThatIsGoneIsDropped(): void
    {
        $layout = $this->normalizer->normalizeLayout([
            'zones' => [['id' => 'a1', 'type' => 'text']],
        ]);

        $content = $this->normalizer->normalizeContent(
            ['zones' => [
                'a1' => ['blocks' => [['type' => 'paragraph', 'data' => []]]],
                'orphan' => ['blocks' => [['type' => 'paragraph', 'data' => []]]],
            ]],
            $layout,
        );

        self::assertArrayHasKey('a1', $content['zones']);
        self::assertArrayNotHasKey('orphan', $content['zones']);
    }

    public function testEveryZoneGetsAnEntryEvenWithNothingWritten(): void
    {
        $layout = $this->normalizer->normalizeLayout([
            'zones' => [['id' => 'a1', 'type' => 'text'], ['id' => 'a2', 'type' => 'video']],
        ]);

        $content = $this->normalizer->normalizeContent([], $layout);

        self::assertSame(['a1', 'a2'], array_keys($content['zones']));
        self::assertSame([], $content['zones']['a1']['blocks']);
        self::assertNull($content['zones']['a2']['url']);
    }

    /**
     * Blocks are the one thing written raw — Editor.js owns that shape and the
     * sanitiser runs at render, as it always has for `blocks`.
     */
    public function testTextBlocksAreKeptAsTheEditorWroteThem(): void
    {
        $blocks = [['type' => 'paragraph', 'data' => ['text' => 'Bonjour']]];

        $layout = $this->normalizer->normalizeLayout(['zones' => [['id' => 'a1', 'type' => 'text']]]);
        $content = $this->normalizer->normalizeContent(['zones' => ['a1' => ['blocks' => $blocks]]], $layout);

        self::assertSame($blocks, $content['zones']['a1']['blocks']);
    }

    /** Carrying blocks a video zone can never show is carrying dead weight. */
    public function testOnlyATextZoneKeepsItsBlocks(): void
    {
        $layout = $this->normalizer->normalizeLayout(['zones' => [['id' => 'a1', 'type' => 'video']]]);

        $content = $this->normalizer->normalizeContent(
            ['zones' => ['a1' => ['blocks' => [['type' => 'paragraph', 'data' => []]]]]],
            $layout,
        );

        self::assertSame([], $content['zones']['a1']['blocks']);
    }

    public function testAnEmptyLayoutIsAnAcceptableArgument(): void
    {
        self::assertSame(
            ['zones' => []],
            $this->normalizer->normalizeContent(['zones' => ['a' => []]], []),
        );
    }

    public function testNormalizingTwiceChangesNothing(): void
    {
        $layout = $this->normalizer->normalizeLayout([
            'enabled' => true,
            'snap' => 2,
            'zones' => [['id' => 'a1', 'type' => 'text', 'span' => ['lg' => 24]]],
        ]);

        self::assertSame($layout, $this->normalizer->normalizeLayout($layout));

        $content = $this->normalizer->normalizeContent(
            ['zones' => ['a1' => ['caption' => '  Espaces  ']]],
            $layout,
        );

        self::assertSame('Espaces', $content['zones']['a1']['caption'], 'trimmed on the way in');
        self::assertSame($content, $this->normalizer->normalizeContent($content, $layout));
    }
}
