<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function dirname;
use function in_array;
use function sprintf;

/**
 * `.aurora-grid` must never be given a column gap.
 *
 * The grid has 48 tracks, so `column-gap` is 47 gutters rather than one
 * between items. The banner shipped with `gap-8`: that is 47 × 2rem = 1504px
 * of gap inside a 1280px container, which collapsed every track to zero width
 * and pushed a full-width item 240px past the edge — a right-aligned button
 * ended up half outside the banner. Gutters come from item padding instead
 * (see css/base/aurora-grid.css).
 *
 * Asserted here rather than in a browser because the Playwright suite is not
 * part of `make ft` or CI, so an end-to-end check would not have stopped the
 * regression from being pushed. This is a static read of the markup, which
 * costs nothing and fails in the pipeline.
 *
 * `gap-y-*` is allowed: the row axis is unaffected — items wrap onto new rows
 * and want space between them.
 */
final class AuroraGridGutterTest extends TestCase
{
    private const string CLASS_ATTRIBUTE = '/class="([^"]*\baurora-grid\b[^"]*)"/';

    /**
     * Matches the utilities that set a column gap: `gap-*` (both axes) and
     * `gap-x-*`. Responsive and state prefixes count too — `md:gap-8` is the
     * same bug above 768px.
     */
    private const string COLUMN_GAP = '/(?:^|\s)(?:[a-z0-9-]+:)*gap(?:-x)?-(?!y-)[^\s"]+/';

    /**
     * @return iterable<string, array{string}>
     */
    public static function markupFiles(): iterable
    {
        $roots = [dirname(__DIR__, 2).'/src'];

        foreach ($roots as $root) {
            /** @var iterable<SplFileInfo> $files */
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if (!$file->isFile() || !in_array($file->getExtension(), ['twig', 'vue'], true)) {
                    continue;
                }

                $path = $file->getPathname();
                $contents = (string) file_get_contents($path);

                if (!str_contains($contents, 'aurora-grid')) {
                    continue;
                }

                yield str_replace(dirname(__DIR__, 2).'/', '', $path) => [$path];
            }
        }
    }

    #[DataProvider('markupFiles')]
    public function testNoElementGivesTheGridAColumnGap(string $path): void
    {
        $contents = (string) file_get_contents($path);

        preg_match_all(self::CLASS_ATTRIBUTE, $contents, $matches);

        $offenders = [];

        foreach ($matches[1] as $classList) {
            // Twig expressions inside the attribute are not utilities; only
            // the literal classes around them can carry a gap.
            $literal = (string) preg_replace('/\{\{.*?\}\}/s', ' ', $classList);

            if (1 === preg_match(self::COLUMN_GAP, $literal)) {
                $offenders[] = mb_trim($classList);
            }
        }

        // Collected and asserted once rather than asserted inside the loop: a
        // file can name `aurora-grid` in a comment and carry no such class at
        // all — `PostGridPanel.vue` does — and a loop that never runs is a test
        // that never asserts, which PHPUnit rightly calls risky.
        self::assertSame(
            [],
            $offenders,
            sprintf(
                '%s applies a column gap to .aurora-grid. '
                .'On 48 tracks that is 47 gutters and the last item overflows. '
                .'Use gap-y-* and let item padding space the columns.',
                $path,
            ),
        );
    }

    public function testTheGridItselfNeutralisesTheColumnGap(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/src/Core/assets/css/base/aurora-grid.css');

        self::assertMatchesRegularExpression(
            '/\.aurora-grid\s*\{[^}]*column-gap:\s*0/',
            $css,
            'aurora-grid.css must set column-gap: 0, so a caller that forgets does not inherit a browser default.',
        );

        self::assertMatchesRegularExpression(
            '/\.aurora-grid\s*>\s*\*\s*\{[^}]*padding-inline:\s*var\(--aurora-gutter/',
            $css,
            'the gutter has to come from item padding, or items touch.',
        );
    }

    /**
     * Item padding insets the two outer edges as well, which is invisible
     * inside a box that has padding of its own and very visible in the article
     * flow: the content grid sat a gutter to the right of the title above it.
     *
     * The modifier cancels exactly that, from the same variable — two numbers
     * that have to match are two numbers that will stop matching.
     */
    public function testTheFlushModifierCancelsExactlyTheGutter(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/src/Core/assets/css/base/aurora-grid.css');

        self::assertMatchesRegularExpression(
            '/\.aurora-grid-flush\s*\{[^}]*margin-inline:\s*calc\(var\(--aurora-gutter[^)]*\)\s*\*\s*-1\)/',
            $css,
            'without this the grid no longer lines up with what it sits under.',
        );
    }
}
