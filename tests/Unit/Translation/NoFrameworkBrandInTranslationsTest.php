<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Translation;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * No translated string may name the framework.
 *
 * A client install is somebody's own site: it carries their name in the
 * settings, their domain, their branding. A label reading "Fourni par Aurora"
 * or a placeholder reading "Bienvenue chez Aurora" leaks the plumbing into
 * their screens, and worse, says something false - "Aurora" is not who
 * provides their content types, it is what runs them.
 *
 * Three strings shipped like that. They surfaced only when a site owner read
 * their own admin.
 */
final class NoFrameworkBrandInTranslationsTest extends TestCase
{
    public function testNoTranslationNamesTheFramework(): void
    {
        $offenders = [];

        foreach ($this->translationFiles() as $file) {
            $lines = explode("\n", (string) file_get_contents($file->getPathname()));
            foreach ($lines as $number => $line) {
                if (1 === preg_match('/^\s*#/', $line)) {
                    continue;
                }

                // The key half may legitimately be `aurora_*`; only the
                // translated value is read by a user.
                $value = mb_substr($line, (int) mb_strpos($line.':', ':') + 1);
                if (1 === preg_match('/\bAurora\b/i', $value)) {
                    $offenders[] = sprintf('%s:%d %s', basename(dirname($file->getPathname(), 2)).'/'.$file->getFilename(), $number + 1, mb_trim($line));
                }
            }
        }

        self::assertSame([], $offenders, sprintf(
            "These translated strings name the framework, which a client's own site should never show:\n%s",
            implode("\n", $offenders),
        ));
    }

    /** @return iterable<SplFileInfo> */
    private function translationFiles(): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 3).'/src', RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo
                && 'yaml' === $file->getExtension()
                && str_contains($file->getPath(), '/translations')
            ) {
                yield $file;
            }
        }
    }
}
