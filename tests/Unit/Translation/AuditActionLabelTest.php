<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Translation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Yaml\Yaml;

/**
 * The audit log renders `backend.audit.actions.<module>.<action>`, built at
 * runtime from whatever the AuditLogger was handed. Nothing tied the two ends
 * together, so the label tree drifted from the call sites until 29 Core/Ged
 * actions rendered as their raw key and 24 labels described actions nothing
 * emitted any more - including three where the label existed under the old
 * name after a rename, leaving both sides dead at once.
 *
 * Renaming an action or adding one now fails here instead of shipping a raw
 * key to the audit screen.
 *
 * Scope is this package: a module distributed as its own composer package
 * owns the labels for the actions it emits and carries its own copy of this
 * test.
 */
final class AuditActionLabelTest extends TestCase
{
    /**
     * Actions whose label deliberately lives elsewhere. Empty by design -
     * an entry here means a raw key reaches a user, so it wants a comment
     * saying who renders it instead.
     *
     * @var list<string>
     */
    private const array LABEL_EXCEPTIONS = [];

    /**
     * Labels this package owns for actions emitted from elsewhere.
     *
     * Editorial's MenuManager logs under the 'core' module rather than
     * 'editorial' - Menu used to live in Core, and the value was kept so the
     * audit rows already in the database keep resolving. The labels therefore
     * have to stay here even though nothing in this package emits them.
     *
     * @var list<string>
     */
    private const array ORPHAN_EXCEPTIONS = [
        'core.menu.created',
        'core.menu.updated',
        'core.menu.deleted',
        'core.menu.item.created',
        'core.menu.item.updated',
        'core.menu.item.deleted',
    ];

    /** @return list<array{string}> */
    public static function localeProvider(): array
    {
        return [['fr'], ['en']];
    }

    #[DataProvider('localeProvider')]
    public function testEveryEmittedActionHasALabel(string $locale): void
    {
        $missing = array_diff(self::emittedActions(), self::definedLabels($locale), self::LABEL_EXCEPTIONS);

        self::assertSame(
            [],
            array_values($missing),
            sprintf(
                "Audit actions emitted by the code with no %s label - they render as the raw key:\n  %s",
                mb_strtoupper($locale),
                implode("\n  ", $missing),
            ),
        );
    }

    #[DataProvider('localeProvider')]
    public function testEveryLabelHasAnEmitter(string $locale): void
    {
        $orphans = array_diff(self::definedLabels($locale), self::emittedActions(), self::ORPHAN_EXCEPTIONS);

        self::assertSame(
            [],
            array_values($orphans),
            sprintf(
                "Audit labels in %s describing actions nothing emits - dead weight, or a rename whose label was left behind:\n  %s",
                mb_strtoupper($locale),
                implode("\n  ", $orphans),
            ),
        );
    }

    /**
     * Every `$this->auditLogger->log('<module>', '<action>', …)` call site,
     * flattened to `<module>.<action>` - the shape the Vue side builds.
     *
     * Only literal arguments are matched. A call built from a variable or a
     * constant would slip through silently, so the count is asserted against
     * the number of call sites found to make that failure loud.
     *
     * @return list<string>
     */
    private static function emittedActions(): array
    {
        $callSites = 0;
        $actions = [];

        foreach (self::phpFiles() as $path) {
            $code = (string) file_get_contents($path);
            $callSites += mb_substr_count($code, 'auditLogger->log(');

            preg_match_all("/auditLogger->log\(\s*'([a-z_]+)',\s*'([a-z_.]+)'/", $code, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $actions[$match[1].'.'.$match[2]] = true;
            }
        }

        self::assertSame(
            $callSites,
            array_sum(array_map(
                static fn (string $path): int => preg_match_all(
                    "/auditLogger->log\(\s*'[a-z_]+',\s*'[a-z_.]+'/",
                    (string) file_get_contents($path),
                ),
                self::phpFiles(),
            )),
            'Some auditLogger->log() call sites pass a non-literal module or action, so this test cannot see them. '
            .'Make the arguments literal, or this drift check has a blind spot.',
        );

        $actions = array_keys($actions);
        sort($actions);

        return $actions;
    }

    /**
     * Every leaf under `backend.audit.actions` across all of this package's
     * translation files, flattened to dot notation.
     *
     * @return list<string>
     */
    private static function definedLabels(string $locale): array
    {
        $labels = [];

        $flatten = static function (mixed $node, string $prefix) use (&$flatten, &$labels): void {
            foreach ((array) $node as $key => $value) {
                if (is_array($value)) {
                    $flatten($value, $prefix.$key.'.');

                    continue;
                }
                $labels[$prefix.$key] = true;
            }
        };

        foreach (self::translationFiles($locale) as $path) {
            $parsed = Yaml::parseFile($path) ?? [];
            $flatten($parsed['backend']['audit']['actions'] ?? [], '');
        }

        $labels = array_keys($labels);
        sort($labels);

        return $labels;
    }

    /** @return list<string> */
    private static function phpFiles(): array
    {
        return self::filesNamed(static fn (string $name): bool => str_ends_with($name, '.php'));
    }

    /** @return list<string> */
    private static function translationFiles(string $locale): array
    {
        return self::filesNamed(static fn (string $name): bool => sprintf('messages.%s.yaml', $locale) === $name);
    }

    /**
     * @param callable(string): bool $matches
     *
     * @return list<string>
     */
    private static function filesNamed(callable $matches): array
    {
        $paths = [];
        $walker = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 3).'/src', RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($walker as $file) {
            if ($matches($file->getFilename())) {
                $paths[] = $file->getPathname();
            }
        }

        sort($paths);

        return $paths;
    }
}
