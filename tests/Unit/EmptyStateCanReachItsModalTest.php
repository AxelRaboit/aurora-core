<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The button on an empty screen has to have a modal to open.
 *
 * The list screens are built as a pair: `<AppNoData v-if="!items.length">` with
 * a create button, and a `v-else` branch holding the list. Putting the modals
 * inside that second branch looks tidy and breaks the only path that matters on
 * a fresh installation: with nothing created yet the branch is not rendered, so
 * the modal does not exist, so the button sets a flag nothing is listening to.
 *
 * The first item can never be created. Only the first - as soon as one exists
 * the branch renders and everything works, which is why this survived: it is
 * invisible to anybody whose database is not empty, and every developer's is
 * not.
 *
 * Four screens shipped with it: forms, post types, taxonomies and document
 * folders.
 */
final class EmptyStateCanReachItsModalTest extends TestCase
{
    private const string ASSETS = __DIR__.'/../../src/Module';

    public function testAModalIsNotTrappedInTheBranchTheEmptyStateReplaces(): void
    {
        $offenders = [];

        foreach ($this->vueFiles() as $file) {
            $source = file_get_contents($file);
            if (false === $source || !str_contains($source, '<AppModal')) {
                continue;
            }

            $lines = explode("\n", $source);
            $branch = $this->rootElseBranch($lines);
            if (null === $branch) {
                continue;
            }

            [$start, $end] = $branch;
            $inside = implode("\n", array_slice($lines, $start, $end - $start));
            $before = implode("\n", array_slice($lines, 0, $start));

            // A modal in the `v-else`, and something before it that opens one.
            if (!str_contains($inside, '<AppModal')) {
                continue;
            }
            if (0 === preg_match('/v-on:click="(open\w+)"/', $before)) {
                continue;
            }

            $offenders[] = basename($file);
        }

        self::assertSame([], $offenders, sprintf(
            "On these screens the empty state offers a button whose modal only exists in the branch the empty state replaces, so the first item can never be created: %s.\nMove the modals out of the `v-else`, to the component root.",
            implode(', ', $offenders),
        ));
    }

    /**
     * Bounds of the first root-level `v-else` element.
     *
     * @param list<string> $lines
     *
     * @return array{int, int}|null
     */
    private function rootElseBranch(array $lines): ?array
    {
        $start = null;
        foreach ($lines as $i => $line) {
            if (str_contains($line, 'v-else') && 1 === preg_match('/^\s*<\w/', $line)) {
                $start = $i;
                break;
            }
        }

        if (null === $start) {
            return null;
        }

        preg_match('/^\s*<(\w+)/', $lines[$start], $tag);
        $depth = 0;

        for ($i = $start, $count = count($lines); $i < $count; ++$i) {
            $depth += preg_match_all('/<'.$tag[1].'\b/', $lines[$i]);
            $depth -= preg_match_all('#</'.$tag[1].'>#', $lines[$i]);

            if ($depth <= 0 && $i > $start) {
                return [$start, $i];
            }
        }

        return null;
    }

    /** @return list<string> */
    private function vueFiles(): array
    {
        $found = [];

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::ASSETS));
        foreach ($files as $file) {
            if ($file->isFile() && 'vue' === $file->getExtension()) {
                $found[] = $file->getPathname();
            }
        }

        return $found;
    }
}
