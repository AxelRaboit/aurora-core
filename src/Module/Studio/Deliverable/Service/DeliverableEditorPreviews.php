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

    /**
     * Les gabarits du thème par défaut, comme la page du client : un livrable
     * se rend toujours avec eux, quel que soit le thème actif du site, pour
     * que ce que lit le client ne dépende pas de l'habillage du site. Un
     * aperçu qui passerait par le thème actif montrerait, le jour où un thème
     * surcharge la grille, une page que le client ne verra pas.
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

    /** @param array<string, mixed> $payload ce que l'éditeur envoie : `{layout, content, locale}` */
    public function grid(array $payload): string
    {
        // La page entière, au thème public, pour l'aperçu posé à côté de la
        // grille ; la grille seule pour la fenêtre d'aperçu.
        // La langue de l'envoi, validée ici et pour les deux aperçus : celle
        // d'une requête n'est pas une langue du site pour autant.
        $locale = $this->locale($payload['locale'] ?? null);

        if (true === ($payload['frame'] ?? false)) {
            return $this->pageRenderer->editorPreviewPage([...$payload, 'locale' => $locale]);
        }

        // Sans les zones que la page du client ne montrera pas : filtrées
        // avant d'être résolues, car un deck ou une liste de publications
        // résolus dans l'aperçu auraient déjà montré ce que l'auteur n'a peut-être
        // pas le droit de voir.
        $layout = DeliverablePageRenderer::withoutHiddenLayoutZones(is_array($payload['layout'] ?? null) ? $payload['layout'] : []);
        $content = DeliverablePageRenderer::contentOfShownZones($layout, is_array($payload['content'] ?? null) ? $payload['content'] : []);

        // Les [passages à remplacer] surlignés, comme dans l'aperçu de la page :
        // c'est l'auteur qui regarde.
        return $this->placeholders->mark($this->twig->render(
            self::GRID_TEMPLATE,
            ['grid' => $this->gridViewBuilder->buildForPreview($layout, $content, $locale), 'locale' => $locale, 'editorPreview' => true],
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
