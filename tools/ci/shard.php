<?php

declare(strict_types=1);

/*
 * Splits the integration tests into CI shards of about the same duration.
 *
 *   php tools/ci/shard.php <total> <index>     the files of shard <index> (1-based)
 *   php tools/ci/shard.php --timings <junit>   rewrites timings.json from a JUnit log
 *
 * Every test file lands in exactly one shard: the list is read from the disk
 * on each run, so a new file is never forgotten, and the timings only decide
 * where it goes. A file they do not know weighs the median. Stale timings can
 * unbalance the shards a little; they cannot drop a test.
 * `tests/Unit/CiShardsCoverEveryIntegrationTestTest.php` holds that promise.
 *
 * Refreshing the timings, when one shard has drifted well past the others:
 *
 *   php bin/phpunit --testsuite=Integration --log-junit var/integration.xml
 *   php tools/ci/shard.php --timings var/integration.xml
 */

$root = dirname(__DIR__, 2);
$timingsFile = __DIR__.'/timings.json';

if ('--timings' === ($argv[1] ?? null)) {
    $junit = simplexml_load_file($argv[2] ?? '');
    if (false === $junit) {
        fwrite(STDERR, "Usage: php tools/ci/shard.php --timings <junit.xml>\n");
        exit(1);
    }

    $timings = [];
    foreach ($junit->xpath('//testsuite[@file]') as $suite) {
        $file = mb_ltrim(str_replace($root, '', (string) $suite['file']), '/');
        $timings[$file] = max($timings[$file] ?? 0.0, round((float) $suite['time'], 1));
    }

    ksort($timings);
    file_put_contents($timingsFile, json_encode($timings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    echo count($timings)." files timed.\n";
    exit(0);
}

$total = (int) ($argv[1] ?? 0);
$index = (int) ($argv[2] ?? 0);
if ($total < 1 || $index < 1 || $index > $total) {
    fwrite(STDERR, "Usage: php tools/ci/shard.php <total> <index>\n");
    exit(1);
}

$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/tests/Integration', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (str_ends_with($file->getFilename(), 'Test.php')) {
        $files[] = mb_ltrim(str_replace($root, '', $file->getPathname()), '/');
    }
}
sort($files);

$timings = is_file($timingsFile) ? json_decode((string) file_get_contents($timingsFile), true) : [];
$known = array_values($timings);
sort($known);
$median = [] === $known ? 1.0 : $known[intdiv(count($known), 2)];

// Longest first, each into the lightest shard so far: close to even without
// solving the partition exactly. Ties break on the path, so every CI job
// computes the same split.
usort($files, static fn (string $a, string $b): int => [$timings[$b] ?? $median, $a] <=> [$timings[$a] ?? $median, $b]);

$loads = array_fill(1, $total, 0.0);
$shards = array_fill(1, $total, []);
foreach ($files as $file) {
    $lightest = array_keys($loads, min($loads), true)[0];
    $shards[$lightest][] = $file;
    $loads[$lightest] += $timings[$file] ?? $median;
}

sort($shards[$index]);
echo implode("\n", $shards[$index])."\n";
