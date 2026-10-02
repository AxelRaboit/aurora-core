<?php

declare(strict_types=1);

namespace Aurora\Fixtures\Studio;

use Aurora\Module\Editorial\Post\Grid\GridNormalizer;
use Aurora\Module\Editorial\Post\Service\EditorBlocks;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Repository\CustomerSpaceRepository;
use Aurora\Module\Studio\SpaceDeliverable\Entity\SpaceDeliverable;
use Aurora\Module\Studio\SpaceDeliverable\Entity\SpaceDeliverableLink;
use Aurora\Module\Studio\SpaceDeliverable\Repository\SpaceDeliverableRepository;
use Aurora\Module\Studio\SpaceDeliverable\Service\DeliverableAppearance;
use Aurora\Module\Studio\SpaceDeliverable\Service\DeliverableReadingHeader;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use RuntimeException;

use function array_keys;
use function array_map;
use function sprintf;

/**
 * Les livrables de la démo : quatre documents qui montrent ce qu'un livrable
 * sait être, finis et habillés.
 *
 * - **L'audit** d'Atelier Dupont, aux couleurs de l'atelier (noyer et cuivre) :
 *   chiffres clés, graphique, constats, plan en étapes, une citation. Ouvert
 *   au client, avec un lien de lecture déjà envoyé.
 * - **Le bilan de septembre**, en sombre : un rapport mensuel, avec sa courbe
 *   de portée et les contenus qui ont le mieux marché. Ouvert au client.
 * - **La stratégie du trimestre**, encore en cours : fermée au client, pour
 *   montrer qu'un livrable se prépare chez soi avant de s'ouvrir.
 * - **La proposition** faite à la Menuiserie Fabre, prospect : démarche, deux
 *   formules et calendrier, en vert forêt. Ouverte au prospect.
 *
 * En français seulement : un livrable a une langue, celle de son client.
 *
 * Rejouable : `make fixtures` charge tous les groupes, `make demo` recharge
 * celui-ci, et un livrable déjà présent dans son espace (même titre) est
 * laissé tel quel.
 *
 * Dev/test seulement, groupe `demo`.
 */
class SpaceDeliverableDemoFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    private const string DUPONT = 'Atelier Dupont - Réseaux sociaux';

    private const string FABRE = 'Menuiserie Fabre - Identité visuelle';

    /** Toute la largeur, sur chaque écran. */
    private const array FULL = ['base' => 48, 'md' => null, 'lg' => 48];

    private const array HALF = ['base' => 48, 'md' => null, 'lg' => 24];

    public function __construct(
        private readonly CustomerSpaceRepository $spaces,
        private readonly SpaceDeliverableRepository $deliverables,
        private readonly GridNormalizer $gridNormalizer,
    ) {}

    public static function getGroups(): array
    {
        return ['demo'];
    }

    public function getDependencies(): array
    {
        return [StudioDemoFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $dupont = $this->space(self::DUPONT);
        $fabre = $this->space(self::FABRE);

        $audit = $this->deliverable($manager, $dupont, ...$this->audit());
        $this->deliverable($manager, $dupont, ...$this->monthlyReport());
        $this->deliverable($manager, $dupont, ...$this->strategy());
        $this->deliverable($manager, $fabre, ...$this->proposal());

        $manager->flush();

        if ($audit instanceof SpaceDeliverable) {
            $link = new SpaceDeliverableLink($audit);
            $link->setLabel('Claire Dupont, le 1er octobre');
            $manager->persist($link);
            $manager->flush();
        }
    }

    private function space(string $name): CustomerSpaceInterface
    {
        $space = $this->spaces->findOneBy(['name' => $name]);

        if (!$space instanceof CustomerSpaceInterface) {
            throw new RuntimeException(sprintf('The demo space "%s" is missing: load the Studio demo fixtures first.', $name));
        }

        return $space;
    }

    /**
     * Un livrable, s'il n'existe pas déjà dans cet espace.
     *
     * @param list<array<string, mixed>> $zones      la disposition, zone par zone
     * @param array<string, mixed>       $content    le contenu, par identifiant de zone
     * @param array<string, string|bool> $appearance
     *
     * @return SpaceDeliverable|null le nouveau, ou null quand il était déjà là
     */
    private function deliverable(
        ObjectManager $manager,
        CustomerSpaceInterface $space,
        string $title,
        string $summary,
        bool $visible,
        array $appearance,
        array $zones,
        array $content,
    ): ?SpaceDeliverable {
        if (null !== $this->deliverables->findOneBy(['space' => $space, 'title' => $title])) {
            return null;
        }

        $layout = $this->gridNormalizer->normalizeLayout(['enabled' => true, 'snap' => 4, 'reveal' => 'fade', 'zones' => $zones]);

        $deliverable = new SpaceDeliverable($space, $title, 'fr');
        $deliverable
            ->setSummary($summary)
            ->setVisibleToClient($visible)
            ->setGridLayout($layout)
            ->setGridContent($this->gridNormalizer->normalizeContent(['zones' => $content], $layout))
            ->setAppearance(DeliverableAppearance::normalize($appearance))
            ->setReadingHeader(DeliverableReadingHeader::normalize(['preparedFor' => $space->getCustomer()->getLegalName()]));

        $manager->persist($deliverable);

        return $deliverable;
    }

    /**
     * Une zone de texte posée sur un dégradé, en contraste clair : l'ouverture
     * d'un document, comme un bloc d'entête sans image.
     *
     * @return array<string, mixed>
     */
    private function hero(string $id, string $from, string $to): array
    {
        return [
            'id' => $id,
            'type' => GridNormalizer::ZONE_TEXT,
            'span' => self::FULL,
            'surface' => 'custom',
            'contrast' => 'dark',
            'background' => ['type' => GridNormalizer::ZONE_FILL_GRADIENT, 'gradientFrom' => $from, 'gradientTo' => $to, 'gradientAngle' => 135],
        ];
    }

    /** @return array<string, mixed> */
    private function text(string $id, array $span = self::FULL, string $surface = 'none'): array
    {
        return ['id' => $id, 'type' => GridNormalizer::ZONE_TEXT, 'span' => $span, 'surface' => $surface];
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, mixed>
     */
    private function items(string $id, string $display, array $ids, int $columns, string $surface = 'none'): array
    {
        return [
            'id' => $id,
            'type' => GridNormalizer::ZONE_ITEMS,
            'span' => self::FULL,
            'display' => $display,
            'columns' => $columns,
            'surface' => $surface,
            'items' => array_map(static fn (string $item): array => ['id' => $item], $ids),
        ];
    }

    /** @return array<string, mixed> */
    private function chart(string $id, string $type, string $unit, array $span = self::HALF): array
    {
        return [
            'id' => $id,
            'type' => GridNormalizer::ZONE_CHART,
            'span' => $span,
            'surface' => 'card',
            'options' => ['chartType' => $type, 'chartUnit' => $unit],
        ];
    }

    /**
     * Les textes d'une liste d'éléments, dans l'ordre : `[titre, description]`
     * ou `[titre, description, légende]`.
     *
     * @param array<string, list<string>> $entries
     *
     * @return array{items: array<string, array<string, string>>}
     */
    private function entries(array $entries): array
    {
        $items = [];
        foreach ($entries as $id => $values) {
            $items[$id] = [
                'title' => $values[0],
                'description' => $values[1] ?? '',
                'caption' => $values[2] ?? '',
            ];
        }

        return ['items' => $items];
    }

    /** @return array{0: string, 1: string, 2: bool, 3: array<string, string|bool>, 4: list<array<string, mixed>>, 5: array<string, mixed>} */
    private function audit(): array
    {
        $figures = [
            'f1' => ['1 240', 'Abonnés Instagram'],
            'f2' => ['2,1 %', "Taux d'engagement moyen"],
            'f3' => ['3', 'Publications par semaine'],
            'f4' => ['+18 %', 'Abonnés en six mois'],
        ];
        $plan = [
            's1' => ['Octobre', "Une série « Dans l'atelier » : un geste, une personne, une minute.", 'Mise en place'],
            's2' => ['Novembre', "Un lien vers la prise de rendez-vous dans chaque publication qui s'y prête.", 'Conversion'],
            's3' => ['Décembre', "Les créations de fin d'année, en photo et en vidéo, publiées trois semaines avant les fêtes.", 'Temps fort'],
        ];

        return [
            'Audit de présence en ligne',
            'Instagram et Facebook, septembre 2026 : où en est Atelier Dupont, et ce que nous proposons pour les trois prochains mois.',
            true,
            // Noyer et cuivre : les couleurs de l'atelier, pas celles du studio.
            [
                'backgroundColor' => '#fbf7f1',
                'headerColor' => '#3d2b1f',
                'footerColor' => '#3d2b1f',
                'accentColor' => '#b5652b',
                'headingColor' => '#3d2b1f',
                'figureColor' => '#b5652b',
                'titleVisible' => false,
            ],
            [
                $this->hero('opening', '#3d2b1f', '#b5652b'),
                $this->items('figures', 'stats', array_keys($figures), 4, 'card'),
                $this->chart('engagement', 'bar', '%'),
                $this->text('findings', self::HALF),
                $this->text('planTitle'),
                $this->items('plan', 'steps', array_keys($plan), 3),
                $this->items('voice', 'quotes', ['q1'], 1, 'soft'),
                $this->text('next'),
            ],
            [
                'opening' => ['blocks' => [
                    EditorBlocks::header('Audit de présence en ligne', 2),
                    EditorBlocks::paragraph('Instagram et Facebook, septembre 2026. Atelier Dupont publie régulièrement et sa communauté grandit, mais les publications parlent surtout des produits et peu de l\'atelier. Les contenus qui montrent le geste et les personnes sont ceux qui engagent le plus.'),
                ]],
                'figures' => $this->entries($figures),
                'engagement' => ['label' => 'Engagement par type de contenu', 'code' => "Vidéos de l'atelier ; 6,2\nCarrousels ; 3,4\nStories ; 2,5\nPhotos de produit ; 1,9"],
                'findings' => ['blocks' => [
                    EditorBlocks::header('Ce que nous avons constaté', 3),
                    EditorBlocks::list([
                        'Les vidéos de fabrication obtiennent trois fois plus de réactions que les photos de produit.',
                        'Facebook touche surtout une clientèle locale, fidèle mais peu active.',
                        'Aucune publication ne renvoie vers le site ni vers la prise de rendez-vous.',
                    ]),
                    EditorBlocks::callout("Garder le rythme de deux publications par semaine, et en consacrer une sur deux aux coulisses de l'atelier.", 'info', 'Notre recommandation'),
                ]],
                'planTitle' => ['blocks' => [EditorBlocks::header('Le plan sur trois mois')]],
                'plan' => $this->entries($plan),
                'voice' => $this->entries(['q1' => ['Marie Dupont', "« On nous demande souvent comment on travaille le bois. Montrer l'atelier, c'est répondre à cette question tous les jours. »", 'Gérante']]),
                'next' => ['blocks' => [
                    EditorBlocks::header('La suite'),
                    EditorBlocks::paragraph("Nous faisons le point ensemble début janvier, chiffres à l'appui. D'ici là, chaque contenu passe par votre validation dans votre espace."),
                ]],
            ],
        ];
    }

    /** @return array{0: string, 1: string, 2: bool, 3: array<string, string|bool>, 4: list<array<string, mixed>>, 5: array<string, mixed>} */
    private function monthlyReport(): array
    {
        $figures = [
            'r1' => ['12 400', 'Comptes touchés'],
            'r2' => ['1 180', 'Interactions'],
            'r3' => ['+86', 'Nouveaux abonnés'],
            'r4' => ['9', 'Contenus publiés'],
        ];
        $best = [
            'b1' => ['Le tour de main du rabot', 'Réel, 38 secondes. 4 200 vues, 310 interactions : le meilleur score de l\'année.', 'Réel'],
            'b2' => ['Avant / après : la cuisine Morel', 'Carrousel de six photos. Enregistré 92 fois, signe qu\'on le garde pour plus tard.', 'Carrousel'],
            'b3' => ['Portes ouvertes du 12', 'Story en quatre écrans. 41 réponses au sondage « Vous venez ? ».', 'Story'],
        ];

        return [
            'Bilan de septembre',
            'Le mois en chiffres, les trois contenus qui ont le mieux marché, et ce que nous ajustons pour octobre.',
            true,
            // Le bilan en sombre : un rapport se lit d'un coup d'œil, chiffres
            // en clair sur fond nuit.
            [
                'backgroundColor' => '#0f172a',
                'headerColor' => '#0b1120',
                'footerColor' => '#0b1120',
                'accentColor' => '#38bdf8',
                'headingColor' => '#f8fafc',
                'figureColor' => '#38bdf8',
            ],
            [
                $this->items('figures', 'stats', array_keys($figures), 4, 'card'),
                $this->chart('reach', 'line', '', self::FULL),
                $this->text('bestTitle'),
                $this->items('best', 'editorial', array_keys($best), 3),
                $this->text('takeaways', self::HALF, 'card'),
                $this->text('october', self::HALF, 'card'),
            ],
            [
                'figures' => $this->entries($figures),
                'reach' => ['label' => 'Comptes touchés, semaine par semaine', 'code' => "Semaine 1 ; 2 100\nSemaine 2 ; 2 650\nSemaine 3 ; 3 900\nSemaine 4 ; 3 750"],
                'bestTitle' => ['blocks' => [EditorBlocks::header('Les trois contenus du mois')]],
                'best' => $this->entries($best),
                'takeaways' => ['blocks' => [
                    EditorBlocks::header("Ce qu'on retient", 3),
                    EditorBlocks::list([
                        'Les réels font la portée : trois fois celle des photos.',
                        'Les portes ouvertes ont doublé les messages reçus la semaine du 12.',
                        'Facebook stagne : on y garde les annonces, pas plus.',
                    ]),
                ]],
                'october' => ['blocks' => [
                    EditorBlocks::header('Pour octobre', 3),
                    EditorBlocks::list([
                        'Deux réels par semaine au lieu d\'un.',
                        'Un carrousel « avant / après » par chantier livré.',
                        'Le lien de prise de rendez-vous en tête du profil.',
                    ], 'ordered'),
                ]],
            ],
        ];
    }

    /** @return array{0: string, 1: string, 2: bool, 3: array<string, string|bool>, 4: list<array<string, mixed>>, 5: array<string, mixed>} */
    private function strategy(): array
    {
        $pillars = [
            'p1' => ["L'atelier", 'Le geste, les outils, les personnes. Un réel par semaine, tourné sur place.', 'Hebdomadaire'],
            'p2' => ['Les chantiers', 'Avant / après, en carrousel, à chaque cuisine ou escalier livré.', 'À chaque livraison'],
            'p3' => ['Les conseils', 'Entretenir un plan de travail, choisir une essence : des réponses courtes, en story.', 'Deux fois par mois'],
        ];
        $calendar = [
            'c1' => ['Octobre', 'Lancement de la série « Dans l\'atelier ».'],
            'c2' => ['Novembre', 'Premier bilan, ajustement des horaires de publication.'],
            'c3' => ['Décembre', 'Les créations de fin d\'année.'],
        ];

        return [
            'Stratégie de contenus, dernier trimestre',
            'Les trois axes et le calendrier proposés pour octobre à décembre.',
            // En cours : le client ne le voit pas encore.
            false,
            ['accentColor' => '#b5652b', 'figureColor' => '#b5652b'],
            [
                $this->text('context'),
                $this->items('pillars', 'features', array_keys($pillars), 3, 'card'),
                $this->text('calendarTitle'),
                $this->items('calendar', 'timeline', array_keys($calendar), 1),
                $this->text('todo', self::FULL, 'soft'),
            ],
            [
                'context' => ['blocks' => [
                    EditorBlocks::header('Le contexte'),
                    EditorBlocks::paragraph('Atelier Dupont fabrique des cuisines et des escaliers sur mesure, en bois massif, à Pont-de-Chéruy. Ses clients sont des particuliers de la région, qui choisissent un artisan sur la confiance : ils veulent voir comment on travaille avant de pousser la porte.'),
                ]],
                'pillars' => $this->entries($pillars),
                'calendarTitle' => ['blocks' => [EditorBlocks::header('Le calendrier')]],
                'calendar' => $this->entries($calendar),
                'todo' => ['blocks' => [
                    EditorBlocks::callout('Reste à caler avec Marie : les jours de tournage à l\'atelier, et qui valide les réels.', 'warning', 'En cours de rédaction'),
                ]],
            ],
        ];
    }

    /** @return array{0: string, 1: string, 2: bool, 3: array<string, string|bool>, 4: list<array<string, mixed>>, 5: array<string, mixed>} */
    private function proposal(): array
    {
        $process = [
            'm1' => ['Atelier de lancement', 'Une demi-journée chez vous : votre histoire, vos clients, ce que la marque doit dire.'],
            'm2' => ['Trois pistes', 'Trois directions de logo et de couleurs, présentées et discutées ensemble.'],
            'm3' => ['La piste retenue', 'Affinée en deux allers-retours : le logo, sa palette, ses typographies.'],
            'm4' => ['Les déclinaisons', 'Cartes de visite, devis, enseigne, réseaux sociaux : prêts à l\'emploi.'],
        ];
        $offers = [
            'o1' => ['Essentiel', "Le logo, sa palette et ses typographies, une charte d'une page, et les fichiers pour l'imprimeur.", '1 800 € HT'],
            'o2' => ['Complet', 'Tout l\'essentiel, plus les cartes de visite, l\'en-tête de devis, le visuel de l\'enseigne et les gabarits pour Instagram.', '3 200 € HT'],
        ];
        $timeline = [
            't1' => ['Semaine 1', 'Atelier de lancement.'],
            't2' => ['Semaine 3', 'Présentation des trois pistes.'],
            't3' => ['Semaine 5', 'Piste retenue, affinée.'],
            't4' => ['Semaine 7', 'Livraison des fichiers et de la charte.'],
        ];

        return [
            "Proposition d'accompagnement",
            'Une identité visuelle pour la Menuiserie Fabre : la démarche, deux formules et le calendrier.',
            true,
            // Vert forêt : la piste de couleurs dont nous avons parlé au
            // premier rendez-vous.
            [
                'backgroundColor' => '#f4f7f3',
                'headerColor' => '#1f3a2e',
                'footerColor' => '#1f3a2e',
                'accentColor' => '#2f7d55',
                'headingColor' => '#1f3a2e',
                'figureColor' => '#2f7d55',
                'titleVisible' => false,
            ],
            [
                $this->hero('opening', '#1f3a2e', '#2f7d55'),
                $this->text('understanding'),
                $this->items('process', 'process', array_keys($process), 4),
                $this->text('offersTitle'),
                $this->items('offers', 'offers', array_keys($offers), 2, 'card'),
                $this->items('timeline', 'timeline', array_keys($timeline), 1, 'soft'),
                $this->text('next'),
            ],
            [
                'opening' => ['blocks' => [
                    EditorBlocks::header("Une identité à la hauteur de l'atelier", 2),
                    EditorBlocks::paragraph('Proposition préparée pour Jean Fabre, après notre rendez-vous du 24 septembre. Un logo, une palette et des documents qui disent ce que vos chantiers montrent déjà : du travail soigné, fait pour durer.'),
                ]],
                'understanding' => ['blocks' => [
                    EditorBlocks::header('Ce que nous avons compris'),
                    EditorBlocks::paragraph("La menuiserie a trente ans et une excellente réputation de bouche-à-oreille, mais rien de ce qu'elle montre ne le dit : un logo dessiné à la création, des devis sur un modèle de traitement de texte, une camionnette sans nom. Vous voulez être reconnu sur un chantier comme sur Instagram, sans perdre l'esprit artisan."),
                ]],
                'process' => $this->entries($process),
                'offersTitle' => ['blocks' => [EditorBlocks::header('Deux formules')]],
                'offers' => $this->entries($offers),
                'timeline' => $this->entries($timeline),
                'next' => ['blocks' => [
                    EditorBlocks::header('Pour démarrer'),
                    EditorBlocks::paragraph("Dites-nous la formule qui vous convient : nous vous envoyons le contrat à signer en ligne, et nous fixons ensemble la date de l'atelier de lancement."),
                ]],
            ],
        ];
    }
}
