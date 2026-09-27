<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Configuration\Theme\Service;

use Aurora\Module\Configuration\Theme\Service\SurfaceContrast;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ce service décide de la lisibilité d'une page publique à partir d'une couleur
 * choisie dans un écran d'administration. Une erreur ici ne casse rien : elle
 * rend un site illisible, ce que rien ne signale.
 */
final class SurfaceContrastTest extends TestCase
{
    private SurfaceContrast $contrast;

    protected function setUp(): void
    {
        $this->contrast = new SurfaceContrast();
    }

    public function testBlackOnWhiteIsTheMaximumRatio(): void
    {
        // La borne connue de WCAG : 21:1. Si ce calcul dérive, tout le reste suit.
        self::assertEqualsWithDelta(21.0, $this->contrast->ratio('#000000', '#ffffff'), 0.01);
    }

    public function testAColourAgainstItselfHasNoContrast(): void
    {
        self::assertEqualsWithDelta(1.0, $this->contrast->ratio('#3b82f6', '#3b82f6'), 0.001);
    }

    #[DataProvider('surfaces')]
    public function testTheTextColourFollowsTheBackground(string $hex, bool $expectsLightText, string $why): void
    {
        self::assertSame($expectsLightText, $this->contrast->needsLightText($hex), $why);
    }

    /** @return iterable<string, array{string, bool, string}> */
    public static function surfaces(): iterable
    {
        yield 'blanc' => ['#ffffff', false, 'le fond historique du frontend garde son texte sombre'];
        yield 'noir' => ['#000000', true, 'texte clair, évidemment'];
        yield 'bleu nuit' => ['#0f172a', true, 'les fonds sombres saturés appellent du clair'];
        yield 'jaune pâle' => ['#fef9c3', false, 'clair malgré la saturation'];
        yield 'rouge vif' => ['#dc2626', true, 'un seuil de luminance à 50 % se tromperait ici'];
        yield 'vert accent' => ['#10b981', false, 'le vert est lumineux : texte sombre'];
    }

    public function testAMidGreyIsFlaggedAsFailingAaa(): void
    {
        // Le ton moyen est le pire cas : le meilleur des deux textes y reste
        // au-dessus d'AA mais sous AAA. C'est ce que l'écran de thème signale.
        self::assertFalse($this->contrast->meetsAaa('#808080'));
    }

    public function testTheExtremesPassAaa(): void
    {
        self::assertTrue($this->contrast->meetsAaa('#ffffff'));
        self::assertTrue($this->contrast->meetsAaa('#000000'));
    }

    /**
     * L'invariant qui justifie de signaler AAA plutôt qu'AA : en retenant
     * toujours le meilleur du noir et du blanc, aucune couleur ne peut passer
     * sous le seuil AA. Balayage exhaustif des 256 gris, où se situe le pire cas.
     */
    public function testNoBackgroundCanEverFallBelowAa(): void
    {
        $worst = 21.0;
        for ($v = 0; $v <= 255; ++$v) {
            $worst = min($worst, $this->contrast->bestRatio(sprintf('#%02x%02x%02x', $v, $v, $v)));
        }

        self::assertGreaterThan(4.5, $worst, 'AA est tenu par construction');
        self::assertEqualsWithDelta(SurfaceContrast::GUARANTEED_FLOOR, $worst, 0.01);
    }

    public function testADarkSurfaceGetsTheWholeDarkTokenSet(): void
    {
        $tokens = $this->contrast->tokensFor('#0f172a');

        // Le point du service : pas seulement le texte fort, mais aussi les gris
        // intermédiaires et les bordures, sans quoi menus et séparateurs
        // disparaissent.
        self::assertSame('rgb(243 244 246)', $tokens['--th-primary']);
        self::assertSame('rgb(156 163 175)', $tokens['--th-secondary']);
        self::assertSame('oklch(0.451 0.040 265.755)', $tokens['--color-border']);
    }

    /**
     * The slate the dark set used to hard-code is `#030712` lifted by the
     * three steps: that background must keep its look.
     */
    public function testANavyBackgroundKeepsTheSlateItAlwaysHad(): void
    {
        $tokens = $this->contrast->tokensFor('#030712');

        self::assertSame('oklch(0.210 0.027 261.692)', $tokens['--th-surface']);
        self::assertSame('oklch(0.278 0.027 261.692)', $tokens['--th-surface-2']);
        self::assertSame('oklch(0.373 0.027 261.692)', $tokens['--th-surface-3']);
    }

    /** A card on a violet page is violet, not a blue-grey box. */
    public function testCardsTakeTheHueOfADarkBackground(): void
    {
        $tokens = $this->contrast->tokensFor('#130918');

        self::assertSame('oklch(0.243 0.034 313.749)', $tokens['--th-surface']);
        self::assertSame('oklch(0.311 0.034 313.749)', $tokens['--th-surface-2']);
        self::assertSame('oklch(0.406 0.034 313.749)', $tokens['--color-border']);
    }

    /** White cards over a light page already read as cards. */
    public function testALightBackgroundKeepsWhiteCards(): void
    {
        self::assertSame('rgb(255 255 255)', $this->contrast->tokensFor('#fef3c7')['--th-surface']);
    }

    /** An off-white text brings warm labels with it, not blue-grey ones. */
    public function testATextColourStepsItsGreysTowardTheBackground(): void
    {
        $tokens = $this->contrast->inkTokensFor('#130918', '#ece2d0', null);

        self::assertSame('#ece2d0', $tokens['--th-primary']);
        self::assertSame('oklch(0.663 0.026 82.383)', $tokens['--th-secondary']);
        self::assertSame('oklch(0.395 0.026 82.383)', $tokens['--th-subtle']);
        self::assertArrayNotHasKey('--color-border', $tokens);
    }

    /** A cream meant for dark pages must not turn a light footer's text to cream. */
    public function testATextColourUnreadableOnTheSurfaceIsIgnored(): void
    {
        self::assertSame([], $this->contrast->inkTokensFor('#fef3c7', '#ece2d0', null));
    }

    public function testALineColourBecomesTheBorderAndAStrongerOne(): void
    {
        $tokens = $this->contrast->inkTokensFor('#130918', null, '#3a2a55');

        self::assertSame('#3a2a55', $tokens['--color-border']);
        self::assertSame('oklch(0.398 0.076 299.336)', $tokens['--color-border-strong']);
        self::assertArrayNotHasKey('--th-primary', $tokens);
    }

    /** A card outline of its own, set apart from the page's lines. */
    public function testACardOutlineIsItsOwnToken(): void
    {
        $tokens = $this->contrast->inkTokensFor('#130918', null, '#ece2d0', '#6d58c4');

        self::assertSame('#ece2d0', $tokens['--color-border']);
        self::assertSame('#6d58c4', $tokens['--th-card-line']);
    }

    /** Unset, no token: cards fall back to the border in CSS. */
    public function testWithoutACardOutlineCardsFollowTheLines(): void
    {
        self::assertArrayNotHasKey('--th-card-line', $this->contrast->inkTokensFor('#130918', null, '#ece2d0'));
    }

    /** A card colour of its own keeps the raised levels the same steps apart. */
    public function testACardColourCarriesItsRaisedLevels(): void
    {
        $tokens = $this->contrast->cardTokensFor('#130918', '#241838');

        self::assertSame('#241838', $tokens['--th-surface']);
        self::assertStringStartsWith('oklch(', $tokens['--th-surface-2']);
        self::assertSame([], $this->contrast->cardTokensFor('#130918', null));
    }

    /** A zone forced to dark has no background to take a hue from. */
    public function testAForcedDarkSchemeKeepsTheFixedSet(): void
    {
        self::assertSame('rgb(17 24 39)', $this->contrast->tokensForScheme('dark')['--th-surface']);
    }

    public function testALightSurfaceGetsTheWholeLightTokenSet(): void
    {
        $tokens = $this->contrast->tokensFor('#fef9c3');

        self::assertSame('rgb(17 24 39)', $tokens['--th-primary']);
        self::assertSame('rgb(107 114 128)', $tokens['--th-secondary']);
        self::assertSame('rgb(229 231 235)', $tokens['--color-border']);
    }

    public function testBothSetsCoverTheSameTokens(): void
    {
        // Un jeu incomplet laisserait une variable à la valeur de l'autre thème,
        // et le défaut ne se verrait que sur la surface concernée.
        self::assertSame(
            array_keys($this->contrast->tokensFor('#ffffff')),
            array_keys($this->contrast->tokensFor('#000000')),
        );
    }

    #[DataProvider('malformed')]
    public function testAnUnreadableValueFallsBackToTheLightSet(string $hex): void
    {
        self::assertFalse($this->contrast->needsLightText($hex));
    }

    /** @return iterable<string, array{string}> */
    public static function malformed(): iterable
    {
        yield 'vide' => [''];
        yield 'pas hexadécimal' => ['#zzzzzz'];
        yield 'trop court' => ['#12'];
        yield 'mot' => ['rouge'];
    }

    public function testShortAndBareNotationsAreAccepted(): void
    {
        self::assertTrue($this->contrast->needsLightText('#000'));
        self::assertTrue($this->contrast->needsLightText('000000'));
        self::assertFalse($this->contrast->needsLightText('  #fff  '));
    }
}
