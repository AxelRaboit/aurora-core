<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Core\Frontend;

use Aurora\Core\Frontend\Service\Context;
use Aurora\Core\Locale\Repository\LocaleRepository;
use Aurora\Core\Locale\Service\LocaleContextInterface;
use Aurora\Module\Configuration\Setting\Entity\Setting;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

/**
 * Every public absolute URL is built on this: canonical tags, hreflang,
 * og:image, the sitemap.
 *
 * The `site_url` parameter ships seeded with `http://localhost`, and reading
 * that back as though an administrator had chosen it is how a production
 * deploy ends up telling search engines the canonical address of every page
 * is a host they cannot reach. Nothing in the application reports it - the
 * pages render, the tags are well-formed, and the only symptom is the site
 * failing to rank.
 *
 * The setting still wins when it has actually been set: pinning one host is
 * the point of having it, and a site answering on both `example.com` and
 * `www.example.com` needs its canonical URLs to agree on one.
 */
final class ContextSiteUrlTest extends TestCase
{
    public function testAConfiguredSiteUrlWinsOverTheRequest(): void
    {
        self::assertSame(
            'https://example.org',
            $this->context('https://example.org', 'https://staging.internal')->siteUrl(),
        );
    }

    public function testTheShippedPlaceholderLosesToTheRequest(): void
    {
        self::assertSame(
            'https://example.org',
            $this->context('http://localhost', 'https://example.org')->siteUrl(),
        );
    }

    public function testAnUnsetSiteUrlFallsBackToTheRequest(): void
    {
        self::assertSame('https://example.org', $this->context('', 'https://example.org')->siteUrl());
    }

    public function testATrailingSlashIsNeverCarriedIntoTheUrlsBuiltOnIt(): void
    {
        self::assertSame('https://example.org', $this->context('https://example.org/', null)->siteUrl());
    }

    /**
     * On the command line there is no request to learn from, but the routing
     * context carries DEFAULT_URI, which the deployment already had to set for
     * the router to build absolute URLs at all. A Messenger worker rendering a
     * canonical tag therefore names the real host.
     */
    public function testTheRoutingContextAnswersWhenThereIsNoRequest(): void
    {
        self::assertSame('https://aurora.test', $this->context('', null, 'aurora.test')->siteUrl());
    }

    public function testTheShippedPlaceholderAlsoLosesToTheRoutingContext(): void
    {
        // Une installation anterieure porte encore la valeur semee. Personne
        // ne l'a choisie : elle ne doit pas gagner sur DEFAULT_URI non plus.
        self::assertSame('https://aurora.test', $this->context('http://localhost', null, 'aurora.test')->siteUrl());
    }

    public function testANonStandardPortSurvivesTheRoutingContext(): void
    {
        self::assertSame('http://aurora.test', $this->context('', null, 'aurora.test', 'http')->siteUrl());
    }

    /**
     * Nothing configured anywhere: no setting, no request, no DEFAULT_URI. The
     * placeholder is wrong, but an empty origin would produce URLs that are
     * wrong in a harder-to-spot way.
     */
    public function testFallsBackToThePlaceholderWhenEvenTheRoutingContextIsEmpty(): void
    {
        self::assertSame('http://localhost', $this->context('http://localhost', null)->siteUrl());
        self::assertSame('http://localhost', $this->context('', null)->siteUrl());
    }

    private function context(string $configured, ?string $requestOrigin, ?string $routingHost = null, string $routingScheme = 'https'): Context
    {
        $settingRepository = $this->createStub(SettingRepository::class);
        $settingRepository->method('get')->willReturnCallback(
            static fn (string $key, ?string $default = null): ?string => 'site_url' === $key ? $configured : $default,
        );
        $settingRepository->method('findOneBy')->willReturn(
            '' === $configured ? null : new Setting()->setKey('site_url')->setValue($configured),
        );

        $requestStack = new RequestStack();
        if (null !== $requestOrigin) {
            $requestStack->push(Request::create($requestOrigin.'/fr'));
        }

        // Le contexte de routage, que le framework remplit depuis DEFAULT_URI.
        // Hote vide = rien de configure au deploiement.
        $routingContext = new RequestContext();
        $routingContext->setHost($routingHost ?? '');
        $routingContext->setScheme($routingScheme);

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('getContext')->willReturn($routingContext);

        return new Context(
            $this->createStub(LocaleRepository::class),
            $settingRepository,
            $this->createStub(LocaleContextInterface::class),
            $requestStack,
            $urlGenerator,
        );
    }
}
