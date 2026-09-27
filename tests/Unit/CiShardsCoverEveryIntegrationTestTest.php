<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_count_values;
use function array_filter;
use function array_keys;
use function array_merge;
use function escapeshellarg;
use function exec;
use function preg_replace;
use function sort;
use function sprintf;
use function str_ends_with;

use const PHP_BINARY;

/**
 * The CI runs the integration tests in shards; none may fall between them.
 *
 * A test that no shard picks never runs, and nothing turns red to say so: the
 * pipeline is green and shorter. The split is computed by `tools/ci/shard.php`
 * from the files on disk, and this holds it to its promise for any number of
 * shards - every file once, none twice.
 */
final class CiShardsCoverEveryIntegrationTestTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    /** @return iterable<string, array{int}> */
    public static function totals(): iterable
    {
        foreach ([1, 2, 3, 4] as $total) {
            yield sprintf('%d shard(s)', $total) => [$total];
        }
    }

    #[DataProvider('totals')]
    public function testEveryIntegrationTestRunsInExactlyOneShard(int $total): void
    {
        $picked = [];
        for ($index = 1; $index <= $total; ++$index) {
            $picked = array_merge($picked, $this->shard($total, $index));
        }

        $twice = array_keys(array_filter(array_count_values($picked), static fn (int $count): bool => $count > 1));
        self::assertSame([], $twice, 'these run in more than one shard');

        sort($picked);
        self::assertSame($this->integrationTests(), $picked, 'every integration test runs in exactly one shard');
    }

    /** @return list<string> */
    private function shard(int $total, int $index): array
    {
        $lines = [];
        exec(sprintf('%s %s %d %d', escapeshellarg(PHP_BINARY), escapeshellarg(self::ROOT.'/tools/ci/shard.php'), $total, $index), $lines, $status);
        self::assertSame(0, $status);

        return array_values(array_filter($lines, static fn (string $line): bool => '' !== $line));
    }

    /** @return list<string> */
    private function integrationTests(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::ROOT.'/tests/Integration', RecursiveDirectoryIterator::SKIP_DOTS));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (str_ends_with($file->getFilename(), 'Test.php')) {
                $files[] = (string) preg_replace('#^.*/tests/Integration/#', 'tests/Integration/', $file->getPathname());
            }
        }

        sort($files);

        return $files;
    }
}
