<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Configuration\Theme;

use Aurora\Module\Configuration\Theme\Service\ThemeContext;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function dirname;
use function explode;
use function file_get_contents;
use function implode;
use function mb_strlen;
use function mb_substr;
use function preg_match;
use function sprintf;
use function str_ends_with;

/**
 * A hover on the public site goes through `highlight`, never through a
 * hard-coded `accent`.
 *
 * {@see ThemeContext::highlight()} lets a theme make its hovers neutral; a
 * block that writes `hover:border-accent` escapes that choice without anything
 * flagging it, and it is precisely on a page with carefully worked colors that
 * a green edge stands out. Filled or outline buttons keep their accent on
 * hover (`hover:bg-accent`): they are outside the rule.
 */
final class FrontendHoversFollowHighlightTest extends TestCase
{
    public function testNoPublicTemplateHardcodesAnAccentHover(): void
    {
        $root = dirname(__DIR__, 5).'/src/Core/templates/Frontend';
        $offenders = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if (!str_ends_with($file->getFilename(), '.twig')) {
                continue;
            }

            foreach (explode("\n", (string) file_get_contents($file->getPathname())) as $index => $line) {
                if (1 === preg_match('/hover:(text|border)-accent\b/', $line)) {
                    $offenders[] = sprintf('%s:%d', mb_substr($file->getPathname(), mb_strlen($root) + 1), $index + 1);
                }
            }
        }

        self::assertSame([], $offenders, "Survol accent en dur, utiliser hover:text-highlight / hover:border-highlight :\n".implode("\n", $offenders));
    }
}
