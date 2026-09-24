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
            [
                'id', 'anchor', 'type', 'span', 'offset', 'newRow', 'ratio', 'scale', 'align',
                'mediaId', 'mediaIds', 'mediaUrl', 'postId', 'variant', 'size', 'separatorStyle',
                'display', 'columns', 'items', 'taxonomyId', 'deckId', 'postTypeId', 'termId', 'limit',
                'cardVariant', 'formId', 'language', 'textSize', 'lineNumbers',
                'visibleFrom', 'visibleUntil', 'audience', 'exclusiveOpen',
                'surface', 'reveal', 'fullBleed', 'children',
            ],
            array_keys($zone),
            'switching a zone type in the editor must not lose what was picked',
        );
    }

    public function testAZoneArrivesWithoutAnEffectUnlessOneIsAsked(): void
    {
        $zone = $this->normalizer->normalizeLayout([
            'zones' => [['id' => 'a1', 'type' => 'text']],
        ])['zones'][0];

        self::assertSame('none', $zone['reveal'], 'a page where every zone moves is a page where nothing stands out');
    }

    public function testAnEffectIsKeptAndAnInventedOneFallsBackToNone(): void
    {
        $zones = $this->normalizer->normalizeLayout([
            'zones' => [
                ['id' => 'a1', 'type' => 'text', 'reveal' => 'blur'],
                ['id' => 'a2', 'type' => 'text', 'reveal' => 'explosion'],
            ],
        ])['zones'];

        self::assertSame('blur', $zones[0]['reveal']);
        self::assertSame(
            'none',
            $zones[1]['reveal'],
            'an effect the stylesheet has no rule for would leave the zone hidden for good',
        );
    }

    public function testAStackChildKeepsItsOwnEffect(): void
    {
        $stack = $this->normalizer->normalizeLayout([
            'zones' => [[
                'id' => 'a1',
                'type' => 'stack',
                'children' => [
                    ['id' => 'b1', 'type' => 'text', 'reveal' => 'left'],
                    ['id' => 'b2', 'type' => 'text', 'reveal' => 'right'],
                ],
            ]],
        ])['zones'][0];

        self::assertSame('left', $stack['children'][0]['reveal']);
        self::assertSame(
            'right',
            $stack['children'][1]['reveal'],
            'two halves meeting in the middle is the arrangement a stack exists to write',
        );
    }

    public function testAZoneThatSaysNothingIsFullWidthOnEveryBreakpoint(): void
    {
        $span = $this->normalizer->normalizeLayout([
            'zones' => [['id' => 'a1', 'type' => 'text']],
        ])['zones'][0]['span'];

        self::assertSame(48, $span['base']);
        self::assertSame(48, $span['md'], 'the phone rule writes the inherited width down');
        self::assertNull($span['lg'], 'and the step above goes on inheriting it');
    }

    public function testSpansAreClampedToTheGrid(): void
    {
        $span = $this->normalizer->normalizeLayout([
            'zones' => [['id' => 'a1', 'type' => 'text', 'span' => ['base' => 900, 'md' => 900, 'lg' => '24']]],
        ])['zones'][0]['span'];

        self::assertSame(48, $span['md']);
        self::assertSame(24, $span['lg'], 'a numeric string is accepted and cast');
    }

    /**
     * The clamp still applies to a width the phone rule does not touch: inside
     * a stack the same field is a share of the height, so there is no row to be
     * alone on and nothing to widen.
     */
    public function testAStackedShareIsStillClampedToTheGrid(): void
    {
        $span = $this->normalizer->normalizeLayout([
            'zones' => [[
                'id' => 's1',
                'type' => 'stack',
                'children' => [['id' => 'c1', 'type' => 'text', 'span' => ['base' => 0, 'md' => 900]]],
            ]],
        ])['zones'][0]['children'][0]['span'];

        self::assertSame(1, $span['base']);
        self::assertSame(48, $span['md']);
    }

    /**
     * A zone is alone on its row on a phone, whatever it stored.
     *
     * The width control has only ever written `span.lg`, but layouts built
     * before that - and client code writing the column itself - carry a real
     * `base`, and a page of photographs stored at half width came out two
     * across on a 390px screen.
     */
    public function testAZoneIsFullWidthOnAPhoneWhateverItStored(): void
    {
        $span = $this->normalizer->normalizeLayout([
            'zones' => [['id' => 'a1', 'type' => 'media', 'span' => ['base' => 24, 'md' => 12, 'lg' => 8]]],
        ])['zones'][0]['span'];

        self::assertSame(48, $span['base']);
        self::assertSame(12, $span['md'], 'the tablet keeps what it was given');
        self::assertSame(8, $span['lg'], 'and so does the desktop');
    }

    /**
     * Widening the phone must not widen what was leaning on it.
     *
     * A zone storing nothing but `base` used to be that width at every size.
     * Setting `base` to the full row on its own would have turned it into a
     * full-width zone on a desktop too - a redesign of somebody's page, not a
     * phone fix - so the old width is written into `md`, which `lg` goes on
     * inheriting exactly as it did.
     */
    public function testWideningThePhoneLeavesTheLargerScreensWhereTheyWere(): void
    {
        $span = $this->normalizer->normalizeLayout([
            'zones' => [['id' => 'a1', 'type' => 'media', 'span' => ['base' => 16]]],
        ])['zones'][0]['span'];

        self::assertSame(48, $span['base']);
        self::assertSame(16, $span['md']);
        self::assertNull($span['lg']);
    }

    /**
     * And the arrangement it produces is unchanged: `place()` reads the large
     * width, which still resolves to 16 through `md`.
     */
    public function testAPhoneWidthDoesNotMoveTheLargeScreenArrangement(): void
    {
        self::assertSame(
            [[1, 1], [1, 17], [1, 33]],
            $this->place([
                ['span' => ['base' => 16]],
                ['span' => ['base' => 16]],
                ['span' => ['base' => 16]],
            ]),
            'three sixteens still share one row on a large screen',
        );
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
     * to fill - it is stored all the same, because the arrangement is written
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
     * Cropping is design, and the design is written once - the same argument
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

    // ── Where a zone sits on its row ──────────────────────────────────────

    public function testAZoneFlowsAfterTheLastOneByDefault(): void
    {
        // Row and column, both 1-based like the properties they end up in: two
        // zones share the first row, and the third has nowhere left to go on it.
        self::assertSame(
            [[1, 1], [1, 25], [2, 1]],
            $this->placeWidths(24, 24, 24),
        );
    }

    /**
     * The first of the two arrangements the flow could not express: one zone,
     * at the right, with nothing to its left.
     */
    public function testAnOffsetPutsAZoneAtTheRightOfAnEmptyRow(): void
    {
        $places = $this->place([
            ['span' => ['lg' => 48]],
            ['span' => ['lg' => 24], 'offset' => 24],
        ]);

        self::assertSame([[1, 1], [2, 25]], $places);
    }

    /**
     * The second: two zones that would sit side by side, and should not. An
     * offset cannot say this - pushing the second one right leaves it on the
     * same row - so it takes a field of its own.
     */
    public function testANewRowDropsAZoneBelowOneItWouldFitBeside(): void
    {
        $places = $this->place([
            ['span' => ['lg' => 32]],
            ['span' => ['lg' => 16], 'newRow' => true],
        ]);

        self::assertSame([[1, 1], [2, 1]], $places);
    }

    /**
     * The case that made the row worth working out at all.
     *
     * Left to the browser's auto-placement - which was the first attempt - the
     * second zone was put beside the first: columns 33 to 48 were free there,
     * and a grid places an item with a definite column in the first row that
     * can take it. The break did nothing, silently.
     */
    public function testABreakHoldsEvenWhenTheColumnsAreFreeBeside(): void
    {
        $places = $this->place([
            ['span' => ['lg' => 32]],
            ['span' => ['lg' => 16], 'offset' => 32, 'newRow' => true],
        ]);

        self::assertSame([[1, 1], [2, 33]], $places);
    }

    public function testANewRowOnTheFirstZoneChangesNothing(): void
    {
        $places = $this->place([
            ['span' => ['lg' => 24], 'newRow' => true],
            ['span' => ['lg' => 24]],
        ]);

        self::assertSame([[1, 1], [1, 25]], $places, 'there is no row to break out of yet');
    }

    public function testAZoneAfterAnOffsetOneGoesOnFlowingFromIt(): void
    {
        $places = $this->place([
            ['span' => ['lg' => 12], 'offset' => 12],
            ['span' => ['lg' => 24]],
        ]);

        self::assertSame([[1, 13], [1, 25]], $places);
    }

    /**
     * A row fills from the left, so what is free on it is always its tail: a
     * column below the mark is taken, and the zone goes to the next row rather
     * than overlapping the neighbour that holds it.
     */
    public function testAnOffsetBehindWhatIsAlreadyPlacedGoesToTheNextRow(): void
    {
        $places = $this->place([
            ['span' => ['lg' => 32]],
            ['span' => ['lg' => 24], 'offset' => 12],
        ]);

        self::assertSame([[1, 1], [2, 13]], $places);
    }

    /**
     * An offset is bounded by what the zone's own width leaves. Clamped at the
     * write boundary, so {@see GridNormalizer::place()} can take an asked-for
     * column at face value.
     */
    public function testAnOffsetNeverPushesAZoneOffItsRow(): void
    {
        $layout = $this->normalizer->normalizeLayout([
            'zones' => [
                ['id' => 'a1', 'type' => 'text', 'span' => ['lg' => 24], 'offset' => 40],
                ['id' => 'a2', 'type' => 'text', 'span' => ['lg' => 48], 'offset' => 12],
                ['id' => 'a3', 'type' => 'text', 'span' => ['lg' => 24], 'offset' => -8],
            ],
        ]);

        self::assertSame(24, $layout['zones'][0]['offset'], 'half a row leaves half a row');
        self::assertSame(0, $layout['zones'][1]['offset'], 'a full-width zone has nowhere to be pushed to');
        self::assertSame(0, $layout['zones'][2]['offset']);
    }

    /**
     * Both are about a row, and a stack has none: its axis of flow is vertical
     * and its zones divide a height.
     */
    public function testAStackedZoneCarriesNoOffsetAndNoBreak(): void
    {
        $layout = $this->normalizer->normalizeLayout([
            'zones' => [[
                'id' => 's1',
                'type' => 'stack',
                'children' => [
                    ['id' => 'c1', 'type' => 'text', 'span' => ['lg' => 24], 'offset' => 12, 'newRow' => true],
                ],
            ]],
        ]);

        self::assertSame(0, $layout['zones'][0]['children'][0]['offset']);
        self::assertFalse($layout['zones'][0]['children'][0]['newRow']);
    }

    /**
     * @return list<array{int, int}>
     */
    private function placeWidths(int ...$widths): array
    {
        return $this->place(array_map(
            static fn (int $lg): array => ['span' => ['lg' => $lg]],
            $widths,
        ));
    }

    /**
     * Through the normaliser rather than straight into the walk, so the zones
     * carry the same clamps a saved layout does.
     *
     * @param list<array<string, mixed>> $zones
     *
     * @return list<array{int, int}>
     */
    private function place(array $zones): array
    {
        $prepared = [];

        foreach ($zones as $index => $zone) {
            $prepared[] = [...$zone, 'id' => 'z'.$index, 'type' => 'text'];
        }

        $layout = $this->normalizer->normalizeLayout(['zones' => $prepared]);

        return array_map(
            static fn (array $place): array => [$place['row'], $place['column']],
            GridNormalizer::place($layout['zones']),
        );
    }

    // ── How big a picture is printed ──────────────────────────────────────

    public function testAPictureMayBePrintedSmallerThanItsZone(): void
    {
        $layout = $this->normalizer->normalizeLayout([
            'zones' => [
                ['id' => 'a1', 'type' => 'media', 'scale' => 50],
                ['id' => 'a2', 'type' => 'media'],
            ],
        ]);

        self::assertSame(50, $layout['zones'][0]['scale']);
        self::assertSame(100, $layout['zones'][1]['scale'], 'a picture fills its zone unless told otherwise');
    }

    /**
     * A whitelist rather than any number, for the reason the width fractions
     * are one: these are the sizes anyone picks, and a page should not be able
     * to carry a picture printed at 3%.
     */
    public function testAnUnlistedSizeFallsBackToFullWidth(): void
    {
        $layout = $this->normalizer->normalizeLayout([
            'zones' => [
                ['id' => 'a1', 'type' => 'media', 'scale' => 37],
                ['id' => 'a2', 'type' => 'media', 'scale' => 0],
                ['id' => 'a3', 'type' => 'media', 'scale' => 'moitié'],
            ],
        ]);

        foreach ($layout['zones'] as $zone) {
            self::assertSame(100, $zone['scale']);
        }
    }

    public function testAPictureMayBeToldWhichSideToSitOn(): void
    {
        $layout = $this->normalizer->normalizeLayout([
            'zones' => [
                ['id' => 'a1', 'type' => 'media', 'scale' => 50, 'align' => 'right'],
                ['id' => 'a2', 'type' => 'media', 'scale' => 50, 'align' => 'sideways'],
            ],
        ]);

        self::assertSame('right', $layout['zones'][0]['align']);
        self::assertSame('center', $layout['zones'][1]['align'], 'what a smaller picture did before this was a choice');
    }

    // ── An address instead of a document ──────────────────────────────────

    public function testAZoneMayCarryAnImageAddress(): void
    {
        $layout = $this->normalizer->normalizeLayout([
            'zones' => [
                ['id' => 'a1', 'type' => 'media', 'mediaUrl' => 'https://picsum.photos/800/600'],
                ['id' => 'a2', 'type' => 'media', 'mediaUrl' => '/uploads/local.jpg'],
            ],
        ]);

        self::assertSame('https://picsum.photos/800/600', $layout['zones'][0]['mediaUrl']);
        self::assertSame('/uploads/local.jpg', $layout['zones'][1]['mediaUrl'], 'a path on this site is an address too');
    }

    /**
     * The value lands in an `src` the browser acts on, so the scheme whitelist
     * is the whole of the defence. `mailto:` and `tel:` pass the generic url
     * check and mean nothing in a picture, so they are refused here as well.
     */
    public function testAnImageAddressTheBrowserShouldNotFollowIsRefused(): void
    {
        foreach (['javascript:alert(1)', 'data:text/html,<script>', 'mailto:x@y.z', 'tel:+33', '#ancre'] as $bad) {
            $layout = $this->normalizer->normalizeLayout([
                'zones' => [['id' => 'a1', 'type' => 'media', 'mediaUrl' => $bad]],
            ]);

            self::assertNull($layout['zones'][0]['mediaUrl'], $bad);
        }
    }

    /**
     * Shared like the id beside it: it is the same picture in every language,
     * and describing it is what the translation carries.
     */
    public function testTheImageAddressIsSharedAndNotTranslated(): void
    {
        $layout = $this->normalizer->normalizeLayout([
            'zones' => [['id' => 'a1', 'type' => 'media', 'mediaUrl' => 'https://example.test/a.jpg']],
        ]);

        $content = $this->normalizer->normalizeContent(
            ['zones' => ['a1' => ['mediaUrl' => 'https://example.test/b.jpg']]],
            $layout,
        );

        self::assertSame('https://example.test/a.jpg', $layout['zones'][0]['mediaUrl']);
        self::assertArrayNotHasKey('mediaUrl', $content['zones']['a1']);
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
     * where what a zone renders as can no longer be read off the list - and
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
     * Blocks are the one thing written raw - Editor.js owns that shape and the
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

    // ── Listes d'entrées ──────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function itemsZone(array $items): array
    {
        return $this->normalizer->normalizeLayout([
            'zones' => [['id' => 'a1', 'type' => 'items', 'items' => $items]],
        ])['zones'][0];
    }

    /** A list is a list; past a dozen entries it is a page of its own. */
    public function testAnItemListIsCappedRatherThanTrusted(): void
    {
        $zone = $this->itemsZone(array_fill(0, 40, ['id' => null]));

        self::assertCount(12, $zone['items']);
    }

    /**
     * Two entries sharing an id would share one set of words, and editing
     * either would edit both. The payload comes from a browser, so the id is
     * regenerated rather than believed.
     */
    public function testDuplicateEntryIdsAreReplaced(): void
    {
        $zone = $this->itemsZone([['id' => 'same'], ['id' => 'same']]);

        self::assertNotSame($zone['items'][0]['id'], $zone['items'][1]['id']);
    }

    /** Only a list zone keeps entries: switching a type must not carry them. */
    public function testOnlyAListZoneHoldsEntries(): void
    {
        $zone = $this->normalizer->normalizeLayout([
            'zones' => [['id' => 'a1', 'type' => 'text', 'items' => [['id' => 'x']]]],
        ])['zones'][0];

        self::assertSame([], $zone['items']);
    }

    /**
     * The words are kept against the arrangement, so an entry removed from
     * the list takes its words with it instead of lingering unseen in every
     * translation.
     */
    public function testWordsWithoutAnEntryAreDropped(): void
    {
        $layout = $this->normalizer->normalizeLayout([
            'zones' => [['id' => 'a1', 'type' => 'items', 'items' => [['id' => 'kept']]]],
        ]);

        $content = $this->normalizer->normalizeContent([
            'zones' => [
                'a1' => [
                    'items' => [
                        'kept' => ['title' => 'Découverte'],
                        'gone' => ['title' => 'Une étape supprimée'],
                    ],
                ],
            ],
        ], $layout);

        self::assertSame(['kept'], array_keys($content['zones']['a1']['items']));
        self::assertSame('Découverte', $content['zones']['a1']['items']['kept']['title']);
    }

    /** An unknown display falls back rather than reaching the template unknown. */
    public function testAnUnknownDisplayFallsBack(): void
    {
        $zone = $this->normalizer->normalizeLayout([
            'zones' => [['id' => 'a1', 'type' => 'items', 'display' => 'carousel']],
        ])['zones'][0];

        self::assertSame('steps', $zone['display']);
    }

    /** Both halves of a button are translated, so both live on the content. */
    public function testAButtonKeepsItsLabelAndAddress(): void
    {
        $layout = $this->normalizer->normalizeLayout([
            'zones' => [['id' => 'a1', 'type' => 'button']],
        ]);

        $content = $this->normalizer->normalizeContent([
            'zones' => ['a1' => ['label' => 'Voir les services', 'url' => 'https://example.test/services']],
        ], $layout);

        self::assertSame('Voir les services', $content['zones']['a1']['label']);
        self::assertSame('https://example.test/services', $content['zones']['a1']['url']);
    }

    /**
     * Full bleed is drawn by pushing a viewport-wide box back by half its own
     * container, which only lands on the middle of the screen when the
     * container is the whole row. Left at two thirds, the band centred on the
     * middle of those two thirds and hung off to one side - which is what a
     * contact form did the first time one was set that way.
     */
    public function testAFullBleedZoneTakesTheWholeRow(): void
    {
        $zone = $this->normalizer->normalizeLayout([
            'zones' => [[
                'id' => 'a1',
                'type' => 'form',
                'fullBleed' => true,
                'span' => ['base' => 48, 'md' => 24, 'lg' => 32],
                'offset' => 8,
            ]],
        ])['zones'][0];

        self::assertSame(['base' => 48, 'md' => 48, 'lg' => 48], $zone['span']);
        self::assertSame(0, $zone['offset'], 'there is nothing to be offset from on a full row');
    }

    /** A zone that has not asked for the whole screen keeps the width it was given. */
    public function testAnOrdinaryZoneKeepsItsWidth(): void
    {
        $zone = $this->normalizer->normalizeLayout([
            'zones' => [[
                'id' => 'a1',
                'type' => 'form',
                'span' => ['base' => 48, 'md' => 24, 'lg' => 32],
                'offset' => 8,
            ]],
        ])['zones'][0];

        self::assertSame(['base' => 48, 'md' => 24, 'lg' => 32], $zone['span']);
        self::assertSame(8, $zone['offset']);
    }

    /**
     * The name lands in an `id` and then in every link that points at it, so
     * it is slugged rather than taken as typed: an accent would percent-encode
     * in the address bar, and a space would end the attribute.
     */
    public function testAnAnchorIsSlugged(): void
    {
        $zone = $this->normalizer->normalizeLayout([
            'zones' => [['id' => 'a1', 'type' => 'text', 'anchor' => 'Où me trouver ?']],
        ])['zones'][0];

        self::assertSame('ou-me-trouver', $zone['anchor']);
    }

    /**
     * A link points at one zone. Two zones answering to one name is a page
     * that behaves differently once a zone is moved, so the second claimant is
     * numbered - the same resolution an id collision gets.
     */
    public function testTwoZonesCannotShareAnAnchor(): void
    {
        $zones = $this->normalizer->normalizeLayout([
            'zones' => [
                ['id' => 'a1', 'type' => 'text', 'anchor' => 'contact'],
                ['id' => 'a2', 'type' => 'text', 'anchor' => 'contact'],
            ],
        ])['zones'];

        self::assertSame('contact', $zones[0]['anchor']);
        self::assertSame('contact-2', $zones[1]['anchor']);
    }

    /** Uniqueness reaches inside a stack: the page is one document of ids. */
    public function testAStackChildCannotStealAnAnchor(): void
    {
        $zones = $this->normalizer->normalizeLayout([
            'zones' => [
                ['id' => 'a1', 'type' => 'text', 'anchor' => 'contact'],
                ['id' => 'a2', 'type' => 'stack', 'children' => [
                    ['id' => 'a3', 'type' => 'text', 'anchor' => 'contact'],
                ]],
            ],
        ])['zones'];

        self::assertSame('contact-2', $zones[1]['children'][0]['anchor']);
    }

    /** No anchor is the normal case, and it has to stay empty rather than invented. */
    public function testAZoneWithNothingUsableHasNoAnchor(): void
    {
        $zones = $this->normalizer->normalizeLayout([
            'zones' => [
                ['id' => 'a1', 'type' => 'text'],
                ['id' => 'a2', 'type' => 'text', 'anchor' => '   '],
                ['id' => 'a3', 'type' => 'text', 'anchor' => '---'],
            ],
        ])['zones'];

        self::assertSame(['', '', ''], array_column($zones, 'anchor'));
    }
}
