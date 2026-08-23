<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Seo\Twig;

use Aurora\Module\Editorial\EditorialContext;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * `feed_url(locale)` - the address a browser or reader should subscribe to,
 * or null when there is nothing to subscribe to.
 *
 * Null rather than an exception for every "no": SEO switched off, the route
 * absent because Editorial is not installed. The head template then guards on
 * null alone and never on which modules exist - the same arrangement as
 * `menu_items()`. Hard-coding the route in Core's head is what left the
 * shared layout unable to boot without Editorial the last time these two were
 * split apart.
 */
final readonly class FeedExtension
{
    public function __construct(
        private EditorialContext $editorialContext,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    #[AsTwigFunction(name: 'feed_url')]
    public function feedUrl(string $locale): ?string
    {
        if (!$this->editorialContext->isSeoEnabled()) {
            return null;
        }

        try {
            return $this->urlGenerator->generate('editorial_rss', ['locale' => $locale]);
        } catch (RouteNotFoundException) {
            return null;
        }
    }
}
