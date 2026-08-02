<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Seo\Controller\Frontend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Enum\HttpStatusEnum;
use Aurora\Core\Frontend\Service\Context;
use Aurora\Module\Editorial\Seo\Service\RobotsTxtBuilder;
use Aurora\Module\Editorial\Seo\Service\RssFeedService;
use Aurora\Module\Editorial\Seo\Service\SitemapService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * What crawlers and feed readers ask for.
 *
 * The priorities matter: `/sitemap.xml` and `/robots.txt` are two-segment-
 * free paths that `/{locale}` would otherwise swallow, and `/{locale}/feed.xml`
 * competes with `/{locale}/{postTypeSlug}`. Losing that race turns each of
 * these into a 404 that nothing in the application reports.
 */
class SitemapController extends AbstractController
{
    public function __construct(
        private readonly SitemapService $sitemapService,
        private readonly RssFeedService $rssFeedService,
        private readonly RobotsTxtBuilder $robotsTxtBuilder,
        private readonly Context $context,
    ) {}

    #[Route('/sitemap.xml', name: 'editorial_sitemap', methods: [HttpMethodEnum::Get->value], priority: 11)]
    public function sitemap(): Response
    {
        return new Response(
            $this->sitemapService->getData()->xml,
            HttpStatusEnum::Ok->value,
            ['Content-Type' => 'application/xml; charset=UTF-8'],
        );
    }

    #[Route('/robots.txt', name: 'editorial_robots', methods: [HttpMethodEnum::Get->value], priority: 11)]
    public function robots(): Response
    {
        return new Response(
            $this->robotsTxtBuilder->build(),
            HttpStatusEnum::Ok->value,
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }

    #[Route('/{locale}/feed.xml', name: 'editorial_rss', requirements: ['locale' => '[a-z]{2}'], methods: [HttpMethodEnum::Get->value], priority: 12)]
    public function rss(string $locale): Response
    {
        if (!$this->context->isLocaleActive($locale)) {
            throw $this->createNotFoundException();
        }

        return new Response(
            $this->rssFeedService->getXml($locale),
            HttpStatusEnum::Ok->value,
            ['Content-Type' => 'application/rss+xml; charset=UTF-8'],
        );
    }
}
