<?php

declare(strict_types=1);

namespace Aurora\Fixtures\Studio;

use Aurora\Fixtures\Ged\GedDemoFixtures;
use Aurora\Module\Editorial\Post\Grid\GridNormalizer;
use Aurora\Module\Editorial\Post\Service\EditorBlocks;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Repository\CustomerSpaceRepository;
use Aurora\Module\Studio\Deliverable\Entity\Deliverable;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableCategory;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableCategoryInterface;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableLink;
use Aurora\Module\Studio\Deliverable\Enum\DeliverableFormatEnum;
use Aurora\Module\Studio\Deliverable\Enum\DeliverableScopeEnum;
use Aurora\Module\Studio\Deliverable\Repository\DeliverableCategoryRepository;
use Aurora\Module\Studio\Deliverable\Repository\DeliverableLinkRepository;
use Aurora\Module\Studio\Deliverable\Repository\DeliverableRepository;
use Aurora\Module\Studio\Deliverable\Service\DeliverableAppearance;
use Aurora\Module\Studio\Deliverable\Service\DeliverableReadingHeader;
use Aurora\Module\Studio\Deliverable\Slides\Enum\DeckThemeEnum;
use Aurora\Module\Studio\Deliverable\Slides\Enum\SlideLayoutEnum;
use Aurora\Module\Studio\Deliverable\Slides\SlidesManager;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use RuntimeException;

use function array_keys;
use function array_map;
use function file_get_contents;
use function is_array;
use function is_string;
use function json_decode;
use function mb_substr;
use function password_hash;
use function sprintf;
use function str_starts_with;

use const JSON_THROW_ON_ERROR;
use const PASSWORD_DEFAULT;

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
 * Et deux **modèles d'audit des réseaux sociaux** dans Studio, ceux que
 * l'équipe duplique pour chaque client : l'un en page continue, l'autre en
 * présentation aux couleurs du site. Quinze sections, des cartes colorées, un
 * camembert, des [passages à remplacer] : le plus complet de ce que la grille
 * sait faire pour un livrable. Eux, la stratégie qui les suit, le modèle
 * d'audit de l'équipe et la proposition type sont marqués « modèle » : ce sont
 * eux que propose « Partir d'un modèle », dans les deux rayons. La ligne
 * éditoriale écrite pour un menuisier nomme son client, la Menuiserie Fabre. Leur grille est dans `data/*.json`, les images
 * désignées par leur nom (`@doc:`) puisque les identifiants changent à chaque
 * chargement.
 *
 * Et quatre **présentations**, des livrables au format diaporama : la trame
 * d'une réunion de lancement (modèle), la réunion de lancement d'Atelier Dupont
 * avec son lien de lecture, la trame du point mensuel (modèle) et une trame de
 * bilan à la corbeille. Les trois dernières étaient les présentations de
 * Studio avant qu'elles deviennent des livrables.
 *
 * En français seulement : un livrable a une langue, celle de son client.
 *
 * Rejouable : `make fixtures` charge tous les groupes, `make demo` recharge
 * celui-ci, et un livrable déjà présent dans son espace (même titre) est
 * laissé tel quel.
 *
 * Dev/test seulement, groupe `demo`.
 */
class DeliverableDemoFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    private const string DUPONT = 'Atelier Dupont - Réseaux sociaux';

    private const string FABRE = 'Menuiserie Fabre - Identité visuelle';

    /** Toute la largeur, sur chaque écran. */
    private const array FULL = ['base' => 48, 'md' => null, 'lg' => 48];

    private const array HALF = ['base' => 48, 'md' => null, 'lg' => 24];

    public function __construct(
        private readonly CustomerSpaceRepository $spaces,
        private readonly DeliverableRepository $deliverables,
        private readonly GridNormalizer $gridNormalizer,
        private readonly UserRepository $users,
        private readonly DeliverableCategoryRepository $categories,
        private readonly DocumentRepository $documents,
        private readonly DeliverableLinkRepository $links,
        private readonly SlidesManager $slides,
    ) {}

    public static function getGroups(): array
    {
        return ['demo'];
    }

    public function getDependencies(): array
    {
        // La médiathèque de démonstration : les deux modèles d'audit montrent
        // ses images, retrouvées par leur nom.
        return [StudioDemoFixtures::class, GedDemoFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $dupont = $this->space(self::DUPONT);
        $fabre = $this->space(self::FABRE);

        $audit = $this->deliverable($manager, $dupont, ...$this->audit());
        $this->deliverable($manager, $dupont, ...$this->monthlyReport());
        $this->deliverable($manager, $dupont, ...$this->strategy());
        $this->deliverable($manager, $fabre, ...$this->proposal());

        // Les catégories des livrables de Studio, dans l'ordre où l'équipe les
        // range : chaque modèle ci-dessous en reçoit une.
        $proposals = $this->category($manager, 'Propositions', '#34d399', 1);
        $audits = $this->category($manager, 'Audits', '#bd4a55', 2);
        $strategies = $this->category($manager, 'Stratégies', '#8b6cff', 3);
        $reports = $this->category($manager, 'Bilans', '#cd8f31', 4);

        // Deux livrables de Studio, hors de tout espace : une proposition que
        // le compte de démo garde pour lui, et un modèle d'audit que l'équipe
        // partage et reprend pour chaque prospect.
        $author = $this->users->findOneBy(['email' => 'dev@aurora.app', 'type' => UserTypeEnum::Suite->value]);
        [, , , $proposalLook, $proposalZones, $proposalContent] = $this->proposal();
        $this->deliverable(
            $manager,
            null,
            'Proposition type, identité visuelle',
            'La trame de départ, avant de la recopier dans l\'espace du client signé.',
            false,
            $proposalLook,
            $proposalZones,
            $proposalContent,
            $author instanceof CoreUserInterface ? $author : null,
            DeliverableScopeEnum::Personal,
            $proposals,
            template: true,
        );
        [, , , $auditLook, $auditZones, $auditContent] = $this->audit();
        $this->deliverable(
            $manager,
            null,
            'Modèle d\'audit de présence en ligne',
            'Le gabarit de l\'équipe pour un premier audit : on le duplique pour chaque prospect.',
            false,
            $auditLook,
            $auditZones,
            $auditContent,
            $author instanceof CoreUserInterface ? $author : null,
            DeliverableScopeEnum::Shared,
            $audits,
            template: true,
        );

        // Un partagé écrit par une collègue, et un second brouillon perso :
        // chaque rayon a de quoi se lire, et la liste des partagés dit qui a
        // écrit quoi.
        $colleague = $this->users->findOneBy(['email' => 'marie.dupont@aurora.app', 'type' => UserTypeEnum::Suite->value]);
        [, , , $strategyLook, $strategyZones, $strategyContent] = $this->strategy();
        $this->deliverable(
            $manager,
            null,
            'Ligne éditoriale, exemple pour un artisan',
            'Les piliers, le ton et le rythme écrits pour un menuisier : l\'exemple que l\'équipe montre en rendez-vous.',
            false,
            $strategyLook,
            $strategyZones,
            $strategyContent,
            $colleague instanceof CoreUserInterface ? $colleague : null,
            DeliverableScopeEnum::Shared,
            $strategies,
            customer: $fabre->getCustomer(),
        );
        [, , , $reportLook, $reportZones, $reportContent] = $this->monthlyReport();
        $this->deliverable(
            $manager,
            null,
            'Bilan mensuel, nouvelle mise en page',
            'Un essai de bilan plus court, avant de le proposer à l\'équipe.',
            false,
            $reportLook,
            $reportZones,
            $reportContent,
            $author instanceof CoreUserInterface ? $author : null,
            DeliverableScopeEnum::Personal,
            $reports,
        );

        // Les deux modèles d'audit, partagés avec l'équipe et rangés dans les audits.
        $this->model($manager, 'deliverable-audit-model.json', $author, $audits);
        $this->model($manager, 'deliverable-audit-presentation.json', $author, $audits);
        // La stratégie qui suit l'audit, au même habillage, rangée dans les stratégies.
        $this->model($manager, 'deliverable-strategy-presentation.json', $author, $strategies);

        // Une présentation parmi les livrables : des diapositives plutôt
        // qu'une page, un modèle que l'équipe reprend pour chaque lancement.
        $this->kickOffSlides($manager, $author instanceof CoreUserInterface ? $author : null, $proposals);

        // Les présentations de la démo, qui étaient des « présentations » de
        // Studio avant d'être des livrables : celle qu'on montre à un client,
        // avec son lien, la trame qu'on duplique, et une à la corbeille.
        $this->presentations(
            $manager,
            $author instanceof CoreUserInterface ? $author : null,
            $dupont->getCustomer(),
            $this->category($manager, 'Lancement', '#f59e0b', 5),
            $this->category($manager, 'Suivi', '#6366f1', 6),
        );

        // Un livrable que l'équipe a mis à la corbeille : de quoi montrer
        // l'onglet des livrables, et qu'on peut le reprendre.
        $abandoned = $this->deliverable(
            $manager,
            null,
            'Ancienne trame de proposition',
            'Remplacée par la proposition type : mise de côté en attendant de savoir si on la garde.',
            false,
            $proposalLook,
            $proposalZones,
            $proposalContent,
            $author instanceof CoreUserInterface ? $author : null,
            DeliverableScopeEnum::Shared,
            $proposals,
        );
        $abandoned?->setDeletedAt(new DateTimeImmutable('-3 days'));

        $manager->flush();

        $this->readingLinks($manager);

        if ($audit instanceof Deliverable) {
            $link = new DeliverableLink($audit);
            $link->setLabel('Claire Dupont, le 1er octobre');
            $manager->persist($link);
            $manager->flush();
        }
    }

    /**
     * Les trois états d'un lien de lecture, sur le modèle d'audit de l'équipe :
     * un lien déjà ouvert (qui ne peut plus que se retirer), un lien protégé qui
     * expire et que personne n'a ouvert, et un lien neuf (qui peut encore se
     * supprimer). Posés une fois : un rechargement ne les double pas.
     */
    private function readingLinks(ObjectManager $manager): void
    {
        $model = $this->deliverables->findOneBy(['space' => null, 'title' => 'Modèle d\'audit de présence en ligne']);
        if (!$model instanceof Deliverable || [] !== $this->links->findForDeliverable($model)) {
            return;
        }

        $opened = new DeliverableLink($model);
        $opened->setLabel('Claire Dupont, le 1er octobre');
        foreach (['-4 days', '-3 days', '-2 days'] as $when) {
            $opened->touch(new DateTimeImmutable($when));
        }

        $locked = new DeliverableLink($model);
        $locked
            ->setLabel('Direction de la menuiserie Fabre')
            ->setExpiresAt(new DateTimeImmutable('+30 days'))
            ->setPasswordHash(password_hash('verysecure123', PASSWORD_DEFAULT));

        $fresh = new DeliverableLink($model);
        $fresh->setLabel('Marie, version relue');

        foreach ([$opened, $locked, $fresh] as $link) {
            $manager->persist($link);
        }

        $manager->flush();
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
     * @return Deliverable|null le nouveau, ou null quand il était déjà là
     */
    private function deliverable(
        ObjectManager $manager,
        ?CustomerSpaceInterface $space,
        string $title,
        string $summary,
        bool $visible,
        array $appearance,
        array $zones,
        array $content,
        ?CoreUserInterface $owner = null,
        DeliverableScopeEnum $scope = DeliverableScopeEnum::Shared,
        ?DeliverableCategoryInterface $category = null,
        bool $template = false,
        ?CustomerInterface $customer = null,
    ): ?Deliverable {
        $existing = $this->deliverables->findOneBy(['space' => $space, 'title' => $title]);
        if (null !== $existing) {
            $this->catchUp($existing, $category, $template, $customer);

            return null;
        }

        $layout = $this->gridNormalizer->normalizeLayout(['enabled' => true, 'snap' => 4, 'reveal' => 'fade', 'zones' => $zones]);

        $deliverable = new Deliverable($space, $title, 'fr');
        $deliverable
            ->setSummary($summary)
            ->setVisibleToClient($visible)
            ->setGridLayout($layout)
            ->setGridContent($this->gridNormalizer->normalizeContent(['zones' => $content], $layout))
            ->setAppearance(DeliverableAppearance::normalize($appearance))
            ->setReadingHeader(DeliverableReadingHeader::normalize(['preparedFor' => $space?->getCustomer()->getLegalName() ?? '']))
            ->setOwner($owner)
            ->setScope($scope)
            ->setCategory($category)
            ->setTemplate($template)
            ->setCustomer($customer);

        $manager->persist($deliverable);

        return $deliverable;
    }

    /**
     * Un livrable au format diaporama : la trame d'une réunion de lancement,
     * avec les notes de l'orateur que seule la vue présentateur montre.
     * Posé une fois : un rechargement le retrouve par son titre.
     */
    private function kickOffSlides(ObjectManager $manager, ?CoreUserInterface $owner, DeliverableCategoryInterface $category): void
    {
        $title = 'Présentation type, réunion de lancement';
        $existing = $this->deliverables->findOneBy(['space' => null, 'title' => $title]);
        if (null !== $existing) {
            $this->catchUp($existing, $category, template: true);

            return;
        }

        $deliverable = new Deliverable(null, $title, 'fr', DeliverableFormatEnum::Slides);
        $deliverable
            ->setSummary('Le déroulé d\'un premier rendez-vous de projet, à reprendre pour chaque client.')
            ->setOwner($owner)
            ->setScope(DeliverableScopeEnum::Shared)
            ->setCategory($category)
            ->setTemplate(true)
            ->setReadingHeader(DeliverableReadingHeader::normalize(['preparedFor' => '']))
            ->setAppearance(DeliverableAppearance::normalize([]));
        $manager->persist($deliverable);

        $this->slides->writeAppearance($deliverable, DeckThemeEnum::Paper, ['slideNumbers' => true, 'footerText' => 'Réunion de lancement']);

        $slides = [
            [SlideLayoutEnum::Title, ['title' => 'Réunion de lancement', 'subtitle' => '[Nom du client], [date]'], 'Remercier pour le temps pris. Annoncer quarante minutes, questions comprises.'],
            [SlideLayoutEnum::Section, ['title' => 'Ce que vous nous avez dit'], null],
            [SlideLayoutEnum::Bullets, ['title' => 'Trois attentes, dans vos mots', 'bullets' => ['[Première attente]', '[Deuxième attente]', '[Troisième attente]']], 'Faire valider chaque ligne : si une seule est fausse, tout le reste se décale.'],
            [SlideLayoutEnum::Split, [
                'title' => "Ce qu'on vous demande, ce que vous recevez",
                'left' => 'Des photos, quelques textes sur votre métier, et une réponse sous deux jours à chaque validation.',
                'right' => 'Les maquettes, la rédaction finale, la mise en ligne et un point de mesure un mois après.',
            ], 'Les deux colonnes se lisent en parallèle : laisser le temps.'],
            [SlideLayoutEnum::Quote, ['quote' => 'Une personne pour valider, un point par semaine : c\'est ce qui tient les délais.', 'attribution' => 'Notre seule règle'], null],
        ];

        foreach ($slides as [$layout, $content, $notes]) {
            $slide = $this->slides->addSlide($deliverable, $layout);
            $this->slides->writeContent($slide, $content);
            $slide->setSpeakerNotes($notes);
        }
    }

    /**
     * Trois présentations, au format diaporama.
     *
     * - **La réunion de lancement** d'Atelier Dupont : une vraie présentation,
     *   avec un début, une thèse et une fin, une douzaine de diapositives qu'on
     *   pourrait montrer au client sans s'excuser de la démo. Tous les gabarits
     *   y passent, images et diapositive libre comprises, et un lien de
     *   lecture déjà envoyé, ouvert une fois.
     * - **La trame de point mensuel**, un modèle de quatre diapositives
     *   exprès : un squelette qu'on duplique et qu'on remplit, sans client.
     * - **La trame de bilan trimestriel**, à la corbeille : remplacée par la
     *   précédente.
     *
     * Ni audit ni stratégie : ce sont des pages (décision d'Axel du
     * 04/10/2026). Une présentation est ce qu'on montre en réunion.
     *
     * Posées une fois, retrouvées par leur titre : un rechargement ne les
     * double pas, et une démo migrée depuis les anciennes présentations les
     * garde telles qu'elles sont.
     */
    private function presentations(
        ObjectManager $manager,
        ?CoreUserInterface $owner,
        CustomerInterface $customer,
        DeliverableCategoryInterface $kickOff,
        DeliverableCategoryInterface $review,
    ): void {
        $deliverable = $this->slidesDeliverable(
            $manager,
            'Réunion de lancement, refonte du site',
            'Ce que vous attendez du nouveau site, comment on travaille ensemble, et les six semaines qui viennent.',
            $owner,
            $kickOff,
            customer: $customer,
        );

        if ($deliverable instanceof Deliverable) {
            $this->slide($deliverable, SlideLayoutEnum::Title, [
                'title' => 'Réunion de lancement',
                'subtitle' => 'Atelier Dupont, octobre 2026',
            ], 'Remercier pour le temps pris. Annoncer quarante minutes, questions comprises.');

            $this->slide($deliverable, SlideLayoutEnum::Section, [
                'title' => 'Ce que vous nous avez dit',
            ], null);

            $this->slide($deliverable, SlideLayoutEnum::Bullets, [
                'title' => 'Trois attentes, dans vos mots',
                'bullets' => [
                    'Être trouvé par les gens de la région qui cherchent un menuisier',
                    "Montrer l'atelier et les chantiers, pas seulement le catalogue",
                    'Recevoir des demandes de devis plutôt que des appels à toute heure',
                ],
            ], 'Faire valider chaque ligne : si une seule est fausse, tout le reste se décale.');

            // La photo de la médiathèque de démonstration, légendée pour ce
            // qu'elle est réellement : une image de bannière. Une légende qui
            // promettrait une capture d'écran mentirait sur la seule chose que
            // cette slide montre.
            $this->slide($deliverable, SlideLayoutEnum::Image, [
                'mediaId' => $this->mediaId(1),
                'caption' => "Le ton visé pour l'accueil : une grande image, peu de mots",
            ], "Laisser l'image dix secondes avant de commenter.");

            $this->slide($deliverable, SlideLayoutEnum::Quote, [
                'quote' => "Un site qui ressemble à l'atelier, et qui ramène des demandes de devis.",
                'attribution' => 'Votre objectif, en une phrase',
            ], 'Marquer un temps ici : tout ce qui suit sert cette phrase.');

            $this->slide($deliverable, SlideLayoutEnum::Section, [
                'title' => 'Comment on travaille',
            ], null);

            $this->slide($deliverable, SlideLayoutEnum::Bullets, [
                'title' => 'Qui fait quoi',
                'bullets' => [
                    'Vous : les photos des chantiers, les textes sur le métier, une personne pour valider',
                    'Nous : les maquettes, la rédaction finale, la mise en ligne et les mesures',
                    'Ensemble : un point de trente minutes chaque semaine, à heure fixe',
                ],
            ], 'Insister sur « une personne pour valider » : c\'est ce qui tient les délais.');

            $this->slide($deliverable, SlideLayoutEnum::Split, [
                'title' => "Ce qu'on vous demande, ce que vous recevez",
                'left' => 'Une vingtaine de photos de chantiers, trois textes sur votre métier, et une réponse sous deux jours à chaque validation.',
                'right' => 'Un site rapide sur téléphone, une page par type de chantier, un formulaire de devis qui arrive dans votre boîte, et un point de mesure un mois après.',
            ], 'Les deux colonnes se lisent en parallèle : laisser le temps.');

            $this->slide($deliverable, SlideLayoutEnum::Section, [
                'title' => 'Le calendrier',
            ], null);

            $this->slide($deliverable, SlideLayoutEnum::Bullets, [
                'title' => 'Six semaines, trois étapes',
                'bullets' => [
                    'Semaines 1 et 2 : les maquettes, présentées puis ajustées une fois',
                    'Semaines 3 et 4 : les contenus, rédigés à partir de vos photos et de vos notes',
                    'Semaines 5 et 6 : la mise en ligne, puis les premières mesures',
                ],
            ], 'Dire tout de suite la date de mise en ligne visée, et ce qui la ferait glisser.');

            // Une diapositive libre, composée à la main : la démonstration de ce
            // que le canevas sait faire que les gabarits ne font pas. Un dégradé
            // tiré des couleurs de la présentation, une photo découpée en cercle,
            // trois cartes groupées qui entrent une à une, et une flèche posée
            // en biais.
            $this->slide($deliverable, SlideLayoutEnum::Free, [
                'fill' => ['type' => 'linear', 'angle' => 160, 'stops' => [
                    ['color' => 'background', 'at' => 0],
                    ['color' => 'background', 'at' => 55],
                    ['color' => 'accent', 'at' => 100],
                ]],
                'elements' => [
                    ['id' => 'title', 'type' => 'text', 'html' => "Le projet, en un coup d'œil", 'font' => 'heading', 'size' => 64, 'weight' => 700, 'lineHeight' => 1.05, 'x' => 6, 'y' => 9, 'w' => 62, 'h' => 14, 'enter' => 'rise'],
                    ['id' => 'subtitle', 'type' => 'text', 'html' => 'Six semaines, et <span style="color: #f2b33d">une validation</span> à chaque étape', 'size' => 28, 'x' => 6, 'y' => 24, 'w' => 60, 'h' => 8, 'enter' => 'fade', 'delay' => 200],
                    ['id' => 'photo', 'type' => 'image', 'mediaId' => $this->mediaId(1), 'mask' => 'circle', 'x' => 76, 'y' => 6, 'w' => 18, 'h' => 32, 'shadow' => ['x' => 0, 'y' => 12, 'blur' => 40, 'color' => '#00000066']],
                    ['id' => 'arrow', 'type' => 'shape', 'shape' => 'line', 'head' => 'end', 'x' => 66, 'y' => 30, 'w' => 9, 'h' => 4, 'rotate' => -24, 'stroke' => ['color' => 'accent', 'width' => 6, 'style' => 'solid']],
                    ['id' => 'card-1', 'type' => 'shape', 'shape' => 'rect', 'x' => 6, 'y' => 42, 'w' => 27, 'h' => 44, 'radius' => 22, 'fill' => ['type' => 'solid', 'color' => '#ffffff12'], 'stroke' => ['color' => 'accent', 'width' => 2, 'style' => 'solid'], 'reveal' => 1, 'enter' => 'rise', 'group' => 'step-1'],
                    ['id' => 'icon-1', 'type' => 'icon', 'icon' => 'palette', 'color' => 'accent', 'x' => 8.5, 'y' => 47, 'w' => 5, 'h' => 8.889, 'reveal' => 1, 'enter' => 'rise', 'group' => 'step-1'],
                    ['id' => 'head-1', 'type' => 'text', 'html' => 'Les maquettes', 'font' => 'heading', 'size' => 30, 'weight' => 700, 'x' => 8.5, 'y' => 59, 'w' => 22, 'h' => 8, 'reveal' => 1, 'enter' => 'rise', 'group' => 'step-1'],
                    ['id' => 'body-1', 'type' => 'text', 'html' => "L'accueil et une page de chantier, ajustées ensemble.", 'size' => 20, 'lineHeight' => 1.35, 'x' => 8.5, 'y' => 68, 'w' => 22, 'h' => 15, 'reveal' => 1, 'enter' => 'rise', 'group' => 'step-1'],
                    ['id' => 'card-2', 'type' => 'shape', 'shape' => 'rect', 'x' => 36.5, 'y' => 42, 'w' => 27, 'h' => 44, 'radius' => 22, 'fill' => ['type' => 'solid', 'color' => '#ffffff12'], 'stroke' => ['color' => 'accent', 'width' => 2, 'style' => 'solid'], 'reveal' => 2, 'enter' => 'rise', 'group' => 'step-2'],
                    ['id' => 'icon-2', 'type' => 'icon', 'icon' => 'pen-line', 'color' => 'accent', 'x' => 39.0, 'y' => 47, 'w' => 5, 'h' => 8.889, 'reveal' => 2, 'enter' => 'rise', 'group' => 'step-2'],
                    ['id' => 'head-2', 'type' => 'text', 'html' => 'Les contenus', 'font' => 'heading', 'size' => 30, 'weight' => 700, 'x' => 39.0, 'y' => 59, 'w' => 22, 'h' => 8, 'reveal' => 2, 'enter' => 'rise', 'group' => 'step-2'],
                    ['id' => 'body-2', 'type' => 'text', 'html' => 'Vos photos et vos mots, mis en forme par nous.', 'size' => 20, 'lineHeight' => 1.35, 'x' => 39.0, 'y' => 68, 'w' => 22, 'h' => 15, 'reveal' => 2, 'enter' => 'rise', 'group' => 'step-2'],
                    ['id' => 'card-3', 'type' => 'shape', 'shape' => 'rect', 'x' => 67, 'y' => 42, 'w' => 27, 'h' => 44, 'radius' => 22, 'fill' => ['type' => 'solid', 'color' => '#ffffff12'], 'stroke' => ['color' => 'accent', 'width' => 2, 'style' => 'solid'], 'reveal' => 3, 'enter' => 'rise', 'group' => 'step-3'],
                    ['id' => 'icon-3', 'type' => 'icon', 'icon' => 'rocket', 'color' => 'accent', 'x' => 69.5, 'y' => 47, 'w' => 5, 'h' => 8.889, 'reveal' => 3, 'enter' => 'rise', 'group' => 'step-3'],
                    ['id' => 'head-3', 'type' => 'text', 'html' => 'La mise en ligne', 'font' => 'heading', 'size' => 30, 'weight' => 700, 'x' => 69.5, 'y' => 59, 'w' => 22, 'h' => 8, 'reveal' => 3, 'enter' => 'rise', 'group' => 'step-3'],
                    ['id' => 'body-3', 'type' => 'text', 'html' => 'Puis un point de mesure un mois après.', 'size' => 20, 'lineHeight' => 1.35, 'x' => 69.5, 'y' => 68, 'w' => 22, 'h' => 15, 'reveal' => 3, 'enter' => 'rise', 'group' => 'step-3'],
                ],
            ], 'Une carte par pression : laisser lire chacune avant la suivante.');

            $this->slide($deliverable, SlideLayoutEnum::Quote, [
                'quote' => 'Six semaines, une validation à chaque étape, et un site qui ramène des devis.',
                'attribution' => "Ce qu'il faut retenir",
            ], 'Fin. Fixer ensemble la date du premier point avant de se quitter.');

            // Un lien de lecture, l'état ordinaire d'une présentation envoyée :
            // ouverte une fois, elle expire dans deux mois. La lecture est
            // posée à la main, aucune fixture n'ouvrant réellement le lien.
            $link = new DeliverableLink($deliverable);
            $link
                ->setLabel('Atelier Dupont - envoi du 12')
                ->setExpiresAt(new DateTimeImmutable('+60 days'))
                ->touch(new DateTimeImmutable('-2 days 14:05'));
            $manager->persist($link);
        }

        $deliverable = $this->slidesDeliverable(
            $manager,
            'Trame de point mensuel',
            'La forme que prend le point du mois avec un client. À dupliquer, puis à remplir.',
            $owner,
            $review,
            template: true,
        );

        if ($deliverable instanceof Deliverable) {
            $this->slide($deliverable, SlideLayoutEnum::Title, [
                'title' => 'Point du mois',
                'subtitle' => '{client}, {mois}',
            ], 'Remplacer les deux mentions avant de présenter.');

            $this->slide($deliverable, SlideLayoutEnum::Section, [
                'title' => 'Le mois écoulé',
            ], null);

            $this->slide($deliverable, SlideLayoutEnum::Split, [
                'title' => 'Prévu, fait',
                'left' => 'Ce qui était prévu ce mois-ci.',
                'right' => "Ce qui a été fait, et ce qui ne l'a pas été.",
            ], "La colonne de droite d'abord : c'est celle qu'on attend.");

            $this->slide($deliverable, SlideLayoutEnum::Bullets, [
                'title' => 'Le mois qui vient',
                'bullets' => [
                    'Trois priorités, pas plus',
                    'Ce que chacune demande de votre côté',
                    'La date du prochain point',
                ],
            ], null);
        }

        $deliverable = $this->slidesDeliverable(
            $manager,
            'Trame de bilan trimestriel',
            'Remplacée par la trame de point mensuel.',
            $owner,
            null,
        );

        if ($deliverable instanceof Deliverable) {
            $this->slide($deliverable, SlideLayoutEnum::Title, ['title' => 'Bilan du trimestre', 'subtitle' => '{client}'], null);
            $deliverable->setDeletedAt(new DateTimeImmutable('-5 days'));
        }
    }

    /**
     * Un livrable de Studio au format diaporama, partagé avec l'équipe, s'il
     * n'existe pas déjà sous ce titre ; null quand il était là, mis au niveau
     * par {@see self::catchUp()}.
     */
    private function slidesDeliverable(
        ObjectManager $manager,
        string $title,
        string $summary,
        ?CoreUserInterface $owner,
        ?DeliverableCategoryInterface $category,
        bool $template = false,
        ?CustomerInterface $customer = null,
    ): ?Deliverable {
        $existing = $this->deliverables->findOneBy(['space' => null, 'title' => $title]);
        if (null !== $existing) {
            $this->catchUp($existing, $category, $template, $customer);

            return null;
        }

        $deliverable = new Deliverable(null, $title, 'fr', DeliverableFormatEnum::Slides);
        $deliverable
            ->setSummary($summary)
            ->setOwner($owner)
            ->setScope(DeliverableScopeEnum::Shared)
            ->setCategory($category)
            ->setTemplate($template)
            ->setCustomer($customer)
            ->setReadingHeader(DeliverableReadingHeader::normalize(['preparedFor' => '']))
            ->setAppearance(DeliverableAppearance::normalize([]));
        $manager->persist($deliverable);

        $this->slides->writeAppearance($deliverable, DeckThemeEnum::Slate, []);

        return $deliverable;
    }

    /** @param array<string, mixed> $content */
    private function slide(Deliverable $deliverable, SlideLayoutEnum $layout, array $content, ?string $notes): void
    {
        $slide = $this->slides->addSlide($deliverable, $layout);
        $this->slides->writeContent($slide, $content);
        $slide->setSpeakerNotes($notes);
    }

    /**
     * L'identifiant d'une image de la médiathèque de démonstration, par sa
     * référence plutôt qu'en dur : les fixtures se chargent dans l'ordre que
     * choisit le chargeur.
     */
    private function mediaId(int $index): int
    {
        return (int) $this->getReference(GedDemoFixtures::mediaRef($index), Document::class)->getId();
    }

    /**
     * Met un livrable déjà là au niveau de la démo : sa catégorie, sa case
     * « modèle » et son client, s'il ne les a pas encore.
     *
     * Les fixtures s'arrêtaient à « existe déjà » : une catégorie, un modèle ou
     * un client ajoutés après le premier chargement n'arrivaient jamais sur les
     * livrables chargés avant. Seulement ce qui manque : un rangement ou un
     * client choisis à la main ne sont pas défaits par un rechargement.
     */
    private function catchUp(object $deliverable, ?DeliverableCategoryInterface $category, bool $template = false, ?CustomerInterface $customer = null): void
    {
        if (!$deliverable instanceof Deliverable || !$deliverable->isStandalone()) {
            return;
        }

        if ($category instanceof DeliverableCategoryInterface && !$deliverable->getCategory() instanceof DeliverableCategoryInterface) {
            $deliverable->setCategory($category);
        }

        if ($template) {
            $deliverable->setTemplate(true);
        }

        if ($customer instanceof CustomerInterface && !$deliverable->getCustomer() instanceof CustomerInterface) {
            $deliverable->setCustomer($customer);
        }
    }

    /**
     * Un modèle complet, lu dans `data/` : le titre, le résumé, l'apparence, la
     * grille entière et son contenu, avec les images retrouvées par leur nom.
     */
    private function model(ObjectManager $manager, string $file, ?CoreUserInterface $owner, DeliverableCategoryInterface $category): void
    {
        /** @var array{title: string, summary: ?string, appearance: array<string, mixed>, layout: array<string, mixed>, content: array<string, mixed>, locale?: string} $model */
        $model = $this->resolveImages(json_decode((string) file_get_contents(__DIR__.'/data/'.$file), true, flags: JSON_THROW_ON_ERROR));

        $existing = $this->deliverables->findOneBy(['space' => null, 'title' => $model['title']]);
        if (null !== $existing) {
            $this->catchUp($existing, $category, template: true);

            return;
        }

        $layout = $this->gridNormalizer->normalizeLayout([...$model['layout'], 'enabled' => true]);
        $deliverable = new Deliverable(null, $model['title'], $model['locale'] ?? 'fr');
        $deliverable
            ->setSummary($model['summary'])
            ->setVisibleToClient(false)
            ->setGridLayout($layout)
            ->setGridContent($this->gridNormalizer->normalizeContent($model['content'], $layout))
            ->setAppearance(DeliverableAppearance::normalize($model['appearance']))
            ->setReadingHeader(DeliverableReadingHeader::normalize(['preparedFor' => '']))
            ->setOwner($owner)
            ->setScope(DeliverableScopeEnum::Shared)
            ->setCategory($category)
            ->setTemplate(true);

        $manager->persist($deliverable);
    }

    /**
     * Les `@doc:Nom d'origine` d'un modèle remplacés par l'identifiant du
     * document de la médiathèque qui porte ce nom : les identifiants changent à
     * chaque chargement, les noms non. Une image absente laisse un trou plutôt
     * que de faire échouer tout le chargement.
     */
    private function resolveImages(mixed $value): mixed
    {
        if (is_string($value) && str_starts_with($value, '@doc:')) {
            $document = $this->documents->findOneBy(['originalName' => mb_substr($value, 5)]);

            return $document instanceof DocumentInterface ? $document->getId() : null;
        }

        return is_array($value) ? array_map($this->resolveImages(...), $value) : $value;
    }

    /** Une catégorie de livrables, si elle n'existe pas déjà sous ce nom. */
    private function category(ObjectManager $manager, string $name, string $color, int $position): DeliverableCategoryInterface
    {
        $category = $this->categories->findOneBy(['name' => $name]);
        if ($category instanceof DeliverableCategoryInterface) {
            return $category;
        }

        $category = new DeliverableCategory();
        $category->setName($name)->setColor($color)->setPosition($position);
        $manager->persist($category);

        return $category;
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
