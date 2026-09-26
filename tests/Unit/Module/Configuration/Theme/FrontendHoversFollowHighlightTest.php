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
 * Un survol du site public passe par `highlight`, jamais par `accent` en dur.
 *
 * {@see ThemeContext::highlight()} laisse un thème rendre ses survols neutres ;
 * un bloc qui écrit `hover:border-accent` échappe à ce choix sans que rien ne
 * le signale, et c'est précisément sur une page aux couleurs travaillées qu'un
 * liseré vert se remarque. Les boutons pleins ou contour gardent leur accent
 * au survol (`hover:bg-accent`) : ils sont hors de la règle.
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
