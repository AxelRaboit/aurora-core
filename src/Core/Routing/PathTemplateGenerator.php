<?php

declare(strict_types=1);

namespace Aurora\Core\Routing;

use Symfony\Component\Routing\Generator\ConfigurableRequirementsInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Router;

/**
 * A URL with a hole in it: `/suite/editorial/posts/__id__/edit`.
 *
 * A Vue app is handed a path it will complete at click time, so the server has
 * to generate one from a route whose `id` is declared `\d+`. The generator
 * refuses, because `__id__` is not a number, and it refuses *while rendering
 * the page* - so the screen answers 500 before anyone reaches the endpoint the
 * requirement was protecting.
 *
 * That was previously worked around by loosening the requirement itself, which
 * cost more than it bought. It had to be repeated on every route, it was got
 * wrong three times (a constant spelled `__id__` copied onto a `revisionId`
 * parameter), and it made the loosened routes match real inbound requests:
 * asking for `/posts/__id__/edit` stopped being a clean 404 and became a
 * database error, because `__id__` reached Doctrine as an identifier.
 *
 * Symfony already separates the two concerns. Requirements are enforced both
 * when matching a request and when generating a URL, and
 * {@see ConfigurableRequirementsInterface} turns off only the second. So routes
 * keep their honest `\d+` - junk in the URL bar is rejected by the router, as
 * it should be - and generation is relaxed here, for the one call that means to
 * leave a hole.
 *
 * A service and not only a Twig function, because half the screens build their
 * paths in a view builder rather than in a template. The Studio module did,
 * called `generate()` straight, and its three screens answered 500 in dev and
 * in test for as long as the module existed - production hid it, because
 * `strict_requirements: null` there generates the hole without complaining.
 * A defect that only appears where the developers are is the worst place to
 * put one.
 */
final readonly class PathTemplateGenerator
{
    public function __construct(private UrlGeneratorInterface $urlGenerator) {}

    /** @param array<string, mixed> $parameters */
    public function generate(string $route, array $parameters = []): string
    {
        $generator = $this->configurableGenerator();

        if (!$generator instanceof ConfigurableRequirementsInterface) {
            return $this->urlGenerator->generate($route, $parameters);
        }

        $strict = $generator->isStrictRequirements();

        // `null` means "generate anyway, check nothing" - as opposed to
        // `false`, which returns an empty string and logs.
        $generator->setStrictRequirements(null);

        try {
            return $this->urlGenerator->generate($route, $parameters);
        } finally {
            // Restored even if generation throws for another reason: the
            // generator is shared for the whole request, and leaving it
            // permissive would silently disable requirement checking on every
            // URL built afterwards.
            $generator->setStrictRequirements($strict);
        }
    }

    /**
     * The object that actually holds the flag.
     *
     * `Router` is what gets injected, and it is not itself configurable - it
     * delegates to an inner generator built on demand and configured from its
     * own options. Testing the injected service directly therefore always
     * failed, silently, and the relaxation never happened.
     */
    private function configurableGenerator(): ?UrlGeneratorInterface
    {
        if ($this->urlGenerator instanceof ConfigurableRequirementsInterface) {
            return $this->urlGenerator;
        }

        return $this->urlGenerator instanceof Router
            ? $this->urlGenerator->getGenerator()
            : null;
    }
}
