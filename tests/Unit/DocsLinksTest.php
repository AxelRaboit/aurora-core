<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Every relative link in the README and the docs has to land on a file.
 *
 * The README now only says what Aurora does and sends the reader to the docs
 * for everything else, so a link that rots is the whole way in. Fourteen of
 * them were broken when this test was written: pages moved between
 * `aurora-core/`, `aurora-client/` and `aurora-shared/`, and the links still
 * pointed at the old neighbour. Nothing said so, because Markdown is never
 * executed.
 *
 * Only relative paths are checked: web links need the network, and anchors
 * depend on how GitHub slugs a heading. Code blocks are skipped, since a path
 * there is an example rather than a link.
 */
final class DocsLinksTest extends TestCase
{
    private const string REPO_ROOT = __DIR__.'/../..';

    public function testEveryRelativeLinkLandsOnAFile(): void
    {
        $broken = [];

        foreach ($this->markdownFiles() as $file) {
            $text = (string) preg_replace('/```.*?```/s', '', (string) file_get_contents($file));
            preg_match_all('/\]\(([^)\s]+)\)/', $text, $matches);

            foreach ($matches[1] as $target) {
                if (1 === preg_match('/^[a-z]+:/i', $target) || str_starts_with($target, '#')) {
                    continue;
                }

                $path = explode('#', $target, 2)[0];
                if ('' !== $path && !file_exists(dirname($file).'/'.$path)) {
                    $broken[] = sprintf('%s -> %s', mb_substr($file, mb_strlen(self::REPO_ROOT) + 1), $target);
                }
            }
        }

        self::assertSame([], $broken, "These links point at nothing:\n".implode("\n", $broken));
    }

    /** @return list<string> */
    private function markdownFiles(): array
    {
        $files = [self::REPO_ROOT.'/README.md'];

        $documentationFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::REPO_ROOT.'/docs', RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($documentationFiles as $entry) {
            if ('md' === $entry->getExtension()) {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
