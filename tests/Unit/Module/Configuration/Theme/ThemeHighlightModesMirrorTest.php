<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Configuration\Theme;

use Aurora\Module\Configuration\Theme\Service\ThemeContext;
use Aurora\Module\Editorial\Post\Grid\GridNormalizer;
use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function is_string;
use function preg_match;
use function preg_match_all;

/**
 * The colour modes of hovers and of the topbar marker, held on both sides.
 *
 * The theme screen and the page's Appearance tab offer them from
 * `highlightModes.js`; the server keeps them in `ThemeContext::HIGHLIGHTS`,
 * and a grid zone adds "inherit" in front. A mode added on one side only
 * would be offered and ignored, or accepted and never offered.
 */
final class ThemeHighlightModesMirrorTest extends TestCase
{
    public function testTheScreensOfferExactlyTheModesTheServerKeeps(): void
    {
        $source = file_get_contents(dirname(__DIR__, 5).'/src/Module/Configuration/assets/backend/themes/highlightModes.js');
        self::assertTrue(is_string($source));
        self::assertSame(1, preg_match('/export const HIGHLIGHT_MODES = \[(.*?)\];/s', $source, $matches));
        preg_match_all('/"([^"]+)"/', $matches[1], $modes);

        self::assertSame(ThemeContext::HIGHLIGHTS, $modes[1]);
    }

    public function testAZoneOffersTheSameModesAfterInherit(): void
    {
        self::assertSame(['inherit', ...ThemeContext::HIGHLIGHTS], GridNormalizer::ZONE_HIGHLIGHTS);
    }
}
