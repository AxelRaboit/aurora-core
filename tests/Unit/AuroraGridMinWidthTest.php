<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function is_string;
use function preg_match;

/**
 * `.aurora-grid > *` must keep its `min-width: 0`.
 *
 * A grid item's `min-width` defaults to `auto`, which means it will not shrink
 * below its own content's minimum. On a 48-track grid a zone spanning four tracks
 * is a twelfth of the width - about 75px in the editor - and one long word, one
 * wide image or one unbroken URL inside it forces that track wider. Every other
 * track gives up its share, the grid overflows its container, and the page scrolls
 * sideways into empty space: the element responsible is inside a box that looks
 * perfectly normal, so there is nothing to see at the far end of the scroll.
 *
 * That is what happened on the post editor's content tab, and it happened on the
 * public article too - both use this class.
 *
 * Asserted statically, for the reason {@see AuroraGridGutterTest} is: the
 * Playwright suite runs in neither `make ft` nor CI, so an end-to-end check would
 * not stop the regression from being pushed. A one-line deletion here is the whole
 * failure mode, and it costs nothing to hold.
 */
final class AuroraGridMinWidthTest extends TestCase
{
    public function testGridItemsAreAllowedToShrink(): void
    {
        $css = file_get_contents(dirname(__DIR__, 2).'/src/Core/assets/css/base/aurora-grid.css');

        self::assertTrue(is_string($css), 'aurora-grid.css not found');

        // The rule body for `.aurora-grid > *`, up to its closing brace.
        self::assertSame(
            1,
            preg_match('/\.aurora-grid\s*>\s*\*\s*\{([^}]*)\}/', $css, $matches),
            'the `.aurora-grid > *` rule has moved or changed shape',
        );

        self::assertMatchesRegularExpression(
            '/min-width:\s*0\s*;/',
            $matches[1],
            '`.aurora-grid > *` lost its `min-width: 0`. Without it a grid item refuses '
            .'to shrink below its content, one long word in a narrow zone widens its '
            .'track, and the whole page scrolls sideways into empty space.',
        );
    }
}
