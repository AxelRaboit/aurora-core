<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit;

use Aurora\Module\Configuration\Theme\Service\ThemeContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The default accent colour is written in PHP and copied into the theme screens.
 *
 * PHP owns it: `ThemeContext::primaryColor()` is what actually paints the
 * application when a theme has none configured. The two theme composables need
 * the same value at module load, to show a swatch and to reset a field, and
 * they had their own copies.
 *
 * Which drifted the day the default moved from indigo to green: the whole
 * application went green while the theme editor still offered `#6366f1` as the
 * "accent colour", and the list still drew an indigo swatch. Nothing was
 * broken, so nothing said anything.
 *
 * Per `convention_mirrored_contract_php_js`: the duplication is allowed, a test
 * holds it rather than a comment.
 */
final class ThemeDefaultColourMirrorTest extends TestCase
{
    private const string EDIT_JS = __DIR__.'/../../src/Module/Configuration/assets/backend/themes/composables/useThemesEdit.js';

    private const string LIST_JS = __DIR__.'/../../src/Module/Configuration/assets/backend/themes/composables/useThemesList.js';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function sources(): iterable
    {
        yield 'the edit form default' => [self::EDIT_JS, '/const DEFAULT_PRIMARY_COLOR = "(#[0-9a-f]{6})";/'];
        yield 'the edit form swatch' => [self::EDIT_JS, '/"--th-accent": "(#[0-9a-f]{6})",/'];
        yield 'the list fallback' => [self::LIST_JS, '/"--th-accent"\]\s*\?\?\s*(?:\/\/[^\n]*\n\s*)?"(#[0-9a-f]{6})"/'];
    }

    #[DataProvider('sources')]
    public function testTheJsCopyMatchesThePhpConstant(string $path, string $pattern): void
    {
        $js = file_get_contents($path);
        self::assertIsString($js, sprintf('%s could not be read.', basename($path)));

        self::assertSame(
            1,
            preg_match($pattern, $js, $matches),
            sprintf('The colour could not be found in %s - if it moved, this test has to follow it rather than be deleted.', basename($path)),
        );

        self::assertSame(
            ThemeContext::DEFAULT_PRIMARY_COLOR,
            $matches[1],
            'The default accent colour drifted between PHP and the theme screens.',
        );
    }
}
