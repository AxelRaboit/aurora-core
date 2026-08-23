<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration\Theme\Controller\Public;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Enum\HttpStatusEnum;
use Aurora\Module\Configuration\Theme\Service\ThemeContext;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves a favicon SVG tinted with the active theme primary colour.
 *
 * Replaces the static {@code public/favicon.svg} with a dynamic version so the browser tab
 * icon follows the admin's chosen primary colour. We don't go through the normal Symfony
 * controller route lookup chain - this route is registered with high priority so it takes
 * precedence over the static file (which the symfony local server would serve otherwise).
 *
 * Cache-Control + ETag based on the colour mean the browser only re-fetches when the admin
 * actually changes the primary colour.
 */
final readonly class FaviconController
{
    public function __construct(private ThemeContext $themeContext) {}

    #[Route('/favicon.svg', name: 'favicon', methods: [HttpMethodEnum::Get->value])]
    public function __invoke(): Response
    {
        $hex = mb_ltrim($this->themeContext->primaryColor(), '#');
        // Default accent hue if an invalid hex slipped through
        if (6 !== mb_strlen($hex) || !ctype_xdigit($hex)) {
            $hex = '6366f1';
        }

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
            .'<defs><linearGradient id="bg" x1="0%%" y1="0%%" x2="100%%" y2="100%%">'
            .'<stop offset="0%%" style="stop-color:#%s;stop-opacity:1"/>'
            .'<stop offset="100%%" style="stop-color:#%s;stop-opacity:0.85"/>'
            .'</linearGradient></defs>'
            .'<rect width="64" height="64" rx="14" fill="url(#bg)"/>'
            .'<text x="32" y="45" font-family="\'Inter\', \'Segoe UI\', sans-serif" font-size="36" font-weight="700" text-anchor="middle" fill="white">V</text>'
            .'<line x1="20" y1="52" x2="44" y2="52" stroke="rgba(255,255,255,0.4)" stroke-width="2.5" stroke-linecap="round"/>'
            .'</svg>',
            $hex,
            $hex,
        );

        $response = new Response($svg, HttpStatusEnum::Ok->value, [
            'Content-Type' => 'image/svg+xml',
            // Public + ETag - the browser re-fetches only when the colour changes.
            'Cache-Control' => 'public, max-age=300, must-revalidate',
        ]);
        $response->setEtag(md5($hex));
        $response->setPublic();

        return $response;
    }
}
