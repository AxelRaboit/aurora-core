<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Translation;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Yaml\Yaml;

use function dirname;
use function implode;
use function is_array;
use function is_string;
use function preg_match;
use function sprintf;

/**
 * No ICU plurals in the translations, because vue-i18n's compiler cannot read
 * them.
 *
 * `{count, plural, one {…} other {# …}}` is what every other framework uses and
 * what anyone will reach for. Aurora runs vue-i18n's default compiler, which wants
 * pipe-separated arms - `'1 photo | {count} photos'` - and throws at runtime on
 * anything else.
 *
 * **The reason this test exists is that nothing else catches it.** The YAML is
 * valid, `lint:yaml` passes, the translation dump succeeds, the Vite build
 * succeeds, and `make ft` is green. The failure appears in the browser, on the one
 * screen that calls `t()` on that key, as `SyntaxError` from inside the vendor
 * bundle - a stack trace with no key name and no file in it. That is a poor way to
 * find a typo, and `convention_i18n_plurals` had already been written after it
 * happened once. It happened again anyway, on
 * `backend.post_galleries.photo_count`, which is what prompted this.
 *
 * Deliberately a syntax check and not a semantic one: whether an arm reads well is
 * a translator's business, but whether the compiler can parse it at all is not a
 * matter of taste.
 */
final class PluralSyntaxTest extends TestCase
{
    /**
     * `{name, plural, …}` and its relatives, which is the whole family the default
     * compiler refuses. Anchored on the comma after the argument name so an
     * ordinary placeholder like `{count}` is not mistaken for one.
     */
    private const string ICU_PATTERN = '/\{\s*[a-zA-Z_][a-zA-Z0-9_]*\s*,\s*(plural|select|selectordinal)\s*,/';

    public function testNoTranslationUsesIcuPluralSyntax(): void
    {
        $offenders = [];

        foreach (self::translationFiles() as $path) {
            $parsed = Yaml::parseFile($path);

            foreach (self::flatten(is_array($parsed) ? $parsed : []) as $key => $message) {
                if (1 === preg_match(self::ICU_PATTERN, $message)) {
                    $offenders[] = sprintf('%s: %s', $key, $message);
                }
            }
        }

        self::assertSame([], $offenders, sprintf(
            "ICU plural syntax in a translation - vue-i18n's default compiler throws on these at "
            ."runtime, and nothing else in the pipeline notices.\nUse pipe-separated arms instead "
            ."(`'1 photo | {count} photos'`) and pass the count as the third argument to `t()`. "
            ."See convention_i18n_plurals.\n  %s",
            implode("\n  ", $offenders),
        ));
    }

    /**
     * Every leaf string, keyed by its dotted path.
     *
     * The path is what makes a failure actionable: the message alone does not say
     * which screen shows it.
     *
     * @param array<mixed> $node
     *
     * @return array<string, string>
     */
    private static function flatten(array $node, string $prefix = ''): array
    {
        $flat = [];

        foreach ($node as $key => $value) {
            $path = '' === $prefix ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                foreach (self::flatten($value, $path) as $childPath => $child) {
                    $flat[$childPath] = $child;
                }

                continue;
            }

            if (is_string($value)) {
                $flat[$path] = $value;
            }
        }

        return $flat;
    }

    /**
     * Every message catalogue under `src`, in every locale.
     *
     * All locales and not just the default: a plural written correctly in French
     * and pasted as ICU into English breaks only for readers in English, which is
     * the sort of thing that ships.
     *
     * @return list<string>
     */
    private static function translationFiles(): array
    {
        $paths = [];

        $walker = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 3).'/src', RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($walker as $file) {
            if ($file instanceof SplFileInfo
                && $file->isFile()
                && 1 === preg_match('/^messages\.[a-z]{2}\.yaml$/', $file->getFilename())
            ) {
                $paths[] = $file->getPathname();
            }
        }

        return $paths;
    }
}
