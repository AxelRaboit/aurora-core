<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function file_get_contents;
use function implode;
use function preg_match_all;
use function preg_replace;
use function sprintf;
use function str_contains;
use function str_ends_with;

/**
 * A checkbox in the suite is `AppCheckbox`.
 *
 * Tables drew their selection with a raw `<input type="checkbox">`, square,
 * next to forms whose boxes were rounded: two drawings of the same control on
 * the same screen (UI audit of 07/10/2026). The toggle components themselves
 * are where the raw input lives, and the visitor's screens (`frontend/`,
 * `public/`) follow the site's theme rather than the suite's.
 */
final class CheckboxesAreTheHouseOnesTest extends TestCase
{
    private const string SOURCES = __DIR__.'/../../src';

    public function testTheSuiteDrawsOneCheckbox(): void
    {
        $offenders = [];

        foreach ($this->components() as $file) {
            preg_match_all('/<input[^>]*type="checkbox"/', (string) file_get_contents($file), $matches);

            if ([] !== $matches[0]) {
                $offenders[] = $this->relative($file);
            }
        }

        self::assertSame([], $offenders, sprintf(
            "These draw their own checkbox instead of the suite's:\n%s\n"
            .'Use `AppCheckbox` (with `ariaLabel` in a table row, `indeterminate` for « select all »).',
            implode("\n", $offenders),
        ));
    }

    /**
     * @return iterable<string>
     */
    private function components(): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::SOURCES, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = $file->getPathname();

            if (str_contains($path, '/node_modules/') || !str_ends_with($path, '.vue')) {
                continue;
            }

            if (str_contains($path, '/components/form/toggle/') || str_contains($path, '/frontend/') || str_contains($path, '/public/')) {
                continue;
            }

            yield $path;
        }
    }

    private function relative(string $path): string
    {
        return (string) preg_replace('#^.*/src/#', 'src/', $path);
    }
}
