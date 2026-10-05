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

use function array_intersect_key;
use function array_map;
use function count;
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
     * Les zones qui n'ont pas leur place dans un document remis à un client.
     *
     * Celles qui ne vivent que sur une page du site : un fil de commentaires,
     * une recherche, une liste de publications, un formulaire ou une
     * inscription à la lettre, un sondage, une prise de rendez-vous. Elles
     * s'accrochent à une publication ou à l'audience du site, qu'un livrable
     * n'a pas : chacune ne pourrait dessiner qu'un bloc qui échoue.
     *
     * Et celles qui montreraient ce qui n'est pas au client : le bloc partagé
     * rend le contenu d'une autre publication, brouillon compris, et l'activité
     * GitHub, le fil Instagram et les avis Google sont les données des
     * intégrations du studio, pas celles de son client.
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
        GridNormalizer::ZONE_APPOINTMENT_BOOKING,
        GridNormalizer::ZONE_SHARED,
        GridNormalizer::ZONE_GITHUB_ACTIVITY,
        GridNormalizer::ZONE_INSTAGRAM_FEED,
        GridNormalizer::ZONE_GOOGLE_REVIEWS,
    ];

    /**
     * La disposition sans les zones masquées, avant qu'aucune ne soit résolue.
     *
     * C'est ici, sur la disposition, et pas sur la grille construite : une
     * zone retirée après coup a déjà été résolue, donc déjà lu sa publication
     * ou son deck. Les aperçus de l'éditeur passent par là eux aussi, pour ne
     * pas montrer ce que la page du client ne montrera pas.
     *
     * @param array<string, mixed> $layout
     *
     * @return array<string, mixed>
     */
    public static function withoutHiddenLayoutZones(array $layout): array
    {
        if (is_array($layout['zones'] ?? null)) {
            $layout['zones'] = self::keepShownZones($layout['zones']);
        }

        return $layout;
    }

    /**
     * Le contenu des seules zones qui restent : le temps de lecture et le
     * découpage en diapositives ne comptent pas ce que la page ne montre pas.
     *
     * @param array<string, mixed> $layout  la disposition déjà filtrée
     * @param array<string, mixed> $content
     *
     * @return array<string, mixed>
     */
    public static function contentOfShownZones(array $layout, array $content): array
    {
        if (!is_array($content['zones'] ?? null) || !is_array($layout['zones'] ?? null)) {
            return $content;
        }

        $kept = [];
        foreach (GridNormalizer::flatten($layout['zones']) as $zone) {
            if (isset($zone['id']) && is_string($zone['id'])) {
                $kept[$zone['id']] = true;
            }
        }

        return [...$content, 'zones' => array_intersect_key($content['zones'], $kept)];
    }

    /**
     * @param array<int|string, mixed> $zones
     *
     * @return list<array<string, mixed>>
     */
    private static function keepShownZones(array $zones): array
    {
        $kept = [];

        foreach ($zones as $zone) {
            if (!is_array($zone)) {
                continue;
            }

            if (in_array($zone['type'] ?? null, self::HIDDEN_ZONE_TYPES, true)) {
                continue;
            }

            if (is_array($zone['children'] ?? null)) {
                $zone['children'] = self::keepShownZones($zone['children']);
            }

            $kept[] = $zone;
        }

        return $kept;
    }

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
     * @param string|null $view             la vue que le lecteur a choisie (`?view=`), parmi
     *                                      {@see DeliverableAppearance::DISPLAYS} ; toute autre
     *                                      valeur, ou rien, laisse celle de l'auteur
     */
    public function render(DeliverableInterface $deliverable, ?string $backUrl = null, bool $markPlaceholders = false, bool $print = false, ?string $view = null): Response
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
            view: $view,
        ));
        $response->headers->set('Content-Language', $locale);

        return $response;
    }

    /**
     * La page lue par son destinataire, lien de lecture ou espace du client.
     *
     * `?print=1` n'y est suivi que si l'auteur a permis le PDF pour ce
     * livrable (réglage « Autoriser le PDF au lecteur », éteint par défaut) :
     * sans cela la demande est ignorée et la page se lit comme d'habitude. Ce
     * n'est pas un secret gardé, le contenu est déjà à l'écran, mais une
     * décision de l'auteur : un document qu'on ne veut pas voir circuler en
     * fichier n'offre pas le bouton.
     *
     * @param string|null $backUrl où revient le lecteur, quand il vient d'une page à lui
     */
    public function renderForReader(DeliverableInterface $deliverable, bool $printRequested, ?string $backUrl = null, ?string $view = null): Response
    {
        return $this->render($deliverable, $backUrl, print: $printRequested && self::allowsReaderPdf($deliverable), view: $view);
    }

    /**
     * La vue demandée par l'adresse, si c'en est une : `page` ou `slides`.
     * Le reste (vide, faute de frappe, valeur fabriquée) vaut « rien », et
     * l'affichage choisi par l'auteur s'applique. Lue brute dans l'adresse :
     * un `?view[]=page` est un tableau, et il vaut « rien » lui aussi au lieu
     * de faire échouer la page.
     */
    public static function requestedView(mixed $raw): ?string
    {
        return in_array($raw, DeliverableAppearance::DISPLAYS, true) ? $raw : null;
    }

    /** Si l'auteur a permis au lecteur de tirer son PDF. */
    public static function allowsReaderPdf(DeliverableInterface $deliverable): bool
    {
        return DeliverableAppearance::normalize($deliverable->getAppearance())['readerPdf'];
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
    private function page(array $source, ?string $backUrl, bool $markPlaceholders, bool $print, bool $editorPreview = false, ?string $view = null): string
    {
        $locale = $source['locale'];
        $layout = self::withoutHiddenLayoutZones($source['gridLayout']);
        $content = self::contentOfShownZones($layout, $source['gridContent']);
        $grid = $editorPreview
            ? $this->gridViewBuilder->buildForPreview($layout, $content, $locale, null)
            : $this->gridViewBuilder->build($layout, $content, $locale, null);

        // L'éditeur ne les propose pas, et la disposition en est déjà
        // débarrassée ; ce qui reste à faire est de couper ce que la page
        // n'a pas : un fil de commentaires.
        if (null !== $grid) {
            $grid['zones'] = $this->withoutHiddenZones($grid['zones']);
            $grid['hasComments'] = false;
        }

        $appearance = DeliverableAppearance::normalize($source['appearance']);

        // La vue : celle que le lecteur a choisie, sinon celle de l'auteur.
        // L'aperçu de l'éditeur montre toujours celle de l'auteur.
        $display = ($editorPreview ? null : self::requestedView($view)) ?? $appearance['display'];

        // Les sections du document, une par diapositive : le même découpage
        // sert aux deux vues, pour qu'on passe de l'une à l'autre au même
        // endroit (`#diapo-N`).
        $sections = null !== $grid ? $this->gridSlides->split($grid['zones'], $content) : [];

        // Shown as a presentation: the same grid, cut at each section. Every
        // slide is a grid of its own for the template; the picture overlay is
        // left out of them, mounted once per grid it would open N times.
        $slides = null;
        if (null !== $grid && ('slides' === $display || $print)) {
            $slides = array_map(
                static fn (array $zones): array => [...$grid, 'zones' => $zones, 'lightbox' => []],
                $sections,
            );
        } elseif (null !== $grid && count($sections) > 1) {
            // En page, la première zone de chaque section porte son numéro :
            // une adresse `#diapo-N` y mène, et repasser en présentation sait
            // où l'on en était.
            $grid['zones'] = $this->markSections($grid['zones'], $sections);
        }

        // Changer de vue, seulement quand il y a plusieurs sections : une
        // présentation d'une seule diapositive ne montre rien de plus. Ni
        // dans l'aperçu de l'éditeur, ni sur la version à imprimer.
        $viewSwitch = !$print && !$editorPreview && count($sections) > 1
            ? ('slides' === $display ? 'page' : 'slides')
            : null;

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
            'viewSwitch' => $viewSwitch,
            'print' => $print,
            'markPlaceholders' => $markPlaceholders,
            'editorPreview' => $editorPreview,
            'readingTimeMinutes' => null !== $grid ? $this->readingTimeCalculator->minutesFor($content) : 0,
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
     * Numérote la première zone de chaque section, et lui donne l'ancre
     * `diapo-N` quand l'auteur ne lui en a pas déjà mis une.
     *
     * @param list<array<string, mixed>>       $zones
     * @param list<list<array<string, mixed>>> $sections
     *
     * @return list<array<string, mixed>>
     */
    private function markSections(array $zones, array $sections): array
    {
        $starts = [];
        foreach ($sections as $position => $section) {
            $first = $section[0]['id'] ?? null;
            if (is_string($first)) {
                $starts[$first] = $position + 1;
            }
        }

        foreach ($zones as $index => $zone) {
            $number = $starts[$zone['id'] ?? ''] ?? null;
            if (null === $number) {
                continue;
            }

            $zones[$index]['section'] = $number;
            if ('' === (string) ($zone['anchor'] ?? '')) {
                $zones[$index]['anchor'] = 'diapo-'.$number;
            }
        }

        return $zones;
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
