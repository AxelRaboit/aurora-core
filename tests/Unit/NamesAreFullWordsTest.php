<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_unique;
use function explode;
use function file_get_contents;
use function implode;
use function in_array;
use function is_array;
use function json_decode;
use function mb_ltrim;
use function mb_strlen;
use function mb_strtolower;
use function mb_trim;
use function preg_match_all;
use function preg_replace;
use function sort;
use function sprintf;
use function str_ends_with;
use function token_get_all;

use const JSON_THROW_ON_ERROR;
use const T_VARIABLE;

/**
 * Variables, parameters and properties are named with full words:
 * `$entityManager`, not `$em`; `$index`, not `$i`; `$left` and `$right` in a
 * comparator, not `$a` and `$b`.
 *
 * The rule had been written in CLAUDE.md for a long time and nothing held it,
 * so by October 2026 some 2,700 short or abbreviated names had come back across
 * 500 files. They were all renamed at once, and this test keeps them from
 * returning. The list of forbidden words, and of the few short names that are
 * not abbreviations, lives in tools/naming/full-word-names.json, which the
 * `aurora/full-word-names` ESLint rule reads as well for the JS and Vue side.
 *
 * In PHP every `$variable` is read, which covers every property too since PHP
 * declares one with its `$`. In Twig, the variables a template declares: `for`,
 * `set`, macro arguments and arrow-function arguments.
 */
final class NamesAreFullWordsTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    private const array DIRECTORIES = ['src', 'tests', 'fixtures', 'config', 'templates'];

    public function testEveryVariableIsNamedWithFullWords(): void
    {
        $naming = json_decode((string) file_get_contents(self::ROOT.'/tools/naming/full-word-names.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($naming);
        $abbreviations = $naming['abbreviations'];
        $shortNamesAllowed = $naming['shortNamesAllowed'];
        self::assertTrue(is_array($abbreviations) && is_array($shortNamesAllowed));

        $offenders = [];

        foreach ($this->names() as [$file, $name]) {
            $problem = $this->problem(mb_ltrim($name, '$'), $abbreviations, $shortNamesAllowed);

            if (null !== $problem) {
                $offenders[sprintf('%s: %s %s', $this->relative($file), $name, $problem)] = true;
            }
        }

        $offenders = array_keys($offenders);
        sort($offenders);

        self::assertSame([], $offenders, sprintf(
            "These names are short or abbreviated, write them in full words:\n%s\n"
            .'The list of forbidden words is tools/naming/full-word-names.json.',
            implode("\n", $offenders),
        ));
    }

    /**
     * @param array<string, string> $abbreviations
     * @param list<string>          $shortNamesAllowed
     */
    private function problem(string $name, array $abbreviations, array $shortNamesAllowed): ?string
    {
        $bare = mb_ltrim($name, '_');

        if ('' === $bare || in_array($bare, $shortNamesAllowed, true)) {
            return null;
        }

        // Words of a camelCase or SNAKE_CASE name: viewingDocVersions gives
        // viewing, doc, versions; DOC_MIME gives doc, mime.
        preg_match_all('/[A-Z]+(?=[A-Z][a-z])|[A-Z]?[a-z]+|[A-Z]+|\d+/', $bare, $matches);

        foreach ($matches[0] as $word) {
            $word = mb_strtolower($word);

            if (array_key_exists($word, $abbreviations)) {
                return sprintf('abbreviates "%s": write %s', $word, $abbreviations[$word]);
            }
        }

        if (mb_strlen($bare) <= 2) {
            return 'is one or two letters: say what it holds';
        }

        return null;
    }

    /**
     * Every name a file declares or uses: each `$variable` of a PHP file, and
     * in a Twig template the variables of `for`, `set`, a macro's arguments
     * and an arrow function's arguments.
     *
     * @return iterable<array{string, string}>
     */
    private function names(): iterable
    {
        foreach ($this->files() as $file) {
            $source = (string) file_get_contents($file);

            if (str_ends_with($file, '.php')) {
                foreach (token_get_all($source) as $token) {
                    if (is_array($token) && T_VARIABLE === $token[0] && '$this' !== $token[1]) {
                        yield [$file, $token[1]];
                    }
                }

                continue;
            }

            preg_match_all('/\{%-?\s*for\s+(\w+)(?:\s*,\s*(\w+))?\s+in\b/', $source, $loops);
            preg_match_all('/\{%-?\s*set\s+(\w+)/', $source, $assignments);
            preg_match_all('/\{%-?\s*macro\s+\w+\(([^)]*)\)/', $source, $macros);
            preg_match_all('/\(\s*(\w+)(?:\s*,\s*(\w+))?\s*\)\s*=>|[(|,]\s*(\w+)\s*=>/', $source, $arrows);

            $declared = [...$loops[1], ...$loops[2], ...$assignments[1], ...$arrows[1], ...$arrows[2], ...$arrows[3]];

            foreach ($macros[1] as $arguments) {
                foreach (explode(',', $arguments) as $argument) {
                    $declared[] = mb_trim(explode('=', $argument)[0]);
                }
            }

            foreach (array_unique(array_filter($declared)) as $name) {
                yield [$file, $name];
            }
        }
    }

    /**
     * @return iterable<string>
     */
    private function files(): iterable
    {
        foreach (self::DIRECTORIES as $directory) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(self::ROOT.'/'.$directory, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                $path = $file->getPathname();

                if (str_ends_with($path, '.php') || str_ends_with($path, '.twig')) {
                    yield $path;
                }
            }
        }
    }

    private function relative(string $path): string
    {
        return (string) preg_replace('#^.*?/tests/Unit/\.\./\.\./#', '', $path);
    }
}
