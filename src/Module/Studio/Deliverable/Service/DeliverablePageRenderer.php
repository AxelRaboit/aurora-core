<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Service;

use Aurora\Core\Frontend\Service\Context;
use Aurora\Module\Configuration\Setting\Service\SiteTimezone;
use Aurora\Module\Configuration\Theme\Service\ThemeContext;
use Aurora\Module\Editorial\Post\Grid\GridNormalizer;
use Aurora\Module\Editorial\Post\Grid\GridSlides;
use Aurora\Module\Editorial\Post\Grid\GridViewBuilder;
use Aurora\Module\Editorial\Post\Service\ReadingTimeCalculator;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableInterface;
use DateTimeImmutable;
use DateTimeInterface;
use IntlDateFormatter;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

use function array_map;
use function in_array;
use function is_array;
use function is_string;

/**
 * La page d'un livrable, telle que le client la lit.
 *
 * La grille se résout par le même constructeur que les pages du site, et se
 * dessine par le même gabarit de zones : un bloc posé dans un livrable a
 * exactement l'allure qu'il a sur le site. Autour, le gabarit de lecture : ni
 * menu ni pied de liens, un en-tête qui dit pour qui le document a été
 * préparé.
 *
 * Trois lecteurs, un seul rendu : le client depuis son espace (avec un retour
 * vers l'espace), le destinataire d'un lien de lecture, et le studio qui
 * regarde son travail avant de l'ouvrir.
 */
final readonly class DeliverablePageRenderer
{
    /**
     * Les zones qui ne vivent que sur une page du site : un fil de
     * commentaires, une recherche, une liste de publications, un formulaire
     * ou une inscription à la lettre, un sondage. Elles s'accrochent à une
     * publication ou à l'audience du site, qu'un livrable n'a pas : chacune
     * ne pourrait dessiner qu'un bloc qui échoue, ou montrer au client ce qui
     * ne le regarde pas.
     */
    public const array HIDDEN_ZONE_TYPES = [
        GridNormalizer::ZONE_COMMENTS,
        GridNormalizer::ZONE_SEARCH,
        GridNormalizer::ZONE_POST,
        GridNormalizer::ZONE_POST_LIST,
        GridNormalizer::ZONE_TERMS,
        GridNormalizer::ZONE_DECK,
        GridNormalizer::ZONE_FORM,
        GridNormalizer::ZONE_POLL,
        GridNormalizer::ZONE_EDITORIAL_CALENDAR,
        GridNormalizer::ZONE_ACTIVITY_FEED,
        GridNormalizer::ZONE_NEWSLETTER_SIGNUP,
        GridNormalizer::ZONE_NEWSLETTER_PRIVACY,
    ];

    public function __construct(
        private Environment $twig,
        private Context $context,
        private ThemeContext $themeContext,
        private GridViewBuilder $gridViewBuilder,
        private ReadingTimeCalculator $readingTimeCalculator,
        private SiteTimezone $siteTimezone,
        private GridSlides $gridSlides,
    ) {}

    /**
     * @param string|null $backUrl          où revient le lecteur, quand il vient d'une page à lui
     * @param bool        $markPlaceholders les [passages à remplacer] surlignés : l'aperçu de
     *                                      l'auteur, jamais la page du client
     * @param bool        $print            la version à imprimer en PDF : en diapositives, une
     *                                      par page, quel que soit l'affichage choisi
     */
    public function render(DeliverableInterface $deliverable, ?string $backUrl = null, bool $markPlaceholders = false, bool $print = false): Response
    {
        $locale = $deliverable->getLocale();

        $response = new Response($this->page(
            [
                'locale' => $locale,
                'title' => $deliverable->getTitle(),
                'summary' => $deliverable->getSummary(),
                'gridLayout' => $deliverable->getGridLayout(),
                'gridContent' => $deliverable->getGridContent(),
                'appearance' => $deliverable->getAppearance(),
                'readingHeader' => $deliverable->getReadingHeader(),
                'updatedAt' => $deliverable->getUpdatedAt(),
            ],
            $backUrl,
            $markPlaceholders,
            $print,
        ));
        $response->headers->set('Content-Language', $locale);

        return $response;
    }

    /**
     * La page telle que la lira le client, rendue depuis ce que l'éditeur
     * tient encore sans l'avoir enregistré : l'aperçu posé à côté de la
     * grille, au thème public près, fond, en-tête et grands titres compris.
     * Chaque zone y porte son identifiant, pour qu'un clic la sélectionne.
     *
     * @param array<string, mixed> $payload ce que l'éditeur envoie
     */
    public function editorPreviewPage(array $payload): string
    {
        return $this->page(
            [
                'locale' => is_string($payload['locale'] ?? null) ? $payload['locale'] : $this->context->defaultLocale(),
                'title' => is_string($payload['title'] ?? null) ? $payload['title'] : '',
                'summary' => is_string($payload['summary'] ?? null) ? $payload['summary'] : null,
                'gridLayout' => is_array($payload['layout'] ?? null) ? $payload['layout'] : [],
                'gridContent' => is_array($payload['content'] ?? null) ? $payload['content'] : [],
                'appearance' => $payload['appearance'] ?? [],
                'readingHeader' => $payload['readingHeader'] ?? [],
                'updatedAt' => new DateTimeImmutable(),
            ],
            null,
            markPlaceholders: true,
            print: false,
            editorPreview: true,
        );
    }

    /**
     * @param array{locale: string, title: string, summary: ?string, gridLayout: array<string, mixed>, gridContent: array<string, mixed>, appearance: mixed, readingHeader: mixed, updatedAt: DateTimeInterface} $source
     */
    private function page(array $source, ?string $backUrl, bool $markPlaceholders, bool $print, bool $editorPreview = false): string
    {
        $locale = $source['locale'];
        $grid = $editorPreview
            ? $this->gridViewBuilder->buildForPreview($source['gridLayout'], $source['gridContent'], $locale, null)
            : $this->gridViewBuilder->build($source['gridLayout'], $source['gridContent'], $locale, null);

        // L'éditeur ne les propose pas ; une grille venue d'ailleurs, d'une
        // duplication ou d'un import, peut quand même en porter.
        if (null !== $grid) {
            $grid['zones'] = $this->withoutHiddenZones($grid['zones']);
            $grid['hasComments'] = false;
        }

        $appearance = DeliverableAppearance::normalize($source['appearance']);

        // Shown as a presentation: the same grid, cut at each section. Every
        // slide is a grid of its own for the template; the picture overlay is
        // left out of them, mounted once per grid it would open N times.
        $slides = null;
        if (null !== $grid && ('slides' === $appearance['display'] || $print)) {
            $slides = array_map(
                static fn (array $zones): array => [...$grid, 'zones' => $zones, 'lightbox' => []],
                $this->gridSlides->split($grid['zones'], $source['gridContent']),
            );
        }

        return $this->twig->render('@Studio/public/deliverable.html.twig', [
            'locale' => $locale,
            'context' => $this->context,
            'themeContext' => $this->themeContext,
            'deliverable' => [
                'title' => $source['title'],
                'summary' => $source['summary'],
            ],
            'appearance' => $appearance,
            'grid' => $grid,
            'slides' => $slides,
            'print' => $print,
            'markPlaceholders' => $markPlaceholders,
            'editorPreview' => $editorPreview,
            'readingTimeMinutes' => null !== $grid ? $this->readingTimeCalculator->minutesFor($source['gridContent']) : 0,
            // Les trois surfaces que le thème sait repeindre, surface par
            // surface : nul laisse passer la sienne.
            'surfaceOverrides' => [
                'background_color' => $appearance['backgroundColor'],
                'header_color' => $appearance['headerColor'],
                'footer_color' => $appearance['footerColor'],
            ],
            'reading' => [
                ...DeliverableReadingHeader::normalize($source['readingHeader']),
                'updatedAt' => $source['updatedAt']->format(DateTimeInterface::ATOM),
                'updatedOn' => new IntlDateFormatter($locale, IntlDateFormatter::LONG, IntlDateFormatter::NONE, $this->siteTimezone->get())->format($source['updatedAt']),
                // Une seule langue : le sélecteur ne se dessine pas.
                'localeUrls' => [],
                'backUrl' => $backUrl,
            ],
        ]);
    }

    /**
     * @param list<array<string, mixed>> $zones
     *
     * @return list<array<string, mixed>>
     */
    private function withoutHiddenZones(array $zones): array
    {
        $kept = [];

        foreach ($zones as $zone) {
            if (in_array($zone['type'] ?? null, self::HIDDEN_ZONE_TYPES, true)) {
                continue;
            }

            if (is_array($zone['children'] ?? null)) {
                $zone['children'] = $this->withoutHiddenZones($zone['children']);
            }

            $kept[] = $zone;
        }

        return $kept;
    }
}
