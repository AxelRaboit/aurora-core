<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Core\Routing;

use Aurora\Core\Routing\PathTemplateGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Loader\ClosureLoader;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Router;
use Throwable;

/**
 * The generator is built around a `Router`, so the test has to be too.
 *
 * This was got wrong once in exactly the way a mock would not have caught: the
 * relaxation was applied to the injected service, which passes for a bare
 * {@see UrlGenerator} - it is configurable - and silently does nothing for a
 * {@see Router}, which is what the container actually injects and which keeps
 * its generator one level down. The unit test looked right; every suite
 * screen answered 500.
 *
 * So the fixture is a real `Router` over a real route with a real `\d+`
 * requirement. Nothing here is doubled.
 */
final class PathTemplateGeneratorTest extends TestCase
{
    /** The bug: a `Router` is not itself configurable, and generation threw. */
    public function testLeavesAHoleInAConstrainedParameter(): void
    {
        $paths = new PathTemplateGenerator($this->router());

        self::assertSame(
            '/posts/__id__/edit',
            $paths->generate('post_edit', ['id' => '__id__']),
        );
    }

    /** Several holes, and a route that constrains only some of them. */
    public function testLeavesAHoleInEveryPlaceholder(): void
    {
        $paths = new PathTemplateGenerator($this->router());

        self::assertSame(
            '/posts/__id__/revisions/__revisionId__',
            $paths->generate('post_revision', ['id' => '__id__', 'revisionId' => '__revisionId__']),
        );
    }

    /** Real values still go through, unchanged. */
    public function testGeneratesAnOrdinaryUrl(): void
    {
        $paths = new PathTemplateGenerator($this->router());

        self::assertSame('/posts/12/edit', $paths->generate('post_edit', ['id' => 12]));
    }

    /**
     * The generator is shared for the whole request. If the relaxation leaked,
     * every URL built after the first call would skip its requirement check -
     * one path with a hole in it would quietly disable routing validation
     * sitewide.
     */
    public function testRestoresRequirementCheckingAfterwards(): void
    {
        $router = $this->router();
        (new PathTemplateGenerator($router))->generate('post_edit', ['id' => '__id__']);

        $this->expectExceptionMessage('must match "\d+"');
        $router->generate('post_edit', ['id' => 'nope']);
    }

    /** Including when generation fails for an unrelated reason. */
    public function testRestoresRequirementCheckingAfterAFailure(): void
    {
        $router = $this->router();
        $paths = new PathTemplateGenerator($router);

        try {
            $paths->generate('no_such_route', ['id' => '__id__']);
        } catch (Throwable) {
            // The missing route is not what is under test.
        }

        $this->expectExceptionMessage('must match "\d+"');
        $router->generate('post_edit', ['id' => 'nope']);
    }

    private function router(): Router
    {
        $routes = new RouteCollection();
        $routes->add('post_edit', new Route('/posts/{id}/edit', requirements: ['id' => '\d+']));
        $routes->add('post_revision', new Route(
            '/posts/{id}/revisions/{revisionId}',
            requirements: ['id' => '\d+', 'revisionId' => '\d+'],
        ));

        $router = new Router(
            new ClosureLoader(),
            static fn (): RouteCollection => $routes,
            ['generator_class' => UrlGenerator::class],
        );
        $router->setContext(new RequestContext());

        return $router;
    }
}
