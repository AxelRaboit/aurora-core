<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Translation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Yaml\Yaml;

use function array_diff_key;
use function array_filter;
use function array_keys;
use function dirname;
use function implode;
use function is_array;
use function is_string;
use function mb_substr_count;
use function preg_match_all;
use function sort;
use function sprintf;
use function str_replace;

/**
 * Spanish says everything French says, with the same variables.
 *
 * The back office fell back to French in Spanish for three weeks: 4 183 of
 * 5 312 keys existed in French only, and nothing noticed, because the
 * fallback hides a missing key instead of failing on it. Every catalogue now
 * has its Spanish file, and a key added in French without its Spanish twin
 * fails here - the same guard {@see TranslationConsistencyTest} keeps for
 * English.
 */
final class SpanishTranslationCompletenessTest extends TestCase
{
    /** @return iterable<string, array{string, array<string, mixed>, array<string, mixed>}> */
    public static function catalogues(): iterable
    {
        $src = dirname(__DIR__, 3).'/src';
        $walker = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($walker as $file) {
            if (1 !== preg_match_all('/^(\w+)\.fr\.yaml$/', $file->getFilename())) {
                continue;
            }

            $fr = $file->getPathname();
            $es = str_replace('.fr.yaml', '.es.yaml', $fr);
            $label = str_replace($src.'/', '', $fr);

            yield $label => [$label, self::flat(Yaml::parseFile($fr) ?? []), is_file($es) ? self::flat(Yaml::parseFile($es) ?? []) : []];
        }
    }

    /**
     * @param array<string, mixed> $fr
     * @param array<string, mixed> $es
     */
    #[DataProvider('catalogues')]
    public function testEveryFrenchKeyHasItsSpanishTwin(string $catalogue, array $fr, array $es): void
    {
        self::assertSame([], array_keys(array_diff_key($fr, $es)), sprintf('[%s] in French, missing in Spanish', $catalogue));
        self::assertSame([], array_keys(array_diff_key($es, $fr)), sprintf('[%s] in Spanish, missing in French', $catalogue));
        self::assertSame([], array_keys(array_filter($es, static fn (mixed $value): bool => '' === $value || null === $value)), sprintf('[%s] empty in Spanish', $catalogue));
    }

    /**
     * The same variables, bars and at signs: a translation that lost `{count}`
     * prints the brace, one that gained a `|` becomes a plural.
     *
     * @param array<string, mixed> $fr
     * @param array<string, mixed> $es
     */
    #[DataProvider('catalogues')]
    public function testSpanishKeepsTheVariablesOfTheFrench(string $catalogue, array $fr, array $es): void
    {
        $mismatches = [];

        foreach ($fr as $key => $value) {
            if (is_string($value) && isset($es[$key]) && is_string($es[$key]) && self::marks($value) !== self::marks($es[$key])) {
                $mismatches[] = $key;
            }
        }

        self::assertSame([], $mismatches, sprintf('[%s] variables differ from the French', $catalogue));
    }

    /**
     * What a value carries besides words. `%hh%` is accepted wherever French
     * says `%h%`: the time is written 09:30 in Spanish and 9h30 in French,
     * and the code offers both.
     */
    private static function marks(string $value): string
    {
        preg_match_all('/\{[^}]*\}|%[a-z_]+%/', str_replace('%hh%', '%h%', $value), $matches);
        $found = $matches[0];
        sort($found);

        return implode(',', $found).'|'.mb_substr_count($value, '|').'@'.mb_substr_count($value, '@');
    }

    /**
     * @param array<array-key, mixed> $tree
     *
     * @return array<string, mixed>
     */
    private static function flat(array $tree, string $prefix = ''): array
    {
        $flat = [];

        foreach ($tree as $key => $value) {
            $path = '' === $prefix ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $flat += self::flat($value, $path);
            } else {
                $flat[$path] = $value;
            }
        }

        return $flat;
    }
}
