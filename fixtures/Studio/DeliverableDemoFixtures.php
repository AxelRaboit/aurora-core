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
 * The demo deliverables: four documents that show what a deliverable can be,
 * finished and dressed.
 *
 * - **The audit** for Atelier Dupont, in the workshop's colours (walnut and
 *   copper): key figures, a chart, findings, a plan in steps, a quote. Open
 *   to the client, with a read link already sent.
 * - **The September review**, in dark: a monthly report, with its reach
 *   curve and the content that worked best. Open to the client.
 * - **The quarter's strategy**, still in progress: closed to the client, to
 *   show that a deliverable is prepared in-house before it opens.
 * - **The proposal** made to Menuiserie Fabre, a prospect: approach, two
 *   packages and a schedule, in forest green. Open to the prospect.
 *
 * And two **social media audit templates** in Studio, the ones the team
 * duplicates for each client: one as a continuous page, the other as a
 * presentation in the site's colours. Fifteen sections, coloured cards, a
 * pie chart, [passages to replace]: the most complete of what the grid can
 * do for a deliverable. They, the strategy that follows them, the team's
 * audit template and the standard proposal are marked as templates: they are
 * what "Start from a template" offers, in both lists. The editorial line
 * written for a carpenter names its client, Menuiserie Fabre. Their grid is
 * in `data/*.json`, the images designated by their name (`@doc:`) since the
 * identifiers change on every load.
 *
 * And four **presentations**, deliverables in slideshow format: the outline
 * of a kickoff meeting (template), the Atelier Dupont kickoff meeting with
 * its read link, the outline of the monthly check-in (template) and a review
 * outline in the trash. The last three were the Studio presentations before
 * they became deliverables.
 *
 * And one presentation inside a client space: Atelier Dupont's October
 * check-in, shown to the client, so their page lists a presentation and opens
 * it in the slide reader (the speaker notes stay on the studio's side).
 *
 * French only: a deliverable has a language, the one of its client.
 *
 * Replayable: `make fixtures` loads every group, `make demo` reloads this
 * one, and a deliverable already present in its space (same title) is left
 * as it is.
 *
 * Dev/test only, `demo` group.
 */
class DeliverableDemoFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    private const string DUPONT = 'Atelier Dupont - Réseaux sociaux';

    private const string FABRE = 'Menuiserie Fabre - Identité visuelle';

    /** Full width, on every screen. */
    private const array FULL = ['base' => 48, 'md' => null, 'lg' => 48];

    private const array HALF = ['base' => 48, 'md' => null, 'lg' => 24];

    public function __construct(
        private readonly CustomerSpaceRepository $spaceRepository,
        private readonly DeliverableRepository $deliverableRepository,
        private readonly GridNormalizer $gridNormalizer,
        private readonly UserRepository $userRepository,
        private readonly DeliverableCategoryRepository $deliverableCategoryRepository,
        private readonly DocumentRepository $documentRepository,
        private readonly DeliverableLinkRepository $deliverableLinkRepository,
        private readonly SlidesManager $slidesManager,
    ) {}

    public static function getGroups(): array
    {
        return ['demo'];
    }

    public function getDependencies(): array
    {
        // The demo media library: the two audit templates show its images, found
        // by their name.
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
        $this->spacePresentation($manager, $dupont);

        // The Studio deliverable categories, in the order the team files them:
        // each template below gets one.
        $proposals = $this->category($manager, 'Propositions', '#34d399', 1);
        $audits = $this->category($manager, 'Audits', '#bd4a55', 2);
        $strategies = $this->category($manager, 'Stratégies', '#8b6cff', 3);
        $reports = $this->category($manager, 'Bilans', '#cd8f31', 4);

        // Two Studio deliverables, outside any space: a proposal the demo account
        // keeps to itself, and an audit template the team shares and reuses for
        // each prospect.
        $author = $this->userRepository->findOneBy(['email' => 'dev@aurora.app', 'type' => UserTypeEnum::Suite->value]);
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

        // A shared one written by a colleague, and a second personal draft: each
        // list has something to read, and the shared list says who wrote what.
        $colleague = $this->userRepository->findOneBy(['email' => 'marie.dupont@aurora.app', 'type' => UserTypeEnum::Suite->value]);
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

        // The two audit templates, shared with the team and filed under audits.
        $this->model($manager, 'deliverable-audit-model.json', $author, $audits);
        $this->model($manager, 'deliverable-audit-presentation.json', $author, $audits);
        // The strategy that follows the audit, in the same dress, filed under strategies.
        $this->model($manager, 'deliverable-strategy-presentation.json', $author, $strategies);

        // A presentation among the deliverables: slides rather than a page, a
        // template the team reuses for each kickoff.
        $this->kickOffSlides($manager, $author instanceof CoreUserInterface ? $author : null, $proposals);

        // The demo presentations, which were Studio "presentations" before being
        // deliverables: the one shown to a client, with its link, the outline that
        // gets duplicated, and one in the trash.
        $this->presentations(
            $manager,
            $author instanceof CoreUserInterface ? $author : null,
            $dupont->getCustomer(),
            $this->category($manager, 'Lancement', '#f59e0b', 5),
            $this->category($manager, 'Suivi', '#6366f1', 6),
        );

        // A deliverable the team put in the trash: enough to show the trash tab of
        // the deliverables, and that it can be restored.
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
     * The three states of a read link, on the team's audit template: a link
     * already opened (which can now only be revoked), a protected link that
     * expires and that nobody has opened, and a new link (which can still be
     * deleted). Set once: a reload does not duplicate them.
     */
    private function readingLinks(ObjectManager $manager): void
    {
        $model = $this->deliverableRepository->findOneBy(['space' => null, 'title' => 'Modèle d\'audit de présence en ligne']);
        if (!$model instanceof Deliverable || [] !== $this->deliverableLinkRepository->findForDeliverable($model)) {
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
        $space = $this->spaceRepository->findOneBy(['name' => $name]);

        if (!$space instanceof CustomerSpaceInterface) {
            throw new RuntimeException(sprintf('The demo space "%s" is missing: load the Studio demo fixtures first.', $name));
        }

        return $space;
    }

    /**
     * A deliverable, unless it already exists in this space.
     *
     * @param list<array<string, mixed>> $zones      the layout, zone by zone
     * @param array<string, mixed>       $content    the content, by zone identifier
     * @param array<string, string|bool> $appearance
     *
     * @return Deliverable|null the new one, or null when it was already there
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
        $existing = $this->deliverableRepository->findOneBy(['space' => $space, 'title' => $title]);
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
     * A deliverable in slideshow format: the outline of a kickoff meeting, with
     * the speaker notes that only the presenter view shows. Set once: a reload
     * finds it by its title.
     */
    private function kickOffSlides(ObjectManager $manager, ?CoreUserInterface $owner, DeliverableCategoryInterface $category): void
    {
        $title = 'Présentation type, réunion de lancement';
        $existing = $this->deliverableRepository->findOneBy(['space' => null, 'title' => $title]);
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

        $this->slidesManager->writeAppearance($deliverable, DeckThemeEnum::Paper, ['slideNumbers' => true, 'footerText' => 'Réunion de lancement']);

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
            $slide = $this->slidesManager->addSlide($deliverable, $layout);
            $this->slidesManager->writeContent($slide, $content);
            $slide->setSpeakerNotes($notes);
        }
    }

    /**
     * Three presentations, in slideshow format.
     *
     * - **The kickoff meeting** of Atelier Dupont: a real presentation, with a
     *   beginning, a thesis and an end, a dozen slides that could be shown to
     *   the client without apologising for the demo. Every template goes
     *   through it, images and free slide included, and a read link already
     *   sent, opened once.
     * - **The monthly check-in outline**, a template of four slides on
     *   purpose: a skeleton to duplicate and fill in, without a client.
     * - **The quarterly review outline**, in the trash: replaced by the
     *   previous one.
     *
     * Neither audit nor strategy: those are pages (Axel's decision of
     * 04/10/2026). A presentation is what gets shown in a meeting.
     *
     * Set once, found by their title: a reload does not duplicate them, and a
     * demo migrated from the old presentations keeps them as they are.
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

            // The photo from the demo media library, captioned for what it really is:
            // a banner image. A caption that promised a screenshot would lie about the
            // only thing this slide shows.
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

            // A free slide, composed by hand: the demonstration of what the canvas can
            // do that the templates cannot. A gradient drawn from the colours of the
            // presentation, a photo cropped into a circle, three grouped cards that
            // enter one by one, and an arrow set at an angle.
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

            // A read link, the ordinary state of a sent presentation: opened once, it
            // expires in two months. The read is recorded by hand, since no fixture
            // actually opens the link.
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
     * A presentation kept in a client space and shown to the client: a short
     * monthly check-in with speaker notes the client never sees. Laid once:
     * a reload finds it by its title in that space.
     */
    private function spacePresentation(ObjectManager $manager, CustomerSpaceInterface $space): void
    {
        $title = 'Point d\'étape, octobre';
        if (null !== $this->deliverableRepository->findOneBy(['space' => $space, 'title' => $title])) {
            return;
        }

        $deliverable = new Deliverable($space, $title, 'fr', DeliverableFormatEnum::Slides);
        $deliverable
            ->setSummary('Le point du mois en cinq diapos : ce qui a marché, ce qui change, ce qu\'on attend de vous.')
            ->setOwner($this->userRepository->findOneBy(['email' => 'dev@aurora.app', 'type' => UserTypeEnum::Suite->value]))
            ->setVisibleToClient(true)
            ->setReadingHeader(DeliverableReadingHeader::normalize(['preparedFor' => $space->getCustomer()->getLegalName()]))
            ->setAppearance(DeliverableAppearance::normalize([]));
        $manager->persist($deliverable);

        $this->slidesManager->writeAppearance($deliverable, DeckThemeEnum::Paper, ['slideNumbers' => true, 'footerText' => $space->getCustomer()->getLegalName()]);

        $this->slide($deliverable, SlideLayoutEnum::Title, ['title' => 'Point d\'étape', 'subtitle' => 'Octobre, réseaux sociaux'], "Rappeler l'objectif du trimestre avant les chiffres.");
        $this->slide($deliverable, SlideLayoutEnum::Bullets, ['title' => 'Ce qui a marché', 'bullets' => ['Les coulisses de l\'atelier, trois fois plus partagées', "Deux demandes de devis venues d'Instagram", 'Un rythme tenu : douze publications sur douze']], "Insister sur les devis : c'est ce qui compte pour eux.");
        $this->slide($deliverable, SlideLayoutEnum::Split, [
            'title' => 'Ce qui change en novembre',
            'left' => 'Moins de visuels produits seuls, plus de mains au travail.',
            'right' => 'Une vidéo courte par semaine, tournée le mardi à l\'atelier.',
        ], null);
        $this->slide($deliverable, SlideLayoutEnum::Bullets, ['title' => "Ce qu'on attend de vous", 'bullets' => ['Vos retours sur le calendrier avant le 25', 'Dix photos de la nouvelle collection', 'Un créneau pour le tournage']], 'Proposer deux dates de tournage, pas une.');
        $this->slide($deliverable, SlideLayoutEnum::End, ['title' => 'Merci', 'subtitle' => 'Prochain point début décembre'], null);
    }

    /**
     * A Studio deliverable in slideshow format, shared with the team, unless it
     * already exists under this title; null when it was there, brought up to
     * date by {@see self::catchUp()}.
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
        $existing = $this->deliverableRepository->findOneBy(['space' => null, 'title' => $title]);
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

        $this->slidesManager->writeAppearance($deliverable, DeckThemeEnum::Slate, []);

        return $deliverable;
    }

    /** @param array<string, mixed> $content */
    private function slide(Deliverable $deliverable, SlideLayoutEnum $layout, array $content, ?string $notes): void
    {
        $slide = $this->slidesManager->addSlide($deliverable, $layout);
        $this->slidesManager->writeContent($slide, $content);
        $slide->setSpeakerNotes($notes);
    }

    /**
     * The identifier of an image from the demo media library, by its reference
     * rather than hard-coded: the fixtures load in the order the loader picks.
     */
    private function mediaId(int $index): int
    {
        return (int) $this->getReference(GedDemoFixtures::mediaRef($index), Document::class)->getId();
    }

    /**
     * Brings a deliverable that is already there up to the demo's level: its
     * category, its "template" box and its client, if it does not have them yet.
     *
     * The fixtures stopped at "already exists": a category, a template or a
     * client added after the first load never reached the deliverables loaded
     * before. Only what is missing: a filing or a client chosen by hand is not
     * undone by a reload.
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
     * A complete template, read from `data/`: the title, the summary, the
     * appearance, the whole grid and its content, with the images found by name.
     */
    private function model(ObjectManager $manager, string $file, ?CoreUserInterface $owner, DeliverableCategoryInterface $category): void
    {
        /** @var array{title: string, summary: ?string, appearance: array<string, mixed>, layout: array<string, mixed>, content: array<string, mixed>, locale?: string} $model */
        $model = $this->resolveImages(json_decode((string) file_get_contents(__DIR__.'/data/'.$file), true, flags: JSON_THROW_ON_ERROR));

        $existing = $this->deliverableRepository->findOneBy(['space' => null, 'title' => $model['title']]);
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
     * The `@doc:Nom d'origine` of a template replaced by the identifier of the
     * media library document that carries that name: identifiers change on
     * every load, names do not. A missing image leaves a hole rather than
     * failing the whole load.
     */
    private function resolveImages(mixed $value): mixed
    {
        if (is_string($value) && str_starts_with($value, '@doc:')) {
            $document = $this->documentRepository->findOneBy(['originalName' => mb_substr($value, 5)]);

            return $document instanceof DocumentInterface ? $document->getId() : null;
        }

        return is_array($value) ? array_map($this->resolveImages(...), $value) : $value;
    }

    /** A deliverable category, unless it already exists under this name. */
    private function category(ObjectManager $manager, string $name, string $color, int $position): DeliverableCategoryInterface
    {
        $category = $this->deliverableCategoryRepository->findOneBy(['name' => $name]);
        if ($category instanceof DeliverableCategoryInterface) {
            return $category;
        }

        $category = new DeliverableCategory();
        $category->setName($name)->setColor($color)->setPosition($position);
        $manager->persist($category);

        return $category;
    }

    /**
     * A text zone set on a gradient, in light contrast: the opening of a
     * document, like a banner block without an image.
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
     * The texts of a list of items, in order: `[title, description]` or
     * `[title, description, caption]`.
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
            // Walnut and copper: the workshop's colours, not the studio's.
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
            // The review in dark: a report reads at a glance, light figures on a night
            // background.
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
            // In progress: the client does not see it yet.
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
            // Forest green: the colour direction we talked about at the first meeting.
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
