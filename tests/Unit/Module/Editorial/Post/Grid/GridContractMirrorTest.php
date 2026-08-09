<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\Post\Grid;

use Aurora\Module\Editorial\Post\Grid\GridNormalizer;
use Aurora\Tests\Unit\AuroraGridGutterTest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function count;
use function dirname;
use function is_string;
use function sprintf;

/**
 * The grid's vocabulary is written twice, and these two copies must agree.
 *
 * `GridNormalizer` is the write boundary: it decides which zone types exist,
 * which shapes a picture may be cropped to, how small it may be printed. The
 * editor's canvas cannot ask the server on every keystroke, so `usePostGrid.js`
 * carries the same lists — each one marked `Mirrors GridNormalizer::…`.
 *
 * **A comment is not a constraint.** Adding a ratio on one side and forgetting
 * the other breaks nothing loudly: the editor simply stops offering it, or
 * offers one the server silently refuses on save. Nothing fails, and nobody
 * finds out until an author does.
 *
 * Read statically, in the pipeline, for the reason {@see AuroraGridGutterTest}
 * is: the Playwright suite is not part of `make ft` or CI, so an end-to-end
 * check would not stop the drift from being pushed. This costs nothing and
 * fails where it is noticed.
 *
 * Deliberately not covered here: `clampOffset` and `place`, which are behaviour
 * rather than vocabulary. They are mirrored too, and each side has its own
 * tests asserting the same cases — see `GridNormalizerTest` and
 * `usePostGrid.test.js`. Comparing two implementations statically would mean
 * parsing them, which is a worse bargain than the tests already written.
 */
final class GridContractMirrorTest extends TestCase
{
    /**
     * @return iterable<string, array{list<int|string>, string}>
     */
    public static function mirrors(): iterable
    {
        yield 'snaps' => [GridNormalizer::SNAPS, 'SNAPS'];
        yield 'ratios' => [GridNormalizer::RATIOS, 'ZONE_RATIOS'];
        yield 'scales' => [GridNormalizer::SCALES, 'ZONE_SCALES'];
        yield 'alignments' => [GridNormalizer::ALIGNMENTS, 'ZONE_ALIGNMENTS'];
        yield 'zone types' => [GridNormalizer::ZONE_TYPES, 'ZONE_TYPES'];
    }

    /**
     * @param list<int|string> $expected
     */
    #[DataProvider('mirrors')]
    public function testTheEditorOffersExactlyWhatTheServerAccepts(array $expected, string $constant): void
    {
        self::assertSame(
            $expected,
            $this->jsList($constant),
            sprintf(
                'GridNormalizer and usePostGrid.js disagree about %s. '
                .'Whichever side was changed, change the other — the editor '
                .'offers what this list says, and the server keeps what its own says.',
                $constant,
            ),
        );
    }

    /**
     * `ZONE_TYPES` is spread from `LEAF_ZONE_TYPES` on the JS side, so it is
     * read through the same parser rather than being special-cased: what
     * matters is the value the editor ends up with.
     */
    public function testAStackIsTopLevelOnBothSides(): void
    {
        $leaves = $this->jsList('LEAF_ZONE_TYPES');

        self::assertNotContains(GridNormalizer::ZONE_STACK, $leaves);
        self::assertCount(count(GridNormalizer::ZONE_TYPES) - 1, $leaves);
    }

    public function testTheStackCapIsTheSameOnBothSides(): void
    {
        self::assertSame(
            GridNormalizer::MAX_STACK_CHILDREN,
            $this->jsNumber('MAX_STACK_CHILDREN'),
        );
        self::assertSame(48, $this->jsNumber('COLUMNS'));
    }

    /**
     * The named width fractions have no PHP counterpart — they are an editing
     * convenience, not a stored value — but every one of them must be a whole
     * number of columns the normaliser will keep, or a button lies.
     */
    public function testEveryNamedFractionIsAWidthTheServerKeeps(): void
    {
        $source = $this->source();

        self::assertSame(
            1,
            preg_match('/export const WIDTH_FRACTIONS = \[(.*?)\];/s', $source, $matches),
            'WIDTH_FRACTIONS has moved or changed shape',
        );

        self::assertGreaterThan(0, preg_match_all('/columns: (\d+)/', $matches[1], $found));

        foreach ($found[1] as $columns) {
            $width = (int) $columns;

            self::assertGreaterThan(0, $width);
            self::assertLessThanOrEqual(GridNormalizer::COLUMNS, $width);
            self::assertSame(0, $width % GridNormalizer::SNAPS[0], sprintf(
                '%d columns is not reachable at the default snap, so the button would round to something else',
                $width,
            ));
        }
    }

    /**
     * @return list<int|string>
     */
    private function jsList(string $constant): array
    {
        $pattern = sprintf('/export const %s = \[(.*?)\];/s', preg_quote($constant, '/'));

        self::assertSame(1, preg_match($pattern, $this->source(), $matches), sprintf(
            '%s is not exported from usePostGrid.js under that name any more',
            $constant,
        ));

        preg_match_all('/"([^"]+)"|(\d+)|\.\.\.([A-Z_]+)/', $matches[1], $items, PREG_SET_ORDER);

        $values = [];

        foreach ($items as $item) {
            if ('' !== ($item[1] ?? '')) {
                $values[] = $item[1];

                continue;
            }

            if ('' !== ($item[2] ?? '')) {
                $values[] = (int) $item[2];

                continue;
            }

            // A spread of another list, which the editor flattens at load —
            // so this reader flattens it too rather than reporting `...FOO`.
            foreach ($this->jsList($item[3]) as $spread) {
                $values[] = $spread;
            }
        }

        return $values;
    }

    private function jsNumber(string $constant): int
    {
        $pattern = sprintf('/const %s = (\d+);/', preg_quote($constant, '/'));

        self::assertSame(1, preg_match($pattern, $this->source(), $matches), sprintf(
            '%s is not declared in usePostGrid.js any more',
            $constant,
        ));

        return (int) $matches[1];
    }

    private function source(): string
    {
        $path = dirname(__DIR__, 6)
            .'/src/Module/Editorial/assets/backend/posts/composables/usePostGrid.js';

        $source = file_get_contents($path);

        self::assertTrue(is_string($source), sprintf('usePostGrid.js not found at %s', $path));

        return $source;
    }
}
