<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Service;

use Aurora\Core\Frontend\Service\Context;
use Aurora\Module\Configuration\Setting\Service\SiteTimezone;
use Aurora\Module\Configuration\Theme\Service\ThemeContext;
use Aurora\Module\Editorial\Post\Grid\GridNormalizer;
use Aurora\Module\Editorial\Post\Grid\GridViewBuilder;
use Aurora\Module\Editorial\Post\Service\ReadingTimeCalculator;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableInterface;
use DateTimeInterface;
use IntlDateFormatter;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

use function in_array;
use function is_array;

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
    ) {}

    /** @param string|null $backUrl où revient le lecteur, quand il vient d'une page à lui */
    public function render(DeliverableInterface $deliverable, ?string $backUrl = null): Response
    {
        $locale = $deliverable->getLocale();
        $grid = $this->gridViewBuilder->build($deliverable->getGridLayout(), $deliverable->getGridContent(), $locale, null);

        // L'éditeur ne les propose pas ; une grille venue d'ailleurs, d'une
        // duplication ou d'un import, peut quand même en porter.
        if (null !== $grid) {
            $grid['zones'] = $this->withoutHiddenZones($grid['zones']);
            $grid['hasComments'] = false;
        }

        $appearance = DeliverableAppearance::normalize($deliverable->getAppearance());

        $body = $this->twig->render('@Studio/public/deliverable.html.twig', [
            'locale' => $locale,
            'context' => $this->context,
            'themeContext' => $this->themeContext,
            'deliverable' => [
                'title' => $deliverable->getTitle(),
                'summary' => $deliverable->getSummary(),
            ],
            'appearance' => $appearance,
            'grid' => $grid,
            'readingTimeMinutes' => null !== $grid ? $this->readingTimeCalculator->minutesFor($deliverable->getGridContent()) : 0,
            // Les trois surfaces que le thème sait repeindre, surface par
            // surface : nul laisse passer la sienne.
            'surfaceOverrides' => [
                'background_color' => $appearance['backgroundColor'],
                'header_color' => $appearance['headerColor'],
                'footer_color' => $appearance['footerColor'],
            ],
            'reading' => [
                ...DeliverableReadingHeader::normalize($deliverable->getReadingHeader()),
                'updatedAt' => $deliverable->getUpdatedAt()->format(DateTimeInterface::ATOM),
                'updatedOn' => new IntlDateFormatter($locale, IntlDateFormatter::LONG, IntlDateFormatter::NONE, $this->siteTimezone->get())->format($deliverable->getUpdatedAt()),
                // Une seule langue : le sélecteur ne se dessine pas.
                'localeUrls' => [],
                'backUrl' => $backUrl,
            ],
        ]);

        $response = new Response($body);
        $response->headers->set('Content-Language', $locale);

        return $response;
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
