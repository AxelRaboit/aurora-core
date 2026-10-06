<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_diff;
use function array_map;
use function array_unique;
use function array_values;
use function dirname;
use function file_get_contents;
use function implode;
use function mb_strtolower;
use function preg_match_all;
use function preg_replace;
use function sort;
use function sprintf;

/**
 * Every limiter a controller asks for is provided by the bundle.
 *
 * **The flaw only shows when a client project deploys**, and it shows badly:
 * a container that refuses to build, over a service the project has never
 * heard of, at the first `make aurora-update` after the faulty version.
 * Nothing in aurora-core flinches, since its own
 * `config/packages/rate_limiter.yaml` declares them all.
 *
 * It happened twice in one hour: first when adding the one for the Drive
 * batch, then when moving them into the bundle and forgetting one. Hence this
 * test, which reads both lists rather than trusting.
 */
final class BundledRateLimitersTest extends TestCase
{
    public function testEveryLimiterAControllerAsksForIsShippedWithTheBundle(): void
    {
        $root = dirname(__DIR__, 3);

        // What the code asks for: Symfony names the service after the
        // argument, so `$spaceGuestWriteLimiter` wants `space_guest_write`.
        //
        // Read in PHP and not with a `grep`: a test that depends on a system
        // binary fails for the wrong reason the day it is missing.
        $asked = [1 => []];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src'));

        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }

            preg_match_all('/\$([a-zA-Z]+)Limiter/', (string) file_get_contents($file->getPathname()), $found);
            $asked[1] = [...$asked[1], ...$found[1]];
        }

        $wanted = array_unique(array_map(self::snake(...), $asked[1]));
        sort($wanted);

        self::assertNotEmpty($wanted, 'Aucun limiteur demandé : le motif de lecture a changé.');

        // What the bundle provides.
        $bundle = (string) file_get_contents($root.'/src/AuroraBundle.php');
        preg_match_all("/'([a-z_]+)' => \['policy' => 'sliding_window'/", $bundle, $shipped);

        $missing = array_diff($wanted, $shipped[1]);

        self::assertSame([], array_values($missing), sprintf(
            "AuroraBundle ne fournit pas : %s. Un projet client refusera de construire son conteneur au premier déploiement, sur un service dont il n'a jamais entendu parler.",
            implode(', ', $missing),
        ));
    }

    /** `spaceGuestWrite` devient `space_guest_write`. */
    private static function snake(string $camel): string
    {
        return mb_strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $camel));
    }
}
