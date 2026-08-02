<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Seo\Service;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The `robots.txt` a crawler reads before anything else.
 *
 * The disallow list is derived from where the private area actually is,
 * because getting it wrong is silent in both directions: a path that does
 * not exist blocks nothing, and the administration it was meant to cover
 * stays open to indexing. The reference this was rebuilt from disallowed
 * `/admin/` and `/dev/` on an application whose backend has always lived
 * under `/backend/` — so every admin screen was announced as crawlable, and
 * the file looked correct while doing the opposite of its job.
 */
final readonly class RobotsTxtBuilder
{
    /**
     * URL prefixes no crawler should follow. Paths, not route names:
     * robots.txt speaks URLs.
     *
     * @var list<string>
     */
    private const array DISALLOWED = [
        '/backend/',
        '/_profiler/',
    ];

    public function __construct(private UrlGeneratorInterface $urlGenerator) {}

    public function build(): string
    {
        $lines = ['User-agent: *'];

        foreach (self::DISALLOWED as $path) {
            $lines[] = 'Disallow: '.$path;
        }

        // Absolute, because robots.txt is read on its own and a relative
        // Sitemap line is ignored by every crawler that follows the spec.
        //
        // Generated from the request rather than from the `site_url` setting:
        // the host in the crawler's address bar is the one it should keep
        // using, and `site_url` ships defaulted to `http://localhost` — a
        // deploy that never changed it would otherwise announce a sitemap on
        // a host nobody can reach, which is the same as announcing none.
        $lines[] = '';
        $lines[] = 'Sitemap: '.$this->urlGenerator->generate(
            'editorial_sitemap',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        return implode("\n", $lines)."\n";
    }
}
