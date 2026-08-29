<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * A panel that is a drawer on mobile must drop its z-index on desktop.
 *
 * The backend page header is `lg:sticky lg:z-20`, and a page container marked
 * `relative` with no z-index of its own creates no stacking context - so a
 * child carrying `z-40` competes with that header directly and wins. Scrolling
 * then draws the panel over the breadcrumb.
 *
 * The pattern is legitimate below the breakpoint, where the panel really is a
 * drawer floating over the page. It is never needed above it, where the panel
 * sits in normal flow. `md:z-auto` (or `lg:`) is the whole fix.
 *
 * Transient overlays - dropdowns, modals, tooltips, backdrops - are exempt:
 * they are meant to cover the page, header included, for as long as they are
 * open.
 */
final class StickyHeaderStackingTest extends TestCase
{
    private const string ASSETS_DIR = __DIR__.'/../../src/Module';

    /** Markers of something meant to float over the page for a moment. */
    private const array TRANSIENT = ['Modal', 'Tooltip', 'Picker', 'FloatingMenu', 'Dropdown'];

    public function testADrawerPanelReleasesItsZIndexOnDesktop(): void
    {
        $offenders = [];

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::ASSETS_DIR));
        foreach ($files as $file) {
            if (!$file->isFile() || 'vue' !== $file->getExtension()) {
                continue;
            }

            $name = $file->getFilename();
            foreach (self::TRANSIENT as $marker) {
                if (str_contains($name, $marker)) {
                    continue 2;
                }
            }

            $source = file_get_contents($file->getPathname());
            if (false === $source) {
                continue;
            }

            foreach (explode("\n", $source) as $number => $line) {
                // A responsive panel: it stops being fixed/absolute above a
                // breakpoint, which is what makes it a drawer rather than an
                // overlay.
                if (!preg_match('/\bz-[34][0-9]\b/', $line)) {
                    continue;
                }
                if (!preg_match('/\b(md|lg):(relative|static)\b/', $line)) {
                    continue;
                }
                if (preg_match('/\b(md|lg):z-auto\b/', $line)) {
                    continue;
                }

                $offenders[] = sprintf('%s:%d', $file->getFilename(), $number + 1);
            }
        }

        self::assertSame([], $offenders, sprintf(
            "These panels keep a z-index above the sticky page header on desktop, so scrolling draws them over the breadcrumb: %s.\nAdd `md:z-auto` (or `lg:z-auto`) beside the mobile z-index.",
            implode(', ', $offenders),
        ));
    }
}
