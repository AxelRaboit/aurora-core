<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\Post\Banner;

use Aurora\Module\Editorial\Post\Banner\BannerNormalizer;
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
        $this->normalizer = new BannerNormalizer();
    }

    public function testGarbageInputStillProducesAUsableBanner(): void
    {
        $banner = $this->normalizer->normalize('not an array at all');

        self::assertFalse($banner['enabled']);
        self::assertSame('md', $banner['height']);
        self::assertSame([], $banner['items']);
    }

    public function testAnItemOfUnknownTypeIsDroppedRatherThanDefaulted(): void
    {
        $banner = $this->normalizer->normalize([
            'items' => [
                ['type' => 'text', 'title' => 'Gardé'],
                ['type' => 'video'],
                'not even an array',
            ],
        ]);

        self::assertCount(1, $banner['items']);
        self::assertSame('Gardé', $banner['items'][0]['title']);
    }

    /**
     * Switching an item from text to image in the editor must not throw the
     * typed text away — the front sends every key back on the next save.
     */
    public function testAnImageItemKeepsTheTextItWasCarrying(): void
    {
        $banner = $this->normalizer->normalize([
            'items' => [['type' => 'image', 'mediaId' => 12, 'title' => 'Titre gardé']],
        ]);

        self::assertSame('image', $banner['items'][0]['type']);
        self::assertSame(12, $banner['items'][0]['mediaId']);
        self::assertSame('Titre gardé', $banner['items'][0]['title']);
    }

    public function testTheItemCountIsCapped(): void
    {
        $banner = $this->normalizer->normalize([
            'items' => array_fill(0, 30, ['type' => 'text']),
        ]);

        self::assertCount(6, $banner['items']);
    }

    public function testAnItemThatSaysNothingIsFullWidthOnEveryBreakpoint(): void
    {
        $span = $this->normalizer->normalize(['items' => [['type' => 'text']]])['items'][0]['span'];

        self::assertSame(48, $span['base'], 'full width on a phone');
        self::assertNull($span['md'], 'absent steps inherit the one below');
        self::assertNull($span['lg']);
    }

    public function testSpansAreClampedToTheGrid(): void
    {
        $span = $this->normalizer->normalize([
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
        $banner = $this->normalizer->normalize([
            'ratio' => '33-67',
            'slots' => [
                ['type' => 'text', 'title' => 'Gauche'],
                ['type' => 'image', 'mediaId' => 7],
            ],
        ]);

        self::assertCount(2, $banner['items']);
        self::assertSame('Gauche', $banner['items'][0]['title']);
        self::assertSame(16, $banner['items'][0]['span']['lg'], 'the old ratio becomes a width in columns');
        self::assertSame(32, $banner['items'][1]['span']['lg']);
    }

    public function testALegacyEmptySlotIsDroppedAndTheSurvivorSpansTheRow(): void
    {
        $banner = $this->normalizer->normalize([
            'slots' => [
                ['type' => 'text', 'title' => 'Seul'],
                ['type' => 'none'],
            ],
        ]);

        self::assertCount(1, $banner['items']);
        self::assertSame(48, $banner['items'][0]['span']['lg']);
    }

    public function testUnknownEnumValuesFallBackInsteadOfPersisting(): void
    {
        $banner = $this->normalizer->normalize([
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
        $banner = $this->normalizer->normalize([
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
        self::assertSame('none', $this->normalizer->normalize([])['background']['type']);
        self::assertSame(
            'none',
            $this->normalizer->normalize(['background' => ['type' => 'radial']])['background']['type'],
            'an unknown fill is refused rather than persisted',
        );
    }

    public function testGradientStopsAreColoursAndTheAngleIsClamped(): void
    {
        $background = $this->normalizer->normalize([
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
        $background = $this->normalizer->normalize([
            'background' => ['color' => '#0f172a', 'overlay' => 0],
        ])['background'];

        self::assertSame('solid', $background['type']);
    }

    public function testAnExplicitNoneIsHonouredEvenWithAColourStillStored(): void
    {
        $background = $this->normalizer->normalize([
            'background' => ['type' => 'none', 'color' => '#0f172a'],
        ])['background'];

        self::assertSame('none', $background['type'], 'the upgrade only applies when no type was written at all');
    }

    public function testOverlayIsClampedToAPercentage(): void
    {
        self::assertSame(0, $this->normalizer->normalize(['background' => ['overlay' => -40]])['background']['overlay']);
        self::assertSame(100, $this->normalizer->normalize(['background' => ['overlay' => 900]])['background']['overlay']);
    }

    public function testNormalizingTwiceChangesNothing(): void
    {
        $once = $this->normalizer->normalize([
            'enabled' => true,
            'height' => 'lg',
            'items' => [['type' => 'text', 'title' => '  Espaces  ', 'span' => ['lg' => 24]]],
        ]);

        self::assertSame($once, $this->normalizer->normalize($once));
    }
}
