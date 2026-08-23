<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Translation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Yaml\Yaml;

/**
 * Every `t('…')` a Vue component writes must resolve to a sentence.
 *
 * When it does not, vue-i18n renders the key itself - so the screen shows
 * `shared.media.choose` where a button label belongs. Nothing throws, no test
 * fails, and the only way it surfaces is somebody looking at the page and
 * saying "I think that translation is missing". That has now happened three
 * times.
 *
 * The check reads the YAML sources rather than the generated JSON catalogue,
 * so it holds whether or not `make translation` has been run.
 *
 * Two things are deliberately not flagged:
 *
 *  - keys built by concatenation (`t('backend.modules.' + id)`), which end at
 *    a dot and cannot be resolved statically. What they resolve to is the
 *    caller's business;
 *  - occurrences inside comments, which are documentation rather than code -
 *    `useAutoSave.js` has a `t("xxx.save_failed")` in its usage example.
 */
final class VueTranslationKeyTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function keyProvider(): iterable
    {
        $catalogue = self::catalogue();

        foreach (self::sourceFiles() as $file) {
            foreach (self::keysIn($file) as $key) {
                // One case per (key, file) so a failure names the component.
                yield sprintf('%s in %s', $key, basename($file)) => [$key, basename($file)];
            }
        }

        // Guards the harvesting itself: a regex that silently stopped matching
        // would otherwise turn this whole test green.
        self::assertNotEmpty($catalogue, 'the translation catalogue is empty');
    }

    #[DataProvider('keyProvider')]
    public function testEveryKeyResolves(string $key, string $file): void
    {
        self::assertTrue(
            self::resolves(self::catalogue(), $key),
            sprintf(
                '"%s" is used in %s but resolves to nothing, so the screen shows the key itself.'."\n",
                $key,
                $file,
            ),
        );
    }

    /** @param array<string, mixed> $catalogue */
    private static function resolves(array $catalogue, string $key): bool
    {
        $node = $catalogue;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return false;
            }

            $node = $node[$segment];
        }

        return is_string($node);
    }

    /**
     * Every `messages.<locale>.yaml` merged. A key defined in any locale
     * counts: a missing *translation* is a different problem from a missing
     * *key*, and only the second one renders as gibberish.
     *
     * @return array<string, mixed>
     */
    private static function catalogue(): array
    {
        static $catalogue = null;
        if (null !== $catalogue) {
            return $catalogue;
        }

        $catalogue = [];
        foreach (self::files(self::root(), '/^messages\..*\.yaml$/') as $file) {
            /** @var array<string, mixed> $parsed */
            $parsed = Yaml::parseFile($file) ?? [];
            $catalogue = array_replace_recursive($catalogue, $parsed);
        }

        return $catalogue;
    }

    /** @return list<string> */
    private static function sourceFiles(): array
    {
        return self::files(self::root().'/src', '/\.(vue|js)$/');
    }

    /** @return list<string> */
    private static function keysIn(string $file): array
    {
        $contents = file_get_contents($file);
        if (false === $contents) {
            return [];
        }

        // Comments first: a usage example in a docblock is not a call site.
        $code = preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $contents) ?? $contents;

        $matches = [];
        preg_match_all('/\bt\(\s*[\'"]([a-z][a-zA-Z0-9_.]*)[\'"]/', $code, $matches);

        return array_values(array_unique(array_filter(
            $matches[1],
            // A trailing dot means the key continues with a variable.
            static fn (string $key): bool => !str_ends_with($key, '.'),
        )));
    }

    /**
     * @return list<string>
     */
    private static function files(string $directory, string $pattern): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $found = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && 1 === preg_match($pattern, $file->getFilename())
                && !str_contains($file->getPathname(), '/node_modules/')
            ) {
                $found[] = $file->getPathname();
            }
        }

        sort($found);

        return $found;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
