<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function file_get_contents;
use function implode;
use function in_array;
use function preg_match_all;
use function preg_replace;
use function sprintf;
use function str_contains;
use function str_ends_with;

/**
 * A choice in the back-office is never the browser's own `<select>`.
 *
 * The native menu follows neither the theme nor the font, and looks different
 * on every system: it was the one control that stood apart from the rest of the
 * screen. `AppSelect` used to render one, so every filter bar and form carried
 * it, and it was spotted by eye on a space's state filter. `AppSelect` now sits
 * on the house selector, and this test keeps a raw `<select>` from coming back
 * through another door.
 */
final class SelectsAreNeverNativeTest extends TestCase
{
    private const string SOURCES = __DIR__.'/../../src';

    /**
     * Where a native select is deliberate, and why.
     *
     * The public form is filled in by a visitor, on the site's theme rather than
     * the back-office's, and often on a phone, where the operating system's own
     * wheel beats any menu we ship for somebody who has never seen this
     * interface.
     *
     * @var list<string>
     */
    private const array DELIBERATE_NATIVE_SELECTS = [
        'src/Module/Editorial/assets/frontend/FormRender.vue',
    ];

    public function testNoComponentRendersANativeSelect(): void
    {
        $offenders = [];

        foreach ($this->components() as $file) {
            if (in_array($this->relative($file), self::DELIBERATE_NATIVE_SELECTS, true)) {
                continue;
            }

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
