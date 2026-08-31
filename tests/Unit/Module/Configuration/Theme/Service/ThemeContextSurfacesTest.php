<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Configuration\Theme\Service;

use Aurora\Module\Configuration\Theme\Entity\ThemeInterface;
use Aurora\Module\Configuration\Theme\Repository\ThemeRepository;
use Aurora\Module\Configuration\Theme\Service\PrimaryColorPalette;
use Aurora\Module\Configuration\Theme\Service\SurfaceContrast;
use Aurora\Module\Configuration\Theme\Service\ThemeContext;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\Document\Service\DocumentUrlGenerator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Le CSS produit ici est la seule chose qui sépare une couleur choisie dans le
 * backend d'une page publique illisible. Ce qui se vérifie : qu'une surface non
 * configurée n'émette rien, et qu'une surface configurée emporte avec elle tout
 * son jeu de jetons plutôt que le seul fond.
 */
#[AllowMockObjectsWithoutExpectations]
final class ThemeContextSurfacesTest extends TestCase
{
    /** @param array<string, mixed> $config */
    private function contextWithConfig(array $config): ThemeContext
    {
        $theme = $this->createMock(ThemeInterface::class);
        $theme->method('getConfig')->willReturn($config);

        $repository = $this->createMock(ThemeRepository::class);
        $repository->method('findActive')->willReturn($theme);

        return new ThemeContext(
            $repository,
            $this->createMock(DocumentRepository::class),
            // Les deux suivants sont `final` donc non doublables, et la méthode
            // testée ne les touche pas : de vraies instances font l'affaire.
            new PrimaryColorPalette(),
            new SurfaceContrast(),
            new DocumentUrlGenerator($this->createMock(UrlGeneratorInterface::class)),
        );
    }

    public function testAThemeWithoutColoursEmitsNothing(): void
    {
        // L'apparence historique reste le défaut, sans valeur à maintenir.
        self::assertSame('', $this->contextWithConfig([])->frontendSurfacesCss());
    }

    public function testABlankColourIsTreatedAsUnset(): void
    {
        self::assertSame('', $this->contextWithConfig(['background_color' => '   '])->frontendSurfacesCss());
    }

    public function testTheBackgroundRuleTargetsThePage(): void
    {
        $css = $this->contextWithConfig(['background_color' => '#0f172a'])->frontendSurfacesCss();

        self::assertStringStartsWith('html[data-theme]{', $css);
        self::assertStringContainsString('--th-bg: #0f172a;', $css);
    }

    public function testADarkSurfaceCarriesItsWholeTokenSetNotJustTheBackground(): void
    {
        $css = $this->contextWithConfig(['background_color' => '#0f172a'])->frontendSurfacesCss();

        // Sans ces trois-là, les libellés de menu et les séparateurs
        // disparaîtraient sur le fond sombre sans que rien ne le signale.
        self::assertStringContainsString('--th-primary: rgb(243 244 246);', $css);
        self::assertStringContainsString('--th-secondary: rgb(156 163 175);', $css);
        self::assertStringContainsString('--color-border: rgb(55 65 81);', $css);
    }

    public function testALightSurfaceKeepsDarkText(): void
    {
        $css = $this->contextWithConfig(['background_color' => '#fef9c3'])->frontendSurfacesCss();

        self::assertStringContainsString('--th-primary: rgb(17 24 39);', $css);
    }

    public function testTheHeaderRuleIsScopedAndRedefinesTheDropdownBackground(): void
    {
        $css = $this->contextWithConfig(['header_color' => '#111827'])->frontendSurfacesCss();

        self::assertStringContainsString('html[data-theme] .aurora-surface-header{', $css);
        // --th-surface-bg peint la barre, --th-bg suit les panneaux de menu
        // déroulant, qui sont rendus en `bg-bg`.
        self::assertStringContainsString('--th-surface-bg: #111827;', $css);
        self::assertStringContainsString('--th-bg: #111827;', $css);
    }

    public function testEachSurfaceIsDecidedOnItsOwn(): void
    {
        // Une topbar sombre sur une page claire : les deux règles coexistent et
        // ne portent pas le même jeu de texte.
        $css = $this->contextWithConfig([
            'background_color' => '#ffffff',
            'header_color' => '#0f172a',
        ])->frontendSurfacesCss();

        [$page, $header] = explode('html[data-theme] .aurora-surface-header{', $css);

        self::assertStringContainsString('--th-primary: rgb(17 24 39);', $page);
        self::assertStringContainsString('--th-primary: rgb(243 244 246);', $header);
    }

    public function testAllThreeSurfacesCanBeSetTogether(): void
    {
        $css = $this->contextWithConfig([
            'background_color' => '#ffffff',
            'header_color' => '#0f172a',
            'footer_color' => '#1f2937',
        ])->frontendSurfacesCss();

        self::assertStringContainsString('.aurora-surface-header{', $css);
        self::assertStringContainsString('.aurora-surface-footer{', $css);
        self::assertSame(3, mb_substr_count($css, '--th-primary'));
    }

    public function testAnUnrelatedConfigKeyIsIgnored(): void
    {
        // `config` porte aussi primary_color, le logo, la largeur de contenu.
        $css = $this->contextWithConfig([
            'primary_color' => '#10b981',
            'content_width' => 'wide',
        ])->frontendSurfacesCss();

        self::assertSame('', $css);
    }

    // ── Surcharges de la page en cours de rendu ───────────────────────────────

    public function testAnOverridePaintsASurfaceTheThemeLeftUnset(): void
    {
        $css = $this->contextWithConfig([])->frontendSurfacesCss(['header_color' => '#0f172a']);

        self::assertStringContainsString('html[data-theme] .aurora-surface-header{', $css);
        self::assertStringContainsString('--th-surface-bg: #0f172a;', $css);
    }

    public function testAnOverrideWinsOverTheThemeOnThatSurface(): void
    {
        $css = $this->contextWithConfig(['header_color' => '#ffffff'])
            ->frontendSurfacesCss(['header_color' => '#0f172a']);

        self::assertStringContainsString('--th-surface-bg: #0f172a;', $css);
        self::assertStringNotContainsString('#ffffff', $css);
    }

    public function testANullOverrideLeavesTheThemeStanding(): void
    {
        // Le contrat du champ : vide veut dire « hérite », pas « éteins ».
        $css = $this->contextWithConfig(['header_color' => '#0f172a'])
            ->frontendSurfacesCss(['header_color' => null]);

        self::assertStringContainsString('--th-surface-bg: #0f172a;', $css);
    }

    public function testABlankOverrideLeavesTheThemeStandingToo(): void
    {
        // Le vide n'est pas un choix, même arrivé sous forme de chaîne : sans
        // ça une publication effacerait la couleur du thème sans le demander.
        $css = $this->contextWithConfig(['header_color' => '#0f172a'])
            ->frontendSurfacesCss(['header_color' => '   ']);

        self::assertStringContainsString('--th-surface-bg: #0f172a;', $css);
    }

    public function testOverridingOneSurfaceLeavesTheOthersToTheTheme(): void
    {
        // Une publication qui ne choisit que sa topbar garde le fond du thème,
        // ce qui est ce qui rend la surcharge par surface utilisable.
        $css = $this->contextWithConfig([
            'background_color' => '#ffffff',
            'footer_color' => '#1f2937',
        ])->frontendSurfacesCss(['header_color' => '#0f172a']);

        self::assertStringContainsString('html[data-theme]{', $css);
        self::assertStringContainsString('.aurora-surface-header{', $css);
        self::assertStringContainsString('.aurora-surface-footer{', $css);
        self::assertSame(3, mb_substr_count($css, '--th-primary'));
    }

    public function testAnOverriddenSurfaceCarriesItsContrastTokens(): void
    {
        // Le contraste est ce qui distingue « repeindre » de « rendre
        // illisible » : la surcharge doit passer par le même calcul.
        $css = $this->contextWithConfig([])->frontendSurfacesCss(['background_color' => '#0f172a']);

        self::assertStringContainsString('--th-primary: rgb(243 244 246);', $css);
    }

    public function testNoOverrideBehavesExactlyAsBefore(): void
    {
        $withEmpty = $this->contextWithConfig(['background_color' => '#fef9c3'])->frontendSurfacesCss([]);
        $withNone = $this->contextWithConfig(['background_color' => '#fef9c3'])->frontendSurfacesCss();

        self::assertSame($withNone, $withEmpty);
    }
}
