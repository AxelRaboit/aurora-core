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
 * A choice is never the browser's own `<select>`, in the back-office or on the
 * public site.
 *
 * The native menu follows neither the theme nor the font, and looks different
 * on every system: it was the one control that stood apart from the rest of the
 * screen. `AppSelect` used to render one, so every filter bar and form carried
 * it, and it was spotted by eye on a space's state filter. `AppSelect` now sits
 * on the house selector, and this test keeps a raw `<select>` from coming back
 * through another door.
 *
 * The public form was the one exception, kept for the phone's own wheel. It
 * stood apart from the site's theme just the same, and it now uses `AppSelect`
 * too, as its date field already used the house picker.
 */
final class SelectsAreNeverNativeTest extends TestCase
{
    private const string SOURCES = __DIR__.'/../../src';

    public function testNoComponentRendersANativeSelect(): void
    {
        $offenders = [];

        foreach ($this->components() as $file) {
            // An element opening a line, not the word quoted in a comment.
            preg_match_all('/^\s*<select[\s>]/m', (string) file_get_contents($file), $matches);

            if ([] !== $matches[0]) {
                $offenders[] = $this->relative($file);
            }
        }

        self::assertSame([], $offenders, sprintf(
            "These render the browser's own select, which follows neither the theme nor the font:\n%s\n"
            .'Use `AppSelect` for a single choice, `AppMultiselect` for several.',
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

            if (!str_contains($path, '/node_modules/') && str_ends_with($path, '.vue')) {
                yield $path;
            }
        }
    }

    private function relative(string $path): string
    {
        return (string) preg_replace('#^.*/src/#', 'src/', $path);
    }
}
