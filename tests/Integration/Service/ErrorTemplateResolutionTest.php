<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Service;

use Aurora\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Twig\Environment;

/**
 * The bundle's error pages have to be reachable under the name Symfony asks for.
 *
 * `TwigErrorRenderer` looks up `@Twig/Exception/error<status>.html.twig`, and
 * `templates/bundles/TwigBundle/` is the convention for an *application*
 * overriding a bundle. So these pages resolved while developing aurora-core —
 * where the package is the application — and resolved nowhere else. Every
 * client project fell back to Symfony's bare "Oops! An Error Occurred", and no
 * amount of looking at aurora-core would have shown it.
 *
 * Checking that the files exist proves nothing, and rendering a 404 through the
 * test client proves nothing either: under CLI, and with debug on, the error
 * renderer never reaches for a template at all. The only question that
 * distinguishes the two worlds is whether Twig can load the name.
 */
final class ErrorTemplateResolutionTest extends IntegrationTestCase
{
    /** @return iterable<string, array{string}> */
    public static function templateProvider(): iterable
    {
        yield 'generic' => ['@Twig/Exception/error.html.twig'];
        yield 'not found' => ['@Twig/Exception/error404.html.twig'];
        yield 'server error' => ['@Twig/Exception/error500.html.twig'];
    }

    #[DataProvider('templateProvider')]
    public function testTheErrorPageResolves(string $template): void
    {
        $twig = static::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);

        self::assertTrue(
            $twig->getLoader()->exists($template),
            sprintf('"%s" does not resolve, so production falls back to Symfony\'s bare error page.', $template),
        );
    }
}
