<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Service;

use Aurora\Core\Locale\Service\LocaleContextInterface;
use Aurora\Core\Twig\PlaceholderMarkExtension;
use Aurora\Module\Configuration\Theme\Service\ThemeStyleRenderer;
use Aurora\Module\Editorial\Post\Banner\BannerViewBuilder;
use Aurora\Module\Editorial\Post\Grid\GridViewBuilder;
use Twig\Environment;

use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function max;

/**
 * The previews of a deliverable's editor, while it is being composed: the
 * grid, and a banner block placed in the grid.
 *
 * The same zone template as the page, rendered from what the editor holds,
 * and not from what is in the database. Shared by the space editor and the
 * Studio one.
 */
final readonly class DeliverableEditorPreviews
{
    /** The selector of the banner block preview, see PostBannerPanel.vue. */
    private const string BANNER_PREVIEW_SELECTOR = '.aurora-banner-preview[data-theme]';

    /**
     * The default theme's templates, like the client's page: a deliverable
     * always renders with them, whatever the site's active theme, so that
     * what the client reads does not depend on the site's skin. A preview
     * that went through the active theme would show, the day a theme
     * overrides the grid, a page the client will not see.
     */
    private const string GRID_TEMPLATE = 'Frontend/themes/default/editorial/post/_grid.html.twig';

    private const string BANNER_TEMPLATE = 'Frontend/themes/default/editorial/post/_banner.html.twig';

    public function __construct(
        private Environment $twig,
        private ThemeStyleRenderer $themeStyles,
        private GridViewBuilder $gridViewBuilder,
        private BannerViewBuilder $bannerViewBuilder,
        private LocaleContextInterface $localeContext,
        private PlaceholderMarkExtension $placeholders,
        private DeliverablePageRenderer $pageRenderer,
    ) {}

    /** @param array<string, mixed> $payload what the editor sends: `{layout, content, locale}` */
    public function grid(array $payload): string
    {
        // The whole page, in the public theme, for the preview next to the
        // grid; the grid alone for the preview dialog.
        // The request's language, validated here and for both previews: a
        // request's language is not a site language for all that.
        $locale = $this->locale($payload['locale'] ?? null);

        if (true === ($payload['frame'] ?? false)) {
            return $this->pageRenderer->editorPreviewPage([...$payload, 'locale' => $locale]);
        }

        // Without the zones the client's page will not show: filtered before
        // being resolved, because a deck or a publication list resolved in
        // the preview would already have shown what the author may not be
        // allowed to see.
        $layout = DeliverablePageRenderer::withoutHiddenLayoutZones(is_array($payload['layout'] ?? null) ? $payload['layout'] : []);
        $content = DeliverablePageRenderer::contentOfShownZones($layout, is_array($payload['content'] ?? null) ? $payload['content'] : []);

        // The [passages to replace] highlighted, as in the page preview: the
        // author is the one looking.
        return $this->placeholders->mark($this->twig->render(
            self::GRID_TEMPLATE,
            ['grid' => $this->gridViewBuilder->buildForPreview($layout, $content, $locale), 'locale' => $locale, 'editorPreview' => true],
        ));
    }

    /** @param array<string, mixed> $payload what the editor sends: `{layout, texts, slide}` */
    public function banner(array $payload): string
    {
        $layout = is_array($payload['layout'] ?? null) ? $payload['layout'] : [];
        $texts = is_array($payload['texts'] ?? null) ? $payload['texts'] : [];
        $slide = is_int($payload['slide'] ?? null) ? $payload['slide'] : 0;

        return '<style>'.$this->themeStyles->previewSurfaceCss(self::BANNER_PREVIEW_SELECTOR).'</style>'
            .$this->twig->render(
                self::BANNER_TEMPLATE,
                ['banner' => $this->bannerViewBuilder->buildForEditor($layout, $texts, max(0, $slide))],
            );
    }

    private function locale(mixed $value): string
    {
        return is_string($value) && in_array($value, $this->localeContext->getActiveLocales(), true)
            ? $value
            : $this->localeContext->getDefaultLocale();
    }
}
