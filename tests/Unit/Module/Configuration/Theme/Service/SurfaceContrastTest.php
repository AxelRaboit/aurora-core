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
        self::assertSame('rgb(55 65 81)', $tokens['--color-border']);
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
