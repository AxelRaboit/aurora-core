<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Module\Configuration\Theme\Service\ThemeResolver;
use Aurora\Module\Editorial\Post\Banner\BannerViewBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Renders a banner the editor is composing, without saving it.
 *
 * Server-side on purpose. Reproducing the banner in Vue would be quicker and
 * would drift: two renderers for one thing is how `twoColumn` ended up
 * writing a shape its renderer could not read, in this very module. Going
 * through the same Twig the public page uses means a preview cannot disagree
 * with what gets published — if it looks wrong here, it is wrong there.
 *
 * Resolved through ThemeResolver rather than hardcoding the default theme, so
 * a project that overrides the partial previews its own version.
 */
#[Route('/backend/editorial/posts', name: 'backend_editorial_posts')]
#[IsGranted('editorial.posts.view')]
final class BannerPreviewController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly BannerViewBuilder $bannerViewBuilder,
        private readonly ThemeResolver $themeResolver,
    ) {}

    #[Route('/banner-preview', name: '_banner_preview', methods: [HttpMethodEnum::Post->value])]
    public function preview(Request $request): JsonResponse
    {
        $payload = $this->decodeJson($request);
        $raw = is_array($payload['banner'] ?? null) ? $payload['banner'] : [];

        // buildForEditor rather than build: the panel asks for a preview while
        // the banner is still switched off or half-composed, and answering
        // "nothing" there would look like a bug rather than a state.
        $banner = $this->bannerViewBuilder->buildForEditor($raw);

        return $this->json([
            'success' => true,
            'html' => $this->renderView(
                $this->themeResolver->resolve('editorial/post/_banner'),
                ['banner' => $banner],
            ),
        ]);
    }
}
