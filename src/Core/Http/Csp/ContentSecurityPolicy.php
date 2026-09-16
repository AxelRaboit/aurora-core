<?php

declare(strict_types=1);

namespace Aurora\Core\Http\Csp;

use Aurora\Core\Content\RawHtmlSanitizer;

use function array_map;
use function implode;
use function sprintf;

/**
 * The policy a browser is handed with every HTML page.
 *
 * The third wall, under the two the upload audit put up: an allow-list decides
 * what may be stored, `BinaryFileServer` decides what may be rendered as a
 * document, and this decides what a page may run at all. Each covers what the
 * others cannot - the first two say nothing about a script injected into a
 * post's body or a reflected parameter.
 *
 * **Scripts by nonce, styles by `unsafe-inline`, and the asymmetry is not
 * laziness.** A handful of scripts have to run before the first paint - the
 * theme, so the page does not flash white, and the globals every staff screen
 * reads - and they carry the nonce. Styles cannot: Vue injects a component's
 * styles from JavaScript at runtime, and an injected tag carries no nonce.
 * Worse, a nonce in `style-src` makes browsers ignore `unsafe-inline`
 * altogether, so adding one there would break every styled component. The
 * exposure is also not the same: an injected style can reskin a page, an
 * injected script can act as the person reading it.
 *
 * **`img-src` admits any HTTPS origin**, because a post's body legitimately
 * embeds pictures from elsewhere and the Pexels importer previews them. An
 * image is data the browser draws, not code it runs.
 *
 * **`frame-src` is read from {@see RawHtmlSanitizer::IFRAME_HOSTS}** rather
 * than written again here. That list already decides which embeds survive
 * being saved; two lists would have drifted, and the drift would have shown up
 * as an embed that saves and then refuses to load.
 *
 * `'self'` is in `frame-src` too: a document's own preview is an `<iframe>`
 * pointing at `/uploads/…`.
 */
final readonly class ContentSecurityPolicy
{
    public function __construct(
        private bool $devMode = false,
        private string $viteDevServer = 'http://localhost:5173',
    ) {}

    public function header(?string $nonce): string
    {
        $script = ["'self'"];
        $connect = ["'self'"];

        if (null !== $nonce) {
            $script[] = sprintf("'nonce-%s'", $nonce);
        }

        if ($this->devMode) {
            // Vite serves modules from its own origin in development and keeps
            // a socket open for hot reload. Neither exists in a build, which is
            // why this is not simply always allowed.
            $script[] = $this->viteDevServer;
            $connect[] = $this->viteDevServer;
            $connect[] = str_replace('http', 'ws', $this->viteDevServer);
        }

        $directives = [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            // Nothing embeds an Aurora page, and a page that cannot be framed
            // cannot be clickjacked.
            "frame-ancestors 'none'",
            "form-action 'self'",
            'script-src '.implode(' ', $script),
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob: https:",
            "font-src 'self' data:",
            "media-src 'self' blob:",
            'connect-src '.implode(' ', $connect),
            'frame-src '.implode(' ', ["'self'", ...$this->embedOrigins()]),
            "worker-src 'self' blob:",
        ];

        return implode('; ', $directives);
    }

    /**
     * @return list<string>
     */
    private function embedOrigins(): array
    {
        return array_map(
            static fn (string $host): string => 'https://'.$host,
            RawHtmlSanitizer::IFRAME_HOSTS,
        );
    }
}
