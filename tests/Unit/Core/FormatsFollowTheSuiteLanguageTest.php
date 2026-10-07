<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function file_get_contents;
use function implode;
use function mb_ltrim;
use function mb_strrpos;
use function mb_substr;
use function mb_substr_count;
use function preg_match_all;
use function preg_replace;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_starts_with;

/**
 * A date or an amount on screen is written in the language of the screen.
 *
 * `Intl.NumberFormat(undefined, …)` and `toLocaleDateString(undefined, …)`
 * take the browser's language instead. A French suite opened in an English
 * browser printed « €750 » and « €10,000 » next to a contract text that says
 * « 750 € », and the trash read « supprimé le Oct 06, 2026 » (UI audit of
 * 07/10/2026). Nobody saw it in a French browser.
 *
 * Dates go through `useDateFormat`, amounts through `useMoneyFormat`, sizes
 * through `useFileSize`: all three read the language from vue-i18n. Asking
 * `Intl.DateTimeFormat().resolvedOptions()` for the device's time zone is
 * not formatting, and stays allowed.
 */
final class FormatsFollowTheSuiteLanguageTest extends TestCase
{
    private const string SOURCES = __DIR__.'/../../../src';

    /** A formatter called with no language, or with `undefined` for one. */
    private const string BROWSER_LANGUAGE = '/(?:Intl\.(?:NumberFormat|DateTimeFormat|RelativeTimeFormat|PluralRules)|\.toLocale(?:Date|Time)?String)\(\s*(?:undefined\b|\))/';

    public function testNothingFormatsInTheBrowsersLanguage(): void
    {
        $offenders = [];

        foreach ($this->scripts() as $file) {
            $source = (string) file_get_contents($file);
            preg_match_all(self::BROWSER_LANGUAGE, $source, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as [$call, $offset]) {
                // `Intl.DateTimeFormat().resolvedOptions().timeZone` asks for
                // the device's zone, it formats nothing.
                if (str_contains(mb_substr($source, $offset, 80), '.resolvedOptions()')) {
                    continue;
                }

                // Quoted in a comment, as the composables quote it to say why
                // they exist.
                $lineStart = (int) mb_strrpos(mb_substr($source, 0, $offset), "\n");
                $before = mb_ltrim(mb_substr($source, $lineStart, $offset - $lineStart));
                if (str_starts_with($before, '*') || str_starts_with($before, '//') || str_starts_with($before, '/*')) {
                    continue;
                }

                $offenders[] = sprintf('%s:%d  %s', $this->relative($file), mb_substr_count(mb_substr($source, 0, $offset), "\n") + 1, $call);
            }
        }

        self::assertSame([], $offenders, sprintf(
            "These format a date or a number in the browser's language, not the suite's:\n%s\n"
            .'Use useDateFormat, useMoneyFormat or useFileSize, or pass the vue-i18n locale.',
            implode("\n", $offenders),
        ));
    }

    /**
     * @return iterable<string>
     */
    private function scripts(): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::SOURCES, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = $file->getPathname();

            if (str_contains($path, '/node_modules/') || str_contains($path, '/generated/') || str_ends_with($path, '.test.js')) {
                continue;
            }

            if (str_ends_with($path, '.vue') || str_ends_with($path, '.js')) {
                yield $path;
            }
        }
    }

    private function relative(string $path): string
    {
        return (string) preg_replace('#^.*/src/#', 'src/', $path);
    }
}
