<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration\Theme\Controller\Public;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Enum\HttpStatusEnum;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Configuration\Theme\Service\ThemeContext;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\Document\Service\DocumentUrlGenerator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Answers `/favicon.svg`, which every layout of the product asks for, and
 * `/favicon.ico`, which no layout asks for and browsers fetch anyway.
 *
 * **The `.ico` path is not decoration.** A browser that has not yet parsed the
 * page - or that is showing an error page, or a route with no layout at all -
 * requests `/favicon.ico` on its own. With no route for it, each of those
 * became a `NotFoundHttpException` logged at ERROR level with a stack trace:
 * 2 071 of them in thirty-one days of production logs, around seventy a day,
 * for a file nobody was missing. Real errors were sitting in that haystack.
 *
 * The answer is the same for both paths, including the drawn fallback, which
 * is an SVG served under a `.ico` address. Browsers sniff the bytes rather
 * than trusting the extension, so they render it; and lying about the content
 * type to match the extension would be the one thing that could break it.
 *
 * Two answers, in this order:
 *
 *  - the picture chosen on the Branding screen, when there is one. That
 *    setting existed and was read by exactly one template - the photo gallery
 *    layout - so an admin who uploaded their favicon saw it on one page and
 *    the generated one everywhere else. Served as a redirect rather than as
 *    bytes: the file is a PNG or an ICO as often as an SVG, and a redirect
 *    lets the browser fetch it with its own content type instead of this
 *    route lying about it;
 *  - failing that, a letter on the theme's own colour. The letter is the
 *    site's initial, not a fixed one: this file used to draw a hard-coded
 *    "V", left over from the product's first name, so every site delivered
 *    since - and this one - carried a stranger's initial in its browser tab.
 *
 * The ETag covers both what is drawn and where it points, so a browser
 * re-fetches when the colour, the name or the uploaded file changes, and not
 * otherwise.
 */
final readonly class FaviconController
{
    /** What is drawn when the site has no name to take an initial from. */
    private const string FALLBACK_INITIAL = 'A';

    private const string FALLBACK_COLOR = '10b981';

    public function __construct(
        private ThemeContext $themeContext,
        private SettingRepository $settingRepository,
        private DocumentRepository $documentRepository,
        private DocumentUrlGenerator $documentUrlGenerator,
    ) {}

    #[Route('/favicon.svg', name: 'favicon', methods: [HttpMethodEnum::Get->value])]
    #[Route('/favicon.ico', name: 'favicon_ico', methods: [HttpMethodEnum::Get->value])]
    public function __invoke(): Response
    {
        $uploaded = $this->uploadedFavicon();

        if (null !== $uploaded) {
            $response = new RedirectResponse($uploaded);
            $response->setEtag(md5($uploaded));
            $response->setPublic();
            $response->setMaxAge(300);

            return $response;
        }

        $hex = mb_ltrim($this->themeContext->primaryColor(), '#');
        // Default accent hue if an invalid hex slipped through
        if (6 !== mb_strlen($hex) || !ctype_xdigit($hex)) {
            $hex = self::FALLBACK_COLOR;
        }

        $initial = $this->initial();

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
            .'<defs><linearGradient id="bg" x1="0%%" y1="0%%" x2="100%%" y2="100%%">'
            .'<stop offset="0%%" style="stop-color:#%s;stop-opacity:1"/>'
            .'<stop offset="100%%" style="stop-color:#%s;stop-opacity:0.85"/>'
            .'</linearGradient></defs>'
            .'<rect width="64" height="64" rx="14" fill="url(#bg)"/>'
            .'<text x="32" y="45" font-family="\'Inter\', \'Segoe UI\', sans-serif" font-size="36" font-weight="700" text-anchor="middle" fill="white">%s</text>'
            .'<line x1="20" y1="52" x2="44" y2="52" stroke="rgba(255,255,255,0.4)" stroke-width="2.5" stroke-linecap="round"/>'
            .'</svg>',
            $hex,
            $hex,
            htmlspecialchars($initial, ENT_QUOTES | ENT_XML1, 'UTF-8'),
        );

        $response = new Response($svg, HttpStatusEnum::Ok->value, [
            'Content-Type' => 'image/svg+xml',
            // Public + ETag - the browser re-fetches only when the colour changes.
            'Cache-Control' => 'public, max-age=300, must-revalidate',
        ]);
        $response->setEtag(md5($hex.$initial));
        $response->setPublic();

        return $response;
    }

    /** The picture chosen on the Branding screen, if it is still there. */
    private function uploadedFavicon(): ?string
    {
        $rawId = $this->settingRepository->get(ApplicationParameterEnum::FaviconMediaId->value, '') ?? '';
        $documentId = (int) $rawId;

        if ($documentId <= 0) {
            return null;
        }

        return $this->documentUrlGenerator->publicUrl($this->documentRepository->find($documentId));
    }

    /**
     * The site's first letter, upper-cased.
     *
     * A name that starts with something other than a letter - a digit, a
     * bracket, an emoji - would draw whatever it starts with, and that is
     * fine: it is still the site's own mark rather than a letter belonging to
     * some other product.
     */
    private function initial(): string
    {
        $name = mb_trim($this->settingRepository->getOrDefault(ApplicationParameterEnum::SiteName));

        return '' === $name ? self::FALLBACK_INITIAL : mb_strtoupper(mb_substr($name, 0, 1));
    }
}
