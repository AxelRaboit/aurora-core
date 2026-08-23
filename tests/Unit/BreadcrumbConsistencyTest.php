<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function dirname;
use function preg_match;
use function preg_match_all;
use function sprintf;
use function str_contains;
use function str_replace;

/**
 * Every backend page's trail, checked against the shape the header can render.
 *
 * A crumb is `{label: …}` plus an optional `href:`. Those are the only two keys
 * `page_header.html.twig` reads, and anything else is silently ignored - the
 * post edit page spent its life writing `path:` instead of `href:`, so the
 * "Publications" level above it was plain text that looked like a link's
 * neighbour and did nothing. Nothing failed. That is why this is asserted.
 *
 * The first crumb has to be a section, because the navigation is exactly two
 * levels deep everywhere: `NavItem` accepts children and no module passes any,
 * so a page is either a section's item or the detail of one. Two profile pages
 * used to start at themselves, which left them the only trails in the backend
 * with no idea where they sat.
 *
 * Read from the templates rather than from rendered pages because the point is
 * coverage: an audit of nineteen files answers "do they all", where a
 * controller test answers "does this one". `PageHeaderTest` covers the render.
 */
final class BreadcrumbConsistencyTest extends TestCase
{
    /** The keys the header actually reads. Anything else is dropped in silence. */
    private const array ALLOWED_KEYS = ['label', 'href'];

    private const string TEMPLATE = 'page_header.html.twig';

    /**
     * @return iterable<string, array{string}>
     */
    public static function backendPages(): iterable
    {
        $root = dirname(__DIR__, 2).'/src';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if (!$file->isFile() || 'twig' !== $file->getExtension()) {
                continue;
            }

            $path = $file->getPathname();
            $contents = (string) file_get_contents($path);

            // The header's own file, and the layout that only declares the slot.
            if (str_contains($path, self::TEMPLATE) || !str_contains($contents, self::TEMPLATE)) {
                continue;
            }

            if (!str_contains($contents, 'crumbs:')) {
                continue;
            }

            yield str_replace($root.'/', '', $path) => [$path];
        }
    }

    /**
     * @return list<string>
     */
    private function crumbsOf(string $path): array
    {
        $contents = (string) file_get_contents($path);
        preg_match_all('/\{label:.*$/m', $contents, $matches);

        return $matches[0];
    }

    #[DataProvider('backendPages')]
    public function testEveryPageDeclaresItsTrail(string $path): void
    {
        self::assertNotEmpty(
            $this->crumbsOf($path),
            'A page that includes the header without crumbs has no trail, and the header has nothing to name it with.',
        );
    }

    /**
     * `path:` reads as correct and does nothing. So does `url:`, or `route:`.
     */
    #[DataProvider('backendPages')]
    public function testALinkingCrumbUsesTheKeyTheHeaderReads(string $path): void
    {
        foreach ($this->crumbsOf($path) as $crumb) {
            preg_match_all('/(?:\{|,)\s*([a-zA-Z_]+):/', $crumb, $keys);

            foreach ($keys[1] as $key) {
                self::assertContains(
                    $key,
                    self::ALLOWED_KEYS,
                    sprintf('`%s:` is not read by the header - the crumb renders, the link does not.', $key),
                );
            }
        }
    }

    /**
     * The navigation is section → item, with no third level anywhere, so every
     * trail starts at a section. A trail that starts at the page itself cannot
     * say where the page sits.
     */
    #[DataProvider('backendPages')]
    public function testTheTrailStartsAtASection(string $path): void
    {
        $first = $this->crumbsOf($path)[0] ?? '';

        self::assertSame(
            1,
            preg_match("/\{label: 'backend\.nav\.sections\.[a-z_]+'\|trans\}/", $first),
            sprintf('The first crumb is `%s`, which is not a section.', $first),
        );
    }
}
