<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Configuration\Setting;

use Aurora\Module\Configuration\Setting\Service\SuitePalette;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_combine;
use function array_keys;
use function array_map;
use function file_get_contents;
use function preg_match;
use function preg_quote;
use function sprintf;

/**
 * Les gris du back-office : une palette laissée au défaut ne doit rien
 * émettre, et ce n'est honnête que si le défaut est exactement `theme.css`.
 */
final class SuitePaletteTest extends TestCase
{
    public function testTheGrayFamilyIsWhatThemeCssHardCodes(): void
    {
        $palette = SuitePalette::normalize([]);

        self::assertSame($this->themeCssColors(':root'), $this->byVariable(SuitePalette::resolve($palette, 'light')));
        self::assertSame($this->themeCssColors('.dark'), $this->byVariable(SuitePalette::resolve($palette, 'dark')));
    }

    public function testAnUntouchedPaletteEmitsNothing(): void
    {
        self::assertSame('', SuitePalette::css(SuitePalette::fromStored('{}')));
        self::assertSame('', SuitePalette::css(SuitePalette::fromStored(null)));
        self::assertSame('', SuitePalette::css(SuitePalette::fromStored('not json')));
    }

    public function testOnlyTheChangedModeIsEmittedAndStaysInItsMode(): void
    {
        $css = SuitePalette::css(SuitePalette::normalize(['dark' => ['family' => 'slate']]));

        self::assertStringStartsWith(':root.dark{', $css);
        self::assertStringNotContainsString(':root:not(.dark)', $css);
        self::assertStringContainsString('--th-bg:#020617;', $css);
        self::assertStringContainsString('--th-surface:#0f172a;', $css);
    }

    public function testAnAdjustedColorWinsOverItsFamily(): void
    {
        $palette = SuitePalette::normalize(['light' => ['family' => 'gray', 'overrides' => ['bg' => '#EEF2FF']]]);

        self::assertSame('#eef2ff', SuitePalette::resolve($palette, 'light')['bg']);
        self::assertStringContainsString(':root:not(.dark){--th-bg:#eef2ff;', SuitePalette::css($palette));
    }

    public function testTheStateDefaultsAreWhatThemeCssHardCodes(): void
    {
        foreach (SuitePalette::STATE_TOKENS as [$variable, $light, $dark]) {
            self::assertSame($light, $this->themeCssColor(':root', $variable), $variable);
            self::assertSame($dark, $this->themeCssColor('.dark', $variable), $variable);
        }
    }

    public function testAStateColorBringsItsSoftBackgroundAndItsBadge(): void
    {
        $css = SuitePalette::css(SuitePalette::normalize(['light' => ['overrides' => ['danger' => '#dc2626']]]));

        self::assertStringContainsString('--th-danger:#dc2626;--th-danger-soft:#dc26261a;', $css);
        self::assertMatchesRegularExpression('/--th-badge-danger-bg:oklch\(0\.930 [^)]+\);--th-badge-danger-text:oklch\(0\.400 [^)]+\);/', $css);
        self::assertStringNotContainsString(':root.dark', $css);
    }

    public function testAnythingButAKnownFamilyAndAHexIsDropped(): void
    {
        $palette = SuitePalette::normalize([
            'light' => ['family' => 'red', 'overrides' => ['bg' => 'red;}body{display:none', 'unknown' => '#000000', 'line' => '#123456']],
        ]);

        self::assertSame(['family' => 'gray', 'overrides' => ['line' => '#123456']], $palette['light']);
    }

    public function testStorageRefusesWhatIsNotAnObject(): void
    {
        self::assertNull(SuitePalette::normalizeForStorage('nope'));
        self::assertNull(SuitePalette::normalizeForStorage('"slate"'));
        self::assertSame(
            '{"light":{"family":"stone","overrides":[]},"dark":{"family":"gray","overrides":[]}}',
            SuitePalette::normalizeForStorage('{"light":{"family":"stone"}}'),
        );
    }

    /**
     * @param array<string, string> $colors
     *
     * @return array<string, string>
     */
    private function byVariable(array $colors): array
    {
        $variables = array_map(static fn (string $token): string => SuitePalette::TOKENS[$token][0], array_keys($colors));

        return array_combine($variables, $colors);
    }

    private function themeCssColor(string $selector, string $variable): string
    {
        $css = (string) file_get_contents(__DIR__.'/../../../../../src/Core/assets/css/base/theme.css');
        preg_match('/^'.preg_quote($selector, '/').' \{(.*?)^\}/ms', $css, $block);
        preg_match('/'.preg_quote($variable, '/').':\s*rgb\(\s*(\d+)\s+(\d+)\s+(\d+)\s*\);/', $block[1], $match);

        return sprintf('#%02x%02x%02x', (int) $match[1], (int) $match[2], (int) $match[3]);
    }

    /**
     * Les variables neutres d'un bloc de `theme.css`, converties en hexadécimal.
     *
     * @return array<string, string>
     */
    private function themeCssColors(string $selector): array
    {
        $colors = [];
        foreach (array_column(SuitePalette::TOKENS, 0) as $variable) {
            $colors[$variable] = $this->themeCssColor($selector, $variable);
        }

        return $colors;
    }
}
