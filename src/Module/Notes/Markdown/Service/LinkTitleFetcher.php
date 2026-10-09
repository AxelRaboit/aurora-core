<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Service;

use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * The title of a web page, for an address pasted into a note (09/10/2026).
 *
 * Pasting `https://…` writes `[https://…](https://…)`; the editor then asks
 * here for the page's title and puts it in place of the address, as Notion
 * and Craft do.
 *
 * **The server fetches an address somebody typed, so it fetches carefully.**
 * Through `NoPrivateNetworkHttpClient`, so a pasted `http://127.0.0.1:…` or an
 * address on the server's own network is refused, redirections included;
 * http and https only; a few seconds at most; HTML only; and no more than the
 * head of the page is read. Any failure answers "no title", and the address
 * simply stays as it was pasted.
 */
final readonly class LinkTitleFetcher
{
    private const int MAX_BYTES = 262_144;

    private const int MAX_LENGTH = 200;

    public function __construct(private HttpClientInterface $httpClient) {}

    public function titleOf(string $url): ?string
    {
        if (!self::isFetchable($url)) {
            return null;
        }

        $client = new NoPrivateNetworkHttpClient($this->httpClient);

        try {
            $response = $client->request('GET', $url, [
                'timeout' => 4,
                'max_duration' => 6,
                'max_redirects' => 3,
                'headers' => [
                    'Accept' => 'text/html,application/xhtml+xml',
                    'User-Agent' => 'Mozilla/5.0 (compatible; AuroraLinkTitle/1.0)',
                ],
            ]);

            if (200 !== $response->getStatusCode()) {
                return null;
            }

            $type = $response->getHeaders(false)['content-type'][0] ?? '';
            if (!str_contains(mb_strtolower($type), 'html')) {
                return null;
            }

            $html = '';
            foreach ($client->stream($response) as $chunk) {
                $html .= $chunk->getContent();
                if (mb_strlen($html, '8bit') >= self::MAX_BYTES || str_contains(mb_strtolower($html), '</head>')) {
                    break;
                }
            }
            $response->cancel();

            return self::extractTitle($html);
        } catch (Throwable) {
            return null;
        }
    }

    public static function isFetchable(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        return in_array(mb_strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && '' !== ($parts['host'] ?? '');
    }

    /**
     * The page's own name for itself: its Open Graph title first, which sites
     * write for exactly this, then its `<title>`.
     */
    public static function extractTitle(string $html): ?string
    {
        $title = null;

        if (1 === preg_match('/<meta[^>]+property=["\']og:title["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $matches)
            || 1 === preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]*property=["\']og:title["\']/i', $html, $matches)) {
            $title = $matches[1];
        } elseif (1 === preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
            $title = $matches[1];
        }

        if (null === $title) {
            return null;
        }

        $title = mb_trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ('' === $title) {
            return null;
        }

        return mb_substr($title, 0, self::MAX_LENGTH);
    }
}
