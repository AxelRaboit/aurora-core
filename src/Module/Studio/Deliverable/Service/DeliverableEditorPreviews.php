<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Service;

use Aurora\Core\Twig\PlaceholderMarkExtension;
use Aurora\Core\Locale\Service\LocaleContextInterface;
use Aurora\Module\Configuration\Theme\Service\ThemeResolver;
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
 * Les aperçus de l'éditeur d'un livrable, pendant qu'on le compose : la grille,
 * et un bloc d'entête posé dans la grille.
 *
 * Le même gabarit de zones que la page, rendu à partir de ce que l'éditeur
 * tient, et pas de ce qui est en base. Partagé par l'éditeur d'un espace et
 * celui de Studio.
 */
final readonly class DeliverableEditorPreviews
{
    /** Le sélecteur de l'aperçu du bloc d'entête, cf. PostBannerPanel.vue. */
    private const string BANNER_PREVIEW_SELECTOR = '.aurora-banner-preview[data-theme]';

    public function __construct(
        private Environment $twig,
        private ThemeResolver $themeResolver,
        private ThemeStyleRenderer $themeStyles,
        private GridViewBuilder $gridViewBuilder,
        private BannerViewBuilder $bannerViewBuilder,
        private LocaleContextInterface $localeContext,
        private PlaceholderMarkExtension $placeholders,
    ) {}

    /** @param array<string, mixed> $payload ce que l'éditeur envoie : `{layout, content, locale}` */
    public function grid(array $payload): string
    {
        $layout = is_array($payload['layout'] ?? null) ? $payload['layout'] : [];
        $content = is_array($payload['content'] ?? null) ? $payload['content'] : [];
        $locale = $this->locale($payload['locale'] ?? null);

        // Les [passages à remplacer] surlignés, comme dans l'aperçu de la page :
        // c'est l'auteur qui regarde.
        return $this->placeholders->mark($this->twig->render(
            $this->themeResolver->resolve('editorial/post/_grid'),
            ['grid' => $this->gridViewBuilder->buildForEditor($layout, $content, $locale), 'locale' => $locale],
        ));
    }

    /** @param array<string, mixed> $payload ce que l'éditeur envoie : `{layout, texts, slide}` */
    public function banner(array $payload): string
    {
        $layout = is_array($payload['layout'] ?? null) ? $payload['layout'] : [];
        $texts = is_array($payload['texts'] ?? null) ? $payload['texts'] : [];
        $slide = is_int($payload['slide'] ?? null) ? $payload['slide'] : 0;

        return '<style>'.$this->themeStyles->previewSurfaceCss(self::BANNER_PREVIEW_SELECTOR).'</style>'
            .$this->twig->render(
                $this->themeResolver->resolve('editorial/post/_banner'),
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
