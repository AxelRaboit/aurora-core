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
use function sprintf;
use function str_contains;
use function str_ends_with;

/**
 * A date is read wrong far more often than it is read as broken.
 *
 * `toLocaleDateString()` with no argument, and a native `<input type="date">`,
 * both format by the *machine's* locale rather than the application's. On a
 * French back-office opened from a machine set to English, 09/20/2026 and
 * 20/09/2026 are both valid dates and only one of them is September the
 * twentieth. Nothing looks broken, so nothing gets reported.
 *
 * It was found twice before this test existed: once on a publication-date
 * field, which is why `AppDatePicker` exists at all, and once on the picker
 * itself, which turned out to declare in its own docblock a display format it
 * never passed on. Eighteen more call sites were still doing it.
 *
 * `useDateFormat()` is the way: it builds `Intl.DateTimeFormat` on the
 * vue-i18n locale, which is the application's.
 */
final class DatesFollowTheApplicationNotTheMachineTest extends TestCase
{
    private const string ASSETS = __DIR__.'/../../src';

    public function testNothingFormatsADateWithTheMachinesLocale(): void
    {
        $offenders = [];

        foreach ($this->assetFiles() as $file) {
            $source = (string) file_get_contents($file);

            // `toLocaleDateString()` and friends, called with no locale at all.
            // Passing one explicitly is fine and is not what this looks for.
            preg_match_all('/toLocale(?:Date|Time)?String\(\s*\)/', $source, $matches);

            foreach ($matches[0] as $call) {
                $offenders[] = sprintf('%s: %s', $this->relative($file), $call);
            }
        }

        self::assertSame([], $offenders, sprintf(
            "These format a date with the browser's locale rather than the application's, so a French "
            ."back-office shows an American date to anybody whose machine is set to English:\n%s\n"
            .'Use `useDateFormat()` instead.',
            implode("\n", $offenders),
        ));
    }

    /**
     * Where a native date field is deliberate, and why.
     *
     * One entry, and it is not an oversight: the public signing page is opened
     * by a stranger, usually on a phone, and the operating system's own wheel
     * beats any picker we ship for somebody who has never seen this interface.
     * The file argues it in its own comment.
     *
     * An allow-list rather than a marker in the source, so that adding to it is
     * a deliberate edit to a test that says what the rule is - and so that the
     * reason is written somewhere a reader of the rule will find it.
     *
     * @var list<string>
     */
    private const array DELIBERATE_NATIVE_FIELDS = [
        'src/Module/Studio/Contract/Signature/assets/public/sign/ContractSignApp.vue',
    ];

    /**
     * The same defect, one layer down.
     *
     * A native date input takes its display format and its calendar from the
     * operating system. `AppDatePicker` exists precisely because of it, reads
     * the application's locale, and accepts several typed formats.
     */
    public function testNoNativeDateFieldIsUsedForADate(): void
    {
        $offenders = [];

        foreach ($this->assetFiles() as $file) {
            if (in_array($this->relative($file), self::DELIBERATE_NATIVE_FIELDS, true)) {
                continue;
            }

            $source = (string) file_get_contents($file);

            preg_match_all('/type="(date|datetime-local|month)"/', $source, $matches);

            foreach ($matches[0] as $field) {
                $offenders[] = sprintf('%s: <input %s>', $this->relative($file), $field);
            }
        }

        self::assertSame([], $offenders, sprintf(
            'These take their format and their calendar from the operating system rather than from the '
            ."application:\n%s\nUse `AppDatePicker` instead.",
            implode("\n", $offenders),
        ));
    }

    /**
     * @return iterable<string>
     */
    private function assetFiles(): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::ASSETS, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = $file->getPathname();

            if (str_contains($path, '/node_modules/') || str_ends_with($path, '.test.js')) {
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
