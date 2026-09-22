<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Studio\Deck;

use Aurora\Module\Studio\Deck\Service\DeckStyleNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function mb_strlen;
use function str_repeat;

/**
 * What a deck may say about its own appearance.
 *
 * The companion to `SlideContentWhitelistTest`, and it pins the same decision
 * one level up: the keys are declared in code and the payload is filtered
 * against them, so a form that posts something else changes nothing rather
 * than storing it.
 *
 * The case worth the most here is the absent key. An override that is not
 * written is what lets a theme keep reaching the deck, and a normalizer that
 * helpfully filled in the theme's own colour would freeze it silently.
 */
#[CoversClass(DeckStyleNormalizer::class)]
final class DeckStyleWhitelistTest extends TestCase
{
    public function testItKeepsOnlyTheKeysItDeclares(): void
    {
        $clean = (new DeckStyleNormalizer())->normalize([
            'accent' => '#ff8800',
            'fontPair' => 'serif',
            'shadow' => 'heavy',
            'slideWidth' => 1920,
        ]);

        self::assertSame(['accent' => '#ff8800', 'fontPair' => 'serif'], $clean);
    }

    public function testAGradientIsKeptOnlyWhenItDrawsSomething(): void
    {
        $normalizer = new DeckStyleNormalizer();

        self::assertSame(['gradient' => 'halo'], $normalizer->normalize(['gradient' => 'halo']));
        self::assertSame([], $normalizer->normalize(['gradient' => 'none']), 'the flat ground is the absence of the key');
        self::assertSame([], $normalizer->normalize(['gradient' => 'rainbow']));
    }

    public function testAPatternIsKeptOnlyWhenItDrawsSomething(): void
    {
        $normalizer = new DeckStyleNormalizer();

        self::assertSame(['pattern' => 'grid'], $normalizer->normalize(['pattern' => 'grid']));
        self::assertSame([], $normalizer->normalize(['pattern' => 'none']), 'the bare ground is the absence of the key');
        self::assertSame([], $normalizer->normalize(['pattern' => 'tartan']));
    }

    /** The wash and the texture are two answers to a bare ground, not one. */
    public function testAGradientAndAPatternLiveTogether(): void
    {
        $clean = (new DeckStyleNormalizer())->normalize(['gradient' => 'top', 'pattern' => 'dots']);

        self::assertSame(['gradient' => 'top', 'pattern' => 'dots'], $clean);
    }

    public function testTheMarginIsStoredEvenWhenItIsTheUsualOne(): void
    {
        $normalizer = new DeckStyleNormalizer();

        // Unlike a wash, a margin is not an effect somebody added: keeping the
        // usual one is a decision worth telling apart from never having looked.
        self::assertSame(['margins' => 'normal'], $normalizer->normalize(['margins' => 'normal']));
        self::assertSame(['margins' => 'wide'], $normalizer->normalize(['margins' => 'wide']));
        self::assertSame([], $normalizer->normalize(['margins' => 'none']));
    }

    public function testTheHairlineIsStoredOnlyWhenItIsOn(): void
    {
        $normalizer = new DeckStyleNormalizer();

        self::assertSame(['hairline' => true], $normalizer->normalize(['hairline' => true]));
        self::assertSame([], $normalizer->normalize(['hairline' => false]));
        self::assertSame([], $normalizer->normalize(['hairline' => 'on']));
    }

    public function testTheTitleCaseAndTheBulletShapeAreDeckWide(): void
    {
        $normalizer = new DeckStyleNormalizer();

        self::assertSame(
            ['titleCase' => 'upper', 'bullets' => 'arrow'],
            $normalizer->normalize(['titleCase' => 'upper', 'bullets' => 'arrow']),
        );

        self::assertSame([], $normalizer->normalize(['titleCase' => 'small-caps', 'bullets' => 'star']));
    }

    public function testAnUnsetColourIsNotWritten(): void
    {
        $clean = (new DeckStyleNormalizer())->normalize(['background' => null, 'ink' => '']);

        self::assertSame([], $clean, 'an absent override is what makes the theme reach the deck');
    }

    /**
     * The value lands in a CSS custom property, where a string that is not a
     * colour does not fail: it makes the property invalid and the slide falls
     * back to the theme's, which reads as a choice being ignored.
     */
    public function testAColourThatIsNotOneIsDropped(): void
    {
        $clean = (new DeckStyleNormalizer())->normalize([
            'background' => 'red; background-image: url(//evil)',
            'ink' => '#fff',
            'accent' => '#AABBCC',
        ]);

        self::assertSame(['accent' => '#aabbcc'], $clean);
    }

    /**
     * The transition is the one key written even at its default. `fade` is what
     * a deck does when nobody chose, so storing nothing for it would make
     * "I picked fade" and "I never looked at this" the same row - and the cut,
     * which is a real choice, would be indistinguishable from an old deck.
     */
    public function testTheTransitionIsKeptEvenWhenItIsTheDefault(): void
    {
        $normalizer = new DeckStyleNormalizer();

        self::assertSame(['transition' => 'fade'], $normalizer->normalize(['transition' => 'fade']));
        self::assertSame(['transition' => 'none'], $normalizer->normalize(['transition' => 'none']));
        self::assertSame([], $normalizer->normalize(['transition' => 'cube']));
    }

    public function testAnUnknownFontPairOrPlacementIsDropped(): void
    {
        $clean = (new DeckStyleNormalizer())->normalize([
            'fontPair' => 'comic',
            'logoPlacement' => 'behind',
        ]);

        self::assertSame([], $clean);
    }

    public function testTheLogoIdMustBeAnInteger(): void
    {
        $normalizer = new DeckStyleNormalizer();

        self::assertSame([], $normalizer->normalize(['logoMediaId' => '12']));
        self::assertSame([], $normalizer->normalize(['logoMediaId' => 0]));
        self::assertSame(['logoMediaId' => 12], $normalizer->normalize(['logoMediaId' => 12]));
    }

    public function testTheFooterIsTrimmedAndCapped(): void
    {
        $clean = (new DeckStyleNormalizer())->normalize([
            'footerText' => '  '.str_repeat('a', 200).'  ',
        ]);

        self::assertSame(120, mb_strlen($clean['footerText']));
    }

    public function testAnEmptyFooterIsNotWritten(): void
    {
        self::assertSame([], (new DeckStyleNormalizer())->normalize(['footerText' => '   ']));
    }

    /**
     * Absence already says "no numbers". A stored `false` would be a second way
     * to spell the same thing, and the two drift.
     */
    public function testSlideNumbersAreStoredOnlyWhenOn(): void
    {
        $normalizer = new DeckStyleNormalizer();

        self::assertSame([], $normalizer->normalize(['slideNumbers' => false]));
        self::assertSame([], $normalizer->normalize(['slideNumbers' => 'yes']));
        self::assertSame(['slideNumbers' => true], $normalizer->normalize(['slideNumbers' => true]));
    }
}
