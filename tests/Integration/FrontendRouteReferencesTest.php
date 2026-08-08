<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Routing\RouterInterface;

use function dirname;
use function in_array;
use function sprintf;

/**
 * Backend paths written as literals in the frontend code must match a route.
 *
 * The block editor posted its image uploads to `/backend/media/media/upload`
 * for months after that route left with the Media module. Nothing failed
 * loudly — the request 404'd, the upload silently did nothing, and no test
 * looked. A literal path in a `.js` or `.vue` file is a contract with the
 * router that nothing else checks.
 *
 * Static on purpose: it costs one router lookup per path and catches the
 * removal at the moment it happens, rather than when someone next tries to
 * upload a picture.
 */
final class FrontendRouteReferencesTest extends IntegrationTestCase
{
    /**
     * Literal `/backend/...` paths, quoted, with no interpolation. Templated
     * ones (`/backend/x/{id}`) are built by `buildPath` at runtime and are not
     * this test's business.
     */
    private const string BACKEND_PATH = '#["\'](/backend/[a-z0-9/-]+)["\']#i';

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function frontendFiles(): iterable
    {
        $root = dirname(__DIR__, 2).'/src';

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (!$file->isFile() || !in_array($file->getExtension(), ['js', 'vue'], true)) {
                continue;
            }

            // Test files quote paths to assert against them, which is not the
            // same as depending on them.
            if (str_contains($file->getFilename(), '.test.')) {
                continue;
            }

            $contents = self::withoutComments((string) file_get_contents($file->getPathname()));

            preg_match_all(self::BACKEND_PATH, $contents, $matches);

            $paths = array_values(array_unique($matches[1]));

            if ([] === $paths) {
                continue;
            }

            yield str_replace(dirname(__DIR__, 2).'/', '', $file->getPathname()) => [$file->getPathname(), $paths];
        }
    }

    /**
     * Docblocks quote example paths — `buildPath` documents itself with one —
     * and an example is not a dependency. Block comments and whole-line `//`
     * only: stripping to the end of any `//` would eat a real path sitting
     * after a `https://` in the same line.
     */
    private static function withoutComments(string $contents): string
    {
        $contents = (string) preg_replace('#/\*.*?\*/#s', '', $contents);

        return (string) preg_replace('#^\s*//.*$#m', '', $contents);
    }

    /**
     * @param list<string> $paths
     */
    #[DataProvider('frontendFiles')]
    public function testEveryLiteralBackendPathMatchesARoute(string $file, array $paths): void
    {
        $routes = static::getContainer()->get(RouterInterface::class)->getRouteCollection();

        $known = [];
        foreach ($routes as $route) {
            $known[$route->getPath()] = true;
        }

        foreach ($paths as $path) {
            self::assertArrayHasKey(
                $path,
                $known,
                sprintf(
                    '%s posts to %s, which matches no route. A dead path fails silently: '
                    .'the request 404s and the feature simply stops working.',
                    $file,
                    $path,
                ),
            );
        }
    }
}
