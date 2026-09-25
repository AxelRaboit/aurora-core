<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Ged\Document;

use Aurora\Module\Ged\Document\Contract\DocumentUsageProviderInterface;
use Aurora\Tests\Integration\Service\DocumentUsageProvidersTest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function basename;
use function dirname;
use function file_get_contents;
use function glob;
use function implode;
use function is_string;
use function preg_match;
use function sprintf;
use function str_contains;

use const GLOB_ONLYDIR;

/**
 * Every module that holds a GED document answers for it.
 *
 * {@see DocumentUsageProviderInterface} explains what the silence costs: the
 * library's deletion screen asks the tagged providers who is using a file,
 * and a module that keeps a document without answering makes that screen
 * report "no usage" on a file somebody is using. Measured on 2026-09-16,
 * two of the three consumers were in that state - Studio's space
 * attachments, which take their row with them on `CASCADE`, and Editorial's
 * posts, whose cover and gallery pictures went blank.
 *
 * It checks that an answer exists, not that it is right - each provider
 * argues its own case, and {@see DocumentUsageProvidersTest}
 * runs their queries against the real schema.
 *
 * **Typed relations only.** A document id parked in a JSON column is invisible
 * here, which is precisely why the rule wants stating: Deck slides hold theirs
 * that way and went unreported for as long as they did because no grep would
 * have found them either.
 */
final class DocumentConsumersDeclareTheirUsageTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function modulesHoldingDocuments(): iterable
    {
        $root = dirname(__DIR__, 5).'/src/Module';

        foreach (glob($root.'/*', GLOB_ONLYDIR) ?: [] as $moduleDir) {
            $module = basename($moduleDir);

            // The GED owns the entity; it does not reference somebody else's.
            if ('Ged' === $module) {
                continue;
            }

            foreach (self::phpFilesIn($moduleDir) as $file) {
                $source = file_get_contents($file);

                if (is_string($source) && str_contains($source, 'targetEntity: DocumentInterface::class')) {
                    yield $module => [$module, $moduleDir];

                    continue 2;
                }
            }
        }
    }

    #[DataProvider('modulesHoldingDocuments')]
    public function testItProvidesAUsageProvider(string $module, string $moduleDir): void
    {
        $providers = [];

        foreach (self::phpFilesIn($moduleDir) as $file) {
            $source = file_get_contents($file);

            // Either interface answers the rule: the batch one extends the
            // single-document one, so a provider declaring it answers the
            // deletion screen as well as the library's listing. Matching only
            // the longer name would have gone the other way and let a module
            // that answers nothing through.
            if (is_string($source) && 1 === preg_match('/implements\s+[^{]*\b(?:Batch)?DocumentUsageProviderInterface\b/', $source)) {
                $providers[] = basename($file, '.php');
            }
        }

        self::assertNotSame([], $providers, sprintf(
            'Module "%s" references a GED Document but provides no DocumentUsageProviderInterface '
            .'(or its batch variant). '
            .'Without one, deleting a document it holds reports no usage and breaks it silently.',
            $module,
        ));
    }

    public function testTheScanFindsTheModulesItIsMeantTo(): void
    {
        $found = [];

        foreach (self::modulesHoldingDocuments() as [$module, $_]) {
            $found[] = $module;
        }

        // A guard on the guard: if the marker string is ever reworded, the
        // data provider empties and every assertion above passes vacuously.
        self::assertContains('Studio', $found, 'Scan found: '.implode(', ', $found));
        self::assertContains('Editorial', $found, 'Scan found: '.implode(', ', $found));
    }

    /**
     * @return list<string>
     */
    private static function phpFilesIn(string $dir): array
    {
        $files = [];

        foreach (glob($dir.'/*.php') ?: [] as $file) {
            $files[] = $file;
        }

        foreach (glob($dir.'/*', GLOB_ONLYDIR) ?: [] as $sub) {
            foreach (self::phpFilesIn($sub) as $file) {
                $files[] = $file;
            }
        }

        return $files;
    }
}
