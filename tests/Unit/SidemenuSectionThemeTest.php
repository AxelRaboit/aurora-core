<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Every menu section has to carry its own colour.
 *
 * The sidemenu colour-codes each family so a glance tells you where you are.
 * A section with no entry in `SECTION_THEMES` does not fail: it falls back to
 * the accent palette and simply looks like it was forgotten - which is exactly
 * what happened to Planning and Notes, both shipping colourless next to six
 * sections that were not. Nothing reported it because nothing was broken.
 *
 * The PHP side is the authority: it is where `NavSection` ids are minted. This
 * reads them out of the module classes and checks the JS map answers for each.
 */
final class SidemenuSectionThemeTest extends TestCase
{
    private const string THEME_PATH = __DIR__.'/../../src/Core/assets/backend/sidemenu/composables/useSidemenuSectionTheme.js';

    private const string MODULES_DIR = __DIR__.'/../../src/Module';

    public function testEverySectionEmittedByAModuleHasAColour(): void
    {
        $themed = $this->themedSectionIds();
        self::assertNotSame([], $themed, 'No themes could be read - if the map moved, this test has to follow it.');

        $missing = array_values(array_diff($this->emittedSectionIds(), $themed));

        self::assertSame(
            [],
            $missing,
            sprintf(
                "These sections fall back to the accent palette and read as uncoloured next to the others: %s.\n".
                'Add an entry to SECTION_THEMES, picking a hue against the sections above and below it in priority order.',
                implode(', ', $missing),
            ),
        );
    }

    /**
     * Section ids the PHP modules actually emit.
     *
     * @return list<string>
     */
    private function emittedSectionIds(): array
    {
        $ids = [];

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::MODULES_DIR));
        foreach ($files as $file) {
            if (!$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            if (false === $source) {
                continue;
            }

            if (0 !== preg_match_all("/new NavSection\(\s*'([a-z_]+)'/", $source, $matches)) {
                foreach ($matches[1] as $id) {
                    $ids[$id] = true;
                }
            }
        }

        return array_keys($ids);
    }

    /**
     * Section ids the JS map answers for.
     *
     * @return list<string>
     */
    private function themedSectionIds(): array
    {
        $js = file_get_contents(self::THEME_PATH);
        if (false === $js) {
            return [];
        }

        if (0 === preg_match('/const SECTION_THEMES = \{(.*?)\};/s', $js, $block)) {
            return [];
        }

        preg_match_all('/^\s*([a-z_]+):\s*makeTheme\(/m', $block[1], $matches);

        return $matches[1];
    }
}
