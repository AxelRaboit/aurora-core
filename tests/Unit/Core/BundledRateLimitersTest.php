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
 * Chaque limiteur qu'un contrôleur demande est fourni par le paquet.
 *
 * **Le défaut ne se voit qu'au déploiement d'un projet client**, et il se voit
 * mal : un conteneur qui refuse de se construire, sur un service dont le
 * projet n'a jamais entendu parler, au premier `make aurora-update` après la
 * version fautive. Rien dans aurora-core ne bronche, puisque son propre
 * `config/packages/rate_limiter.yaml` les déclare tous.
 *
 * C'est arrivé deux fois en une heure : une première en ajoutant celui du lot
 * Drive, une seconde en les déplaçant dans le paquet et en en oubliant un.
 * D'où ce test, qui lit les deux listes plutôt que de faire confiance.
 */
final class BundledRateLimitersTest extends TestCase
{
    public function testEveryLimiterAControllerAsksForIsShippedWithTheBundle(): void
    {
        $root = dirname(__DIR__, 3);

        // Ce que le code demande : Symfony nomme le service d'après
        // l'argument, donc `$spaceGuestWriteLimiter` veut `space_guest_write`.
        //
        // Lu en PHP et non par un `grep` : un test qui dépend d'un binaire du
        // système échoue pour la mauvaise raison le jour où il manque.
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

        // Ce que le paquet fournit.
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
