<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Locale\Service\LocaleContextInterface;
use Aurora\Module\Configuration\Theme\Service\ThemeResolver;
use Aurora\Module\Editorial\Post\Grid\GridViewBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Renders the content grid an editor is composing, without saving it.
 *
 * Server-side and through the same Twig the public page uses, for the reason
 * the banner's preview is: reproducing the grid in Vue would be quicker and
 * would drift, and a preview that can disagree with what gets published is
 * worse than none because it is believed.
 *
 * Resolved through ThemeResolver rather than hardcoding the default theme, so
 * a project that overrides the partial previews its own version.
 */
#[Route('/backend/editorial/posts', name: 'backend_editorial_posts')]
#[IsGranted('editorial.posts.view')]
final class GridPreviewController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly GridViewBuilder $gridViewBuilder,
        private readonly ThemeResolver $themeResolver,
        private readonly LocaleContextInterface $localeContext,
    ) {}

    #[Route('/grid-preview', name: '_grid_preview', methods: [HttpMethodEnum::Post->value])]
    public function preview(Request $request): JsonResponse
    {
        $payload = $this->decodeJson($request);

        // Both halves, because a preview is per language: the same arrangement
        // holding the German copy is a different page from the same
        // arrangement holding the French.
        $layout = is_array($payload['layout'] ?? null) ? $payload['layout'] : [];
        $content = is_array($payload['content'] ?? null) ? $payload['content'] : [];

        // The locale the editor has open, not the request's. A backend in
        // French previewing the German tab has to render the German zones —
        // and a linked publication's card has to be the German one.
        $locale = $this->locale($payload['locale'] ?? null);

        // buildForEditor rather than build: the panel asks for a preview while
        // the grid is still switched off or half-composed, and answering
        // "nothing" there would look like a bug rather than a state.
        $grid = $this->gridViewBuilder->buildForEditor($layout, $content, $locale);

        return $this->json([
            'success' => true,
            'html' => $this->renderView(
                $this->themeResolver->resolve('editorial/post/_grid'),
                // `locale` too: the partial builds a card's link from it, and
                // the route needs one whatever the payload said.
                ['grid' => $grid, 'locale' => $locale],
            ),
        ]);
    }

    /**
     * Whitelisted against what the site actually speaks. The value reaches a
     * route generator, and a locale nobody configured would throw there rather
     * than render.
     */
    private function locale(mixed $value): string
    {
        $active = $this->localeContext->getActiveLocales();

        return is_string($value) && in_array($value, $active, true)
            ? $value
            : $this->localeContext->getDefaultLocale();
    }
}
