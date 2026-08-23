<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit;

use Aurora\AuroraBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Whether a *client* project gets the bundle's error pages.
 *
 * This cannot be observed from inside aurora-core. Here the package is the
 * application, so Symfony's own bundle-override convention already makes
 * `@Twig/Exception/error404.html.twig` resolve, and every check - the files
 * exist, Twig loads the name, a 404 renders - comes out green whether or not
 * the bundle does anything. Meanwhile every client project fell back to
 * Symfony's bare error page. That is the whole defect, and only a run with a
 * different `kernel.project_dir` can see it.
 *
 * So this drives `prependExtension()` directly, once for a project that ships
 * its own error pages and once for a project that does not.
 */
final class AuroraBundleTwigPathsTest extends TestCase
{
    public function testTheBundleShipsItsErrorPagesToAProjectWithNone(): void
    {
        $paths = $this->twigPathsForProject(sys_get_temp_dir().'/aurora-project-without-overrides');

        self::assertContains(
            'Twig',
            $paths,
            'no @Twig path: a client project falls back to Symfony\'s bare error page.',
        );
    }

    /**
     * A project's own pages must win, and Twig resolves a namespace by first
     * matching path - with user-configured paths registered *before* the
     * per-bundle override paths. Registering ours unconditionally would
     * therefore silently outrank the client's.
     */
    public function testTheBundleStandsAsideForAProjectThatShipsItsOwn(): void
    {
        // dirname(__DIR__, 2) is this package, which does ship them.
        $paths = $this->twigPathsForProject(dirname(__DIR__, 2));

        self::assertNotContains('Twig', $paths);
    }

    /** @return list<string|null> the namespace of every registered Twig path */
    private function twigPathsForProject(string $projectDir): array
    {
        $builder = new ContainerBuilder();
        $builder->setParameter('kernel.project_dir', $projectDir);

        // prependExtension() never touches the configurator; it writes through
        // the builder. A real one would need a loader and a file it can import.
        $configurator = new class extends ContainerConfigurator {
            public function __construct() {}
        };

        (new AuroraBundle())->prependExtension($configurator, $builder);

        $twig = $builder->getExtensionConfig('twig');

        /** @var array<string, string|null> $paths */
        $paths = $twig[0]['paths'] ?? [];

        return array_values($paths);
    }
}
