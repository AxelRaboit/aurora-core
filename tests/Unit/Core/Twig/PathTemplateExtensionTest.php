<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Core\Twig;

use Aurora\Core\Twig\PathTemplateExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Loader\ClosureLoader;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Router;
use Throwable;

/**
 * The extension is built around a `Router`, so the test has to be too.
 *
 * This was got wrong once in exactly the way a mock would not have caught: the
 * relaxation was applied to the injected service, which passes for a bare
 * {@see UrlGenerator} - it is configurable - and silently does nothing for a
 * {@see Router}, which is what the container actually injects and which keeps
 * its generator one level down. The unit test looked right; every backend
 * screen answered 500.
 *
 * So the fixture is a real `Router` over a real route with a real `\d+`
 * requirement. Nothing here is doubled.
 */
final class PathTemplateExtensionTest extends TestCase
{
    /** The bug: a `Router` is not itself configurable, and generation threw. */
    public function testLeavesAHoleInAConstrainedParameter(): void
    {
        $extension = new PathTemplateExtension($this->router());

        self::assertSame(
            '/posts/__id__/edit',
            $extension->pathTemplate('post_edit', ['id' => '__id__']),
        );
    }

    /** Several holes, and a route that constrains only some of them. */
    public function testLeavesAHoleInEveryPlaceholder(): void
    {
        $extension = new PathTemplateExtension($this->router());

        self::assertSame(
            '/posts/__id__/revisions/__revisionId__',
            $extension->pathTemplate('post_revision', ['id' => '__id__', 'revisionId' => '__revisionId__']),
        );
    }

    /** Real values still go through, unchanged. */
    public function testGeneratesAnOrdinaryUrl(): void
    {
        $extension = new PathTemplateExtension($this->router());

        self::assertSame('/posts/12/edit', $extension->pathTemplate('post_edit', ['id' => 12]));
    }

    /**
     * The generator is shared for the whole request. If the relaxation leaked,
     * every URL built after the first `path_template` call would skip its
     * requirement check - the template helper would quietly disable routing
     * validation sitewide.
     */
    public function testRestoresRequirementCheckingAfterwards(): void
    {
        $router = $this->router();
        (new PathTemplateExtension($router))->pathTemplate('post_edit', ['id' => '__id__']);

        $this->expectExceptionMessage('must match "\d+"');
        $router->generate('post_edit', ['id' => 'nope']);
    }

    /** Including when generation fails for an unrelated reason. */
    public function testRestoresRequirementCheckingAfterAFailure(): void
    {
        $router = $this->router();
        $extension = new PathTemplateExtension($router);

        try {
            $extension->pathTemplate('no_such_route', ['id' => '__id__']);
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
