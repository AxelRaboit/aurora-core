<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Configuration\Setting;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Configuration\Setting\Service\EmailColors;
use Aurora\Module\Configuration\Theme\Entity\ThemeInterface;
use Aurora\Module\Configuration\Theme\Repository\ThemeRepository;
use Aurora\Module\Configuration\Theme\Service\PrimaryColorPalette;
use Aurora\Module\Configuration\Theme\Service\SurfaceContrast;
use Aurora\Module\Configuration\Theme\Service\ThemeContext;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\Document\Service\DocumentUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function file_get_contents;
use function preg_quote;

/**
 * The e-mail colors: at the default a message goes out as before, and
 * anything that is not a hexadecimal does not get into its HTML.
 */
final class EmailColorsTest extends TestCase
{
    public function testTheDefaultsAreTheColoursEmailCssHardCodes(): void
    {
        $css = (string) file_get_contents(__DIR__.'/../../../../../src/Core/assets/css/email.css');

        self::assertMatchesRegularExpression('/\.button-primary \{ background-color: '.preg_quote(ApplicationParameterEnum::EmailAccentColor->getDefaultValue(), '/').';/', $css);
        self::assertMatchesRegularExpression('/\.wrapper \{\s*background-color: '.preg_quote(ApplicationParameterEnum::EmailBackgroundColor->getDefaultValue(), '/').';/', $css);
        self::assertMatchesRegularExpression('/h1 \{\s*color: '.preg_quote(ApplicationParameterEnum::EmailHeadingColor->getDefaultValue(), '/').';/', $css);
        self::assertMatchesRegularExpression('/body \{[^}]*\bcolor: '.preg_quote(ApplicationParameterEnum::EmailTextColor->getDefaultValue(), '/').';/', $css);
    }

    public function testUntouchedSettingsAddNoRule(): void
    {
        $colors = $this->colorsWith([]);

        self::assertSame('', $colors->css());
        self::assertSame('#10b981', $colors->colors()['accentLight']);
    }

    public function testTheAccentFollowsTheThemesMainColour(): void
    {
        $colors = $this->colorsWith([], ['primary_color' => '#8B6CFF']);

        self::assertSame('#8b6cff', $colors->colors()['accent']);
        self::assertStringContainsString('.button-primary{background-color:#8b6cff}', $colors->css());
    }

    public function testAThemeWithoutMainColourKeepsTheOriginalGreen(): void
    {
        self::assertSame('#059669', $this->colorsWith([], ['primary_color' => 'violet'])->colors()['accent']);
        self::assertSame('#059669', $this->colorsWith([], null)->colors()['accent']);
    }

    public function testAChangedAccentRepaintsTheButtonAndTheLinks(): void
    {
        $colors = $this->colorsWith([
            ApplicationParameterEnum::EmailAccentFollowsTheme->value => '0',
            ApplicationParameterEnum::EmailAccentColor->value => '#8B6CFF',
        ], ['primary_color' => '#ff0000']);

        self::assertSame('a,.header a,.fallback a{color:#8b6cff}.button-primary{background-color:#8b6cff}.panel{border-left-color:#8b6cff}', $colors->css());
        self::assertSame('#a891ff', $colors->colors()['accentLight']);
    }

    public function testAValueThatIsNotHexFallsBackToTheDefault(): void
    {
        $colors = $this->colorsWith([ApplicationParameterEnum::EmailBackgroundColor->value => 'red}</style>']);

        self::assertSame('', $colors->css());
        self::assertSame('#f5f3ff', $colors->colors()['background']);
    }

    /**
     * @param array<string, string>     $stored
     * @param array<string, mixed>|null $themeConfig null = no active theme
     */
    private function colorsWith(array $stored, ?array $themeConfig = []): EmailColors
    {
        $repository = $this->createStub(SettingRepository::class);
        $repository->method('get')->willReturnCallback(
            static fn (string $key, ?string $default = null): ?string => $stored[$key] ?? $default,
        );

        $theme = null;
        if (null !== $themeConfig) {
            $theme = $this->createStub(ThemeInterface::class);
            $theme->method('getConfig')->willReturn($themeConfig);
        }

        $themes = $this->createStub(ThemeRepository::class);
        $themes->method('findActive')->willReturn($theme);

        return new EmailColors($repository, new ThemeContext(
            $themes,
            $this->createStub(DocumentRepository::class),
            new PrimaryColorPalette(),
            new SurfaceContrast(),
            new DocumentUrlGenerator($this->createStub(UrlGeneratorInterface::class)),
        ));
    }
}
