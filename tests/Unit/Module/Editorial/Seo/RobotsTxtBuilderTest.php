<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\Seo;

use Aurora\Module\Editorial\Seo\Service\RobotsTxtBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * robots.txt fails silently in both directions, which is why it gets a test
 * rather than a read-through.
 *
 * The reference this was rebuilt from disallowed `/admin/` and `/dev/` on an
 * application whose backend has always been at `/backend/` - so the file
 * blocked nothing that exists and left every admin screen announced as
 * crawlable. Nothing would have reported that: the file was well-formed, the
 * route answered 200, and the only symptom was admin URLs turning up in
 * search results weeks later.
 */
final class RobotsTxtBuilderTest extends TestCase
{
    public function testDisallowsTheBackendWhereItActuallyLives(): void
    {
        $robots = $this->build();

        self::assertStringContainsString('Disallow: /backend/', $robots);
        self::assertStringNotContainsString('/admin/', $robots);
    }

    /**
     * `site_url` ships defaulted to `http://localhost`. Announcing the sitemap
     * there is the same as announcing none, so the line has to come from the
     * host the crawler actually used.
     */
    public function testPointsAtTheSitemapOnTheRequestedHost(): void
    {
        self::assertStringContainsString(
            'Sitemap: https://example.org/sitemap.xml',
            $this->build('https://example.org/sitemap.xml'),
        );
    }

    public function testEndsWithANewlineAndOpensWithTheUserAgentLine(): void
    {
        $robots = $this->build();

        self::assertStringStartsWith("User-agent: *\n", $robots);
        self::assertStringEndsWith("\n", $robots);
    }

    private function build(string $sitemapUrl = 'https://example.org/sitemap.xml'): string
    {
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn($sitemapUrl);

        return new RobotsTxtBuilder($urlGenerator)->build();
    }
}
