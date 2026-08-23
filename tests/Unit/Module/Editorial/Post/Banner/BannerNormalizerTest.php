<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\Post\Banner;

use Aurora\Core\Content\ContentValueNormalizer;
use Aurora\Module\Editorial\Post\Banner\BannerNormalizer;
use DoctrineMigrations\Version20260809210000;
use PHPUnit\Framework\TestCase;

/**
 * The normaliser is the only thing standing between a request body and a JSON
 * column, so these tests are written from the attacker's side as much as the
 * author's: what happens to a value nobody sanctioned.
 */
final class BannerNormalizerTest extends TestCase
{
    private BannerNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new BannerNormalizer(new ContentValueNormalizer());
    }

    public function testGarbageInputStillProducesAUsableBanner(): void
    {
        $banner = $this->normalizer->normalizeLayout('not an array at all');

        self::assertFalse($banner['enabled']);
        self::assertSame('md', $banner['height']);
        self::assertSame([], $banner['items']);
    }

    public function testAnItemOfUnknownTypeIsDroppedRatherThanDefaulted(): void
    {
        $layout = $this->normalizer->normalizeLayout([
            'items' => [
                ['type' => 'text'],
                ['type' => 'video'],
                'not even an array',
            ],
        ]);

        self::assertCount(1, $layout['items']);
        self::assertSame('text', $layout['items'][0]['type']);
    }

    /**
     * Switching an item from text to image in the editor must not throw the
     * typed text away - the item keeps its id, and the words hang off that.
     */
    public function testAnImageItemKeepsTheTextItWasCarrying(): void
    {
        $layout = $this->normalizer->normalizeLayout([
            'items' => [['id' => 'a1', 'type' => 'image', 'mediaId' => 12]],
        ]);

        $texts = $this->normalizer->normalizeTexts(
            ['items' => ['a1' => ['title' => 'Titre gardé']]],
            $layout,
        );

        self::assertSame('image', $layout['items'][0]['type']);
        self::assertSame(12, $layout['items'][0]['mediaId']);
        self::assertSame('Titre gardé', $texts['items']['a1']['title']);
    }

    public function testTheItemCountIsCapped(): void
    {
        $banner = $this->normalizer->normalizeLayout([
            'items' => array_fill(0, 30, ['type' => 'text']),
        ]);

        self::assertCount(6, $banner['items']);
    }

    public function testAnItemThatSaysNothingIsFullWidthOnEveryBreakpoint(): void
    {
        $span = $this->normalizer->normalizeLayout(['items' => [['type' => 'text']]])['items'][0]['span'];

        self::assertSame(48, $span['base'], 'full width on a phone');
        self::assertNull($span['md'], 'absent steps inherit the one below');
        self::assertNull($span['lg']);
    }

    public function testSpansAreClampedToTheGrid(): void
    {
        $span = $this->normalizer->normalizeLayout([
            'items' => [['type' => 'text', 'span' => ['base' => 0, 'md' => 900, 'lg' => '24']]],
        ])['items'][0]['span'];

        self::assertSame(1, $span['base']);
        self::assertSame(48, $span['md']);
        self::assertSame(24, $span['lg'], 'a numeric string is accepted and cast');
    }

    /**
     * Banners written against the fixed two-slot shape must survive the move
     * to a free list, or every existing header silently empties.
     */
    public function testLegacySlotsBecomeItems(): void
    {
        $layout = $this->normalizer->normalizeLayout([
            'ratio' => '33-67',
            'slots' => [
                ['type' => 'text', 'title' => 'Gauche'],
                ['type' => 'image', 'mediaId' => 7],
            ],
        ]);

        self::assertCount(2, $layout['items']);
        self::assertSame(16, $layout['items'][0]['span']['lg'], 'the old ratio becomes a width in columns');
        self::assertSame(32, $layout['items'][1]['span']['lg']);
    }

    public function testALegacyEmptySlotIsDroppedAndTheSurvivorSpansTheRow(): void
    {
        $layout = $this->normalizer->normalizeLayout([
            'slots' => [
                ['type' => 'text', 'title' => 'Seul'],
                ['type' => 'none'],
            ],
        ]);

        self::assertCount(1, $layout['items']);
        self::assertSame(48, $layout['items'][0]['span']['lg']);
    }

    /**
     * A button's link lands in an `href`, so the whitelist is the guard. These
     * are the cases a blocklist would have let through.
     */
    public function testOnlyKnownSafeLinkSchemesSurvive(): void
    {
        $layout = $this->normalizer->normalizeLayout([
            'items' => [['id' => 'b1', 'type' => 'button']],
        ]);

        $url = fn (string $value): ?string => $this->normalizer->normalizeTexts(
            ['items' => ['b1' => ['url' => $value]]],
            $layout,
        )['items']['b1']['url'];

        self::assertSame('/fr/contact', $url('/fr/contact'));
        self::assertSame('https://example.org', $url('https://example.org'));
        self::assertSame('mailto:a@b.c', $url('mailto:a@b.c'));
        self::assertSame('#ancre', $url('#ancre'));

        self::assertNull($url('javascript:alert(1)'));
        self::assertNull($url('JaVaScRiPt:alert(1)'), 'the check is case-insensitive');
        self::assertNull($url('data:text/html,<script>'));
        self::assertNull($url('ftp://example.org'));
    }

    public function testAButtonIsAFirstClassItemType(): void
    {
        $layout = $this->normalizer->normalizeLayout([
            'items' => [['id' => 'b1', 'type' => 'button']],
        ]);

        $texts = $this->normalizer->normalizeTexts(
            ['items' => ['b1' => ['label' => 'Découvrir', 'url' => '/fr/a-propos']]],
            $layout,
        );

        self::assertSame('button', $layout['items'][0]['type']);
        self::assertSame('Découvrir', $texts['items']['b1']['label']);
        self::assertSame('/fr/a-propos', $texts['items']['b1']['url']);
    }

    public function testTitleSizeAndVerticalAlignmentAreWhitelisted(): void
    {
        $banner = $this->normalizer->normalizeLayout([
            'verticalAlign' => 'bottom',
            'items' => [['type' => 'text', 'titleSize' => 'enormous']],
        ]);

        self::assertSame('center', $banner['verticalAlign'], 'unknown alignment falls back to centred');
        self::assertSame('md', $banner['items'][0]['titleSize']);
    }

    public function testUnknownEnumValuesFallBackInsteadOfPersisting(): void
    {
        $banner = $this->normalizer->normalizeLayout([
            'height' => 'gigantic',
            'items' => [['type' => 'text', 'align' => 'justify']],
        ]);

        self::assertSame('md', $banner['height']);
        self::assertSame('start', $banner['items'][0]['align']);
    }

    /**
     * Colours land in a `style` attribute, so this is the injection guard,
     * not a formatting nicety.
     */
    public function testOnlySixDigitHexColoursSurvive(): void
    {
        $banner = $this->normalizer->normalizeLayout([
            'background' => ['color' => '#AABBCC'],
            'items' => [[
                'type' => 'text',
                'titleColor' => 'red; background: url(javascript:alert(1))',
                'descriptionColor' => '#abc',
            ]],
        ]);

        self::assertSame('#aabbcc', $banner['background']['color']);
        self::assertNull($banner['items'][0]['titleColor']);
        self::assertNull($banner['items'][0]['descriptionColor'], 'three-digit hex is not accepted');
    }

    public function testTheFillTypeIsWhitelistedAndDefaultsToNone(): void
    {
        self::assertSame('none', $this->normalizer->normalizeLayout([])['background']['type']);
        self::assertSame(
            'none',
            $this->normalizer->normalizeLayout(['background' => ['type' => 'radial']])['background']['type'],
            'an unknown fill is refused rather than persisted',
        );
    }

    public function testGradientStopsAreColoursAndTheAngleIsClamped(): void
    {
        $background = $this->normalizer->normalizeLayout([
            'background' => [
                'type' => 'gradient',
                'gradientFrom' => '#112233',
                'gradientTo' => 'rgb(0,0,0)',
                'gradientAngle' => 900,
            ],
        ])['background'];

        self::assertSame('#112233', $background['gradientFrom']);
        self::assertNull($background['gradientTo'], 'only hex is accepted, as for every other colour');
        self::assertSame(360, $background['gradientAngle']);
    }

    /**
     * Banners written before the fill type existed carry a colour and no type.
     * Reading those as "no fill" would strip a background someone chose.
     */
    public function testALegacyBannerWithAColourAndNoTypeReadsAsSolid(): void
    {
        $background = $this->normalizer->normalizeLayout([
            'background' => ['color' => '#0f172a', 'overlay' => 0],
        ])['background'];

        self::assertSame('solid', $background['type']);
    }

    public function testAnExplicitNoneIsHonouredEvenWithAColourStillStored(): void
    {
        $background = $this->normalizer->normalizeLayout([
            'background' => ['type' => 'none', 'color' => '#0f172a'],
        ])['background'];

        self::assertSame('none', $background['type'], 'the upgrade only applies when no type was written at all');
    }

    public function testOverlayIsClampedToAPercentage(): void
    {
        self::assertSame(0, $this->normalizer->normalizeLayout(['background' => ['overlay' => -40]])['background']['overlay']);
        self::assertSame(100, $this->normalizer->normalizeLayout(['background' => ['overlay' => 900]])['background']['overlay']);
    }

    // ── The split itself ──────────────────────────────────────────────────

    public function testTheLayoutHoldsNoWordsAndTheTextsHoldNoDesign(): void
    {
        $layout = $this->normalizer->normalizeLayout([
            'items' => [['id' => 'a1', 'type' => 'text', 'title' => 'Passé en fraude', 'titleSize' => 'xl']],
        ]);

        // A title sent inside the layout is dropped, not stored: two places
        // holding the same word is how the two halves would start to disagree.
        self::assertArrayNotHasKey('title', $layout['items'][0]);
        self::assertSame('xl', $layout['items'][0]['titleSize']);

        $texts = $this->normalizer->normalizeTexts(
            ['items' => ['a1' => ['title' => 'Bonjour', 'titleSize' => 'sm']]],
            $layout,
        );

        self::assertSame('Bonjour', $texts['items']['a1']['title']);
        self::assertArrayNotHasKey('titleSize', $texts['items']['a1']);
    }

    public function testAnItemWithNoIdIsGivenOneRatherThanDropped(): void
    {
        $layout = $this->normalizer->normalizeLayout([
            'items' => [['type' => 'text'], ['type' => 'image']],
        ]);

        self::assertSame('i1', $layout['items'][0]['id']);
        self::assertSame('i2', $layout['items'][1]['id']);
    }

    /**
     * Two items sharing an id would share one translation's words. The second
     * claim loses, and gets an id of its own.
     */
    public function testTwoItemsCannotShareAnId(): void
    {
        $layout = $this->normalizer->normalizeLayout([
            'items' => [['id' => 'dup', 'type' => 'text'], ['id' => 'dup', 'type' => 'image']],
        ]);

        self::assertSame('dup', $layout['items'][0]['id']);
        self::assertNotSame('dup', $layout['items'][1]['id']);
    }

    public function testAnIdThatIsNotSafeAsAKeyIsReplaced(): void
    {
        $layout = $this->normalizer->normalizeLayout([
            'items' => [['id' => '../../etc/passwd', 'type' => 'text']],
        ]);

        self::assertSame('i1', $layout['items'][0]['id']);
    }

    /**
     * Text for an item the layout no longer has would sit in every language
     * with nothing able to show or clear it.
     */
    public function testTextForAnItemThatIsGoneIsDropped(): void
    {
        $layout = $this->normalizer->normalizeLayout([
            'items' => [['id' => 'a1', 'type' => 'text']],
        ]);

        $texts = $this->normalizer->normalizeTexts(
            ['items' => ['a1' => ['title' => 'Gardé'], 'orphan' => ['title' => 'Perdu']]],
            $layout,
        );

        self::assertArrayHasKey('a1', $texts['items']);
        self::assertArrayNotHasKey('orphan', $texts['items']);
    }

    public function testEveryLayoutItemGetsAnEntryEvenWithNothingWritten(): void
    {
        $layout = $this->normalizer->normalizeLayout([
            'items' => [['id' => 'a1', 'type' => 'text'], ['id' => 'a2', 'type' => 'button']],
        ]);

        $texts = $this->normalizer->normalizeTexts([], $layout);

        self::assertSame(['a1', 'a2'], array_keys($texts['items']));
        self::assertSame('', $texts['items']['a1']['title']);
        self::assertNull($texts['items']['a2']['url']);
    }

    /**
     * A banner stored whole, before the split. Its texts are positional and
     * the layout was built from the same list, so position is how they line
     * up - a database restored from an older dump must not lose its copy.
     */
    public function testAPreSplitBannerStillYieldsItsTexts(): void
    {
        $stored = [
            'enabled' => true,
            'items' => [
                ['type' => 'text', 'title' => 'Bienvenue', 'description' => 'Sous-titre'],
                ['type' => 'button', 'label' => 'Découvrir', 'url' => '/fr/a-propos'],
            ],
        ];

        $layout = $this->normalizer->normalizeLayout($stored);
        $texts = $this->normalizer->normalizeTexts($stored, $layout);

        self::assertSame('Bienvenue', $texts['items']['i1']['title']);
        self::assertSame('Sous-titre', $texts['items']['i1']['description']);
        self::assertSame('Découvrir', $texts['items']['i2']['label']);
        self::assertSame('/fr/a-propos', $texts['items']['i2']['url']);
    }

    public function testAnEmptyLayoutIsAnAcceptableArgument(): void
    {
        self::assertSame(['items' => []], $this->normalizer->normalizeTexts(['items' => ['a' => []]], []));
    }

    public function testNormalizingTwiceChangesNothing(): void
    {
        $once = $this->normalizer->normalizeLayout([
            'enabled' => true,
            'height' => 'lg',
            'items' => [['id' => 'a1', 'type' => 'text', 'span' => ['lg' => 24]]],
        ]);

        self::assertSame($once, $this->normalizer->normalizeLayout($once));

        $texts = $this->normalizer->normalizeTexts(
            ['items' => ['a1' => ['title' => '  Espaces  ']]],
            $once,
        );

        self::assertSame('Espaces', $texts['items']['a1']['title'], 'trimmed on the way in');
        self::assertSame($texts, $this->normalizer->normalizeTexts($texts, $once));
    }

    /**
     * The retired full-bleed placement folds into its neighbour, not into the
     * default.
     *
     * `oneOf` answers `contained` for a value it no longer knows, and that
     * would move a banner from full width into the article column - a larger
     * change than the one being made, on a page nobody edited.
     * {@see Version20260809210000} rewrites what was in
     * the database; this is what catches the rest, since a revision restored
     * afterwards or an old payload posted by a client both arrive here still
     * saying `full`.
     */
    public function testTheRetiredFullBleedBecomesTheAlignedFullWidth(): void
    {
        $layout = $this->normalizer->normalizeLayout([
            'enabled' => true,
            'width' => BannerNormalizer::WIDTH_FULL_RETIRED,
        ]);

        self::assertSame(BannerNormalizer::WIDTH_FULL_ALIGNED, $layout['width']);
    }

    public function testAnUnknownPlacementStillFallsBackToTheColumn(): void
    {
        $layout = $this->normalizer->normalizeLayout(['enabled' => true, 'width' => 'edge-to-edge']);

        self::assertSame(BannerNormalizer::WIDTH_CONTAINED, $layout['width']);
    }

    public function testTheTwoPlacementsThatRemainAreKept(): void
    {
        foreach ([BannerNormalizer::WIDTH_CONTAINED, BannerNormalizer::WIDTH_FULL_ALIGNED] as $width) {
            $layout = $this->normalizer->normalizeLayout(['enabled' => true, 'width' => $width]);

            self::assertSame($width, $layout['width']);
        }
    }
}
