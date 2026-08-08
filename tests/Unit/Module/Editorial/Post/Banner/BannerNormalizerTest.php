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
        self::assertSame('50-50', $banner['ratio']);
        self::assertCount(2, $banner['slots']);
    }

    public function testBothSlotsAlwaysExistEvenWhenNoneWereSent(): void
    {
        $banner = $this->normalizer->normalize(['slots' => []]);

        self::assertCount(2, $banner['slots']);
        foreach ($banner['slots'] as $slot) {
            self::assertSame(BannerNormalizer::SLOT_NONE, $slot['type']);
        }
    }

    /**
     * Switching a slot from text to image in the editor must not throw the
     * typed text away — the front sends every key back on the next save.
     */
    public function testAnImageSlotKeepsTheTextItWasCarrying(): void
    {
        $banner = $this->normalizer->normalize([
            'slots' => [
                ['type' => 'image', 'mediaId' => 12, 'title' => 'Titre gardé'],
            ],
        ]);

        self::assertSame('image', $banner['slots'][0]['type']);
        self::assertSame(12, $banner['slots'][0]['mediaId']);
        self::assertSame('Titre gardé', $banner['slots'][0]['title']);
    }

    public function testUnknownEnumValuesFallBackInsteadOfPersisting(): void
    {
        $banner = $this->normalizer->normalize([
            'height' => 'gigantic',
            'ratio' => '80-20',
            'slots' => [['type' => 'video', 'align' => 'justify']],
        ]);

        self::assertSame('md', $banner['height']);
        self::assertSame('50-50', $banner['ratio']);
        self::assertSame('none', $banner['slots'][0]['type']);
        self::assertSame('start', $banner['slots'][0]['align']);
    }

    /**
     * Colours land in a `style` attribute, so this is the injection guard,
     * not a formatting nicety.
     */
    public function testOnlySixDigitHexColoursSurvive(): void
    {
        $banner = $this->normalizer->normalize([
            'background' => ['color' => '#AABBCC'],
            'slots' => [[
                'titleColor' => 'red; background: url(javascript:alert(1))',
                'descriptionColor' => '#abc',
            ]],
        ]);

        self::assertSame('#aabbcc', $banner['background']['color']);
        self::assertNull($banner['slots'][0]['titleColor']);
        self::assertNull($banner['slots'][0]['descriptionColor'], 'three-digit hex is not accepted');
    }

    public function testOverlayIsClampedToAPercentage(): void
    {
        self::assertSame(0, $this->normalizer->normalize(['background' => ['overlay' => -40]])['background']['overlay']);
        self::assertSame(100, $this->normalizer->normalize(['background' => ['overlay' => 900]])['background']['overlay']);
        self::assertSame(45, $this->normalizer->normalize(['background' => ['overlay' => '45']])['background']['overlay']);
    }

    public function testMediaIdsMustBePositiveIntegers(): void
    {
        $banner = $this->normalizer->normalize([
            'logoMediaId' => 0,
            'background' => ['mediaId' => -3],
            'slots' => [['mediaId' => '7']],
        ]);

        self::assertNull($banner['logoMediaId']);
        self::assertNull($banner['background']['mediaId']);
        self::assertSame(7, $banner['slots'][0]['mediaId'], 'a numeric string is accepted and cast');
    }

    public function testNormalizingTwiceChangesNothing(): void
    {
        $once = $this->normalizer->normalize([
            'enabled' => true,
            'height' => 'lg',
            'slots' => [['type' => 'text', 'title' => '  Espaces  ']],
        ]);

        self::assertSame($once, $this->normalizer->normalize($once));
    }
}
