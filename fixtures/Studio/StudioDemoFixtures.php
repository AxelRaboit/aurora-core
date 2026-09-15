<?php

declare(strict_types=1);

namespace Aurora\Fixtures\Studio;

use Aurora\Core\Money\Enum\CurrencyEnum;
use Aurora\Fixtures\Core\AppFixtures;
use Aurora\Fixtures\Core\CoreDemoFixtures;
use Aurora\Fixtures\Ged\GedDemoFixtures;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Contract\Dto\ContractInput;
use Aurora\Module\Studio\Contract\Dto\ContractTemplateInput;
use Aurora\Module\Studio\Contract\Dto\ContractTemplateVersionInput;
use Aurora\Module\Studio\Contract\Entity\ContractInterface;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateInterface;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateVersionInterface;
use Aurora\Module\Studio\Contract\Enum\ContractStatusEnum;
use Aurora\Module\Studio\Contract\Enum\ContractTemplateKindEnum;
use Aurora\Module\Studio\Contract\Enum\ContractTerminationOriginEnum;
use Aurora\Module\Studio\Contract\Manager\ContractManagerInterface;
use Aurora\Module\Studio\Contract\Manager\ContractTemplateManagerInterface;
use Aurora\Module\Studio\Contract\Repository\ContractRepository;
use Aurora\Module\Studio\Contract\Repository\ContractTemplateRepository;
use Aurora\Module\Studio\Contract\Signature\Entity\ContractSignature;
use Aurora\Module\Studio\Contract\Signature\Enum\ContractSignatureRoleEnum;
use Aurora\Module\Studio\Customer\Dto\CustomerInput;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\Customer\Manager\CustomerManagerInterface;
use Aurora\Module\Studio\Customer\Repository\CustomerRepository;
use Aurora\Module\Studio\CustomerSpace\Dto\CustomerSpaceInput;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Enum\CustomerSpaceStatusEnum;
use Aurora\Module\Studio\CustomerSpace\Manager\CustomerSpaceManagerInterface;
use Aurora\Module\Studio\CustomerSpace\Repository\CustomerSpaceRepository;
use Aurora\Module\Studio\Deck\Entity\DeckCategory;
use Aurora\Module\Studio\Deck\Entity\DeckInterface;
use Aurora\Module\Studio\Deck\Enum\SlideLayoutEnum;
use Aurora\Module\Studio\Deck\Manager\DeckManager;
use Aurora\Module\Studio\Deck\Repository\DeckRepository;
use Aurora\Module\Studio\SpaceContent\Dto\SpaceContentColumnInput;
use Aurora\Module\Studio\SpaceContent\Dto\SpaceContentItemInput;
use Aurora\Module\Studio\SpaceContent\Manager\SpaceContentColumnManagerInterface;
use Aurora\Module\Studio\SpaceContent\Manager\SpaceContentItemManagerInterface;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentColumnRepository;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use RuntimeException;

use function sprintf;

/**
 * Demo content for the Studio module: three customers, five client spaces,
 * three trames and a contract in each state the list can draw.
 *
 * Written because the module had none, and because a module with no demo data
 * has no screenshot of itself - the three screens could be opened locally and
 * showed "nothing yet", which is not a picture anybody can put on a page.
 *
 * **Every word of contract wording here is invented.** The five real trames
 * live in production and nowhere else: this repository is public, and a real
 * client's clauses have no business in it. What the demo needs is text of the
 * right shape and length - a title, a few clauses, the tokens - not the real
 * text.
 *
 * **The customers are invented too**, with SIRETs that pass the checksum
 * because the validator is real and would reject a made-up string of digits.
 * They are the same three names the rest of the demo data uses, so a reader
 * moving from the users screen to the customers screen recognises them.
 *
 * **Dates are relative to today**, like the calendar fixture next door: a
 * contract signed on a date written into the file is a contract that reads as
 * ancient history six months from now.
 *
 * **Re-running is safe.** `make demo` loads with `--append`, so everything is
 * found before it is created and the contracts are only built for trames this
 * run created itself.
 */
class StudioDemoFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    /**
     * The provider identity the trames read through `{{provider.*}}`.
     *
     * Filled here because the seal refuses to run while one of them is empty -
     * a guard added on purpose, so that a contract can never go out quoting a
     * blank. A demo instance therefore needs an identity of its own, and an
     * invented one is the only honest choice in a public repository.
     */
    private const array PROVIDER = [
        'studio_provider_name' => 'Studio Aurora (démonstration)',
        'studio_provider_representative' => 'Camille Vasseur, gérante',
        'studio_provider_address' => '12 rue des Fabriques, 69000 Lyon',
        'studio_provider_siret' => '83787330207491',
        'studio_provider_ape_code' => '62.01Z (programmation informatique)',
        'studio_provider_vat_mention' => 'TVA non applicable, article 293 B du CGI',
        'studio_provider_email' => 'contact@studio-aurora.test',
        'studio_provider_phone' => '04 00 00 00 00',
        'studio_provider_bank_holder' => 'Studio Aurora',
        'studio_provider_bank_iban' => 'FR7630001007941234567890185',
        'studio_provider_bank_bic' => 'DEMOFRPP',
        'studio_provider_bank_name' => 'Banque de démonstration',
    ];

    public function __construct(
        private readonly CustomerManagerInterface $customers,
        private readonly CustomerRepository $customerRepository,
        private readonly CustomerSpaceManagerInterface $spaces,
        private readonly CustomerSpaceRepository $spaceRepository,
        private readonly UserRepository $userRepository,
        private readonly SpaceContentItemManagerInterface $contentItems,
        private readonly SpaceContentColumnManagerInterface $contentColumnManager,
        private readonly SpaceContentColumnRepository $contentColumns,
        private readonly ContractTemplateManagerInterface $templates,
        private readonly ContractTemplateRepository $templateRepository,
        private readonly ContractManagerInterface $contracts,
        private readonly ContractRepository $contractRepository,
        private readonly DeckManager $decks,
        private readonly DeckRepository $deckRepository,
        private readonly SettingRepository $settings,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public static function getGroups(): array
    {
        return ['demo'];
    }

    public function getDependencies(): array
    {
        // The GED fixtures, for the two slides that carry a picture: a
        // full-page image points at a document in the library rather than
        // carrying a file of its own. `CoreDemoFixtures` for the two demo
        // accounts the spaces put on their teams.
        return [AppFixtures::class, CoreDemoFixtures::class, GedDemoFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $this->seedProviderIdentity();

        $marie = $this->customer(
            legalName: 'Atelier Dupont',
            legalForm: 'SARL',
            siret: '11281704039760',
            office: '4 place du Marché, 38230 Pont-de-Chéruy',
            firstName: 'Marie',
            lastName: 'Dupont',
            role: 'Gérante',
            email: 'marie.dupont@aurora.app',
            sector: 'Menuiserie',
            capitalCents: 1_000_000,
        );

        $jean = $this->customer(
            legalName: 'Martin Documents',
            legalForm: 'SAS',
            siret: '73245630779356',
            office: '17 avenue de la Gare, 69100 Villeurbanne',
            firstName: 'Jean',
            lastName: 'Martin',
            role: 'Président',
            email: 'jean.martin@aurora.app',
            sector: 'Archivage',
            capitalCents: 5_000_000,
        );

        $sophie = $this->customer(
            legalName: 'Roux Photographie',
            legalForm: 'Entreprise individuelle',
            siret: '98700210228712',
            office: '3 chemin des Vignes, 38200 Vienne',
            firstName: 'Sophie',
            lastName: 'Roux',
            role: 'Photographe',
            email: 'sophie.roux@aurora.app',
            sector: 'Photographie',
            capitalCents: null,
        );

        // A body and an annex, which is the pairing the module is built around:
        // the body never changes per offer, the annex carries what does. Plus a
        // second body left with an unpublished draft, so the list shows the
        // amber badge and the "open draft" state without anybody having to
        // click first.
        $monthly = $this->template('Contrat de prestation mensuelle', ContractTemplateKindEnum::Body, $this->monthlyBody());
        $annex = $this->template('Annexe - formule Suivi', ContractTemplateKindEnum::Annex, $this->annexFormula());
        $oneShot = $this->template('Contrat de prestation ponctuelle', ContractTemplateKindEnum::Body, $this->oneShotBody());
        $amendmentTrame = $this->template('Avenant', ContractTemplateKindEnum::Body, $this->amendmentBody());

        // The draft that stays a draft. Opened after publication, so the trame
        // has both a version in force and a version being written - the pair
        // the version badge exists to tell apart. Only when there is not one
        // already: a trame may hold a single draft, guaranteed by a partial
        // index, and a second `make demo` must not go asking for a second.
        if (!$oneShot->getDraft() instanceof ContractTemplateVersionInterface) {
            $this->templates->openDraft($oneShot);
            $this->entityManager->flush();
        }

        // Before the contracts guard below, and not after it: the decks were
        // seeded at the end of this method and therefore never seeded at all
        // on an instance that already had contracts, which is every instance
        // where `make demo` had been run once.
        $this->seedDecks($marie);
        $this->seedSpaces($marie, $jean, $sophie);

        // Nothing below is built if the instance already has contracts. The
        // seal mints a reference from a yearly sequence, so a second run would
        // not collide - it would just quietly double a list that is meant to be
        // read, which is worse.
        if (0 !== $this->contractRepository->count([])) {
            $this->entityManager->flush();

            return;
        }

        // 1. A draft, still editable, no reference yet.
        $this->contract($marie, $monthly, $annex, 490_00, '+1 month', [
            'formule' => 'Suivi',
            'duree' => '12 mois',
        ]);

        // 2. Sealed and sent, waiting for an answer. The state most of the list
        //    is in on any given day.
        $waiting = $this->contract($jean, $monthly, $annex, 690_00, '+2 weeks', [
            'formule' => 'Suivi',
            'duree' => '24 mois',
        ]);
        $this->seal($waiting, ContractStatusEnum::Sent);
        $waiting->markReminded(new DateTimeImmutable('-1 day'));

        // 3. Signed by the customer and countersigned: a concluded contract,
        //    with both signatures on the same hash.
        $concluded = $this->contract($sophie, $monthly, $annex, 390_00, '+1 week', [
            'formule' => 'Essentiel',
            'duree' => '12 mois',
        ]);
        $this->seal($concluded, ContractStatusEnum::Countersigned);
        $this->sign($concluded, ContractSignatureRoleEnum::Customer, $sophie, '-2 days');
        $this->sign($concluded, ContractSignatureRoleEnum::Provider, $sophie, '-1 day');

        // 4. An amendment of the concluded one, which is the whole point of the
        //    design: the parent is untouched and this document says what it
        //    changes.
        $amendment = $this->amendment($concluded, $amendmentTrame, 490_00, '+1 month', [
            'avenant_objet' => 'Passage de la formule Essentiel à la formule Suivi',
            'avenant_duree' => "Jusqu'au terme du contrat initial",
        ]);

        $this->seal($amendment, ContractStatusEnum::Countersigned);
        $this->sign($amendment, ContractSignatureRoleEnum::Customer, $sophie, '-1 day');
        $this->sign($amendment, ContractSignatureRoleEnum::Provider, $sophie, 'now');

        // 5. A refusal, because a list that only shows agreements teaches the
        //    wrong thing about what the module handles.
        $refused = $this->contract($marie, $monthly, $annex, 890_00, '+3 weeks', [
            'formule' => 'Suivi',
            'duree' => '36 mois',
        ]);
        $this->seal($refused, ContractStatusEnum::Sent);
        $refused->refuse(
            new DateTimeImmutable('-1 day'),
            'Budget reporte au prochain exercice.',
            '203.0.113.24',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
        );

        // 6. A terminated relationship: concluded, then ended with notice.
        $ended = $this->contract($jean, $monthly, $annex, 290_00, '-1 year', [
            'formule' => 'Essentiel',
            'duree' => '12 mois',
        ]);
        $this->seal($ended, ContractStatusEnum::Countersigned);
        $this->sign($ended, ContractSignatureRoleEnum::Customer, $jean, '-4 days');
        $this->sign($ended, ContractSignatureRoleEnum::Provider, $jean, '-3 days');
        $ended->terminate(
            new DateTimeImmutable('now'),
            new DateTimeImmutable('+2 months'),
            ContractTerminationOriginEnum::Customer,
            "Fin de la mission, arrêt à l'échéance annuelle.",
        );

        $this->entityManager->flush();
    }

    /**
     * Five client spaces, chosen for the states a reader needs to recognise.
     *
     * Atelier Dupont gets two, which is the decision the whole design turns on:
     * a space belongs to a customer and a customer may have several, so a
     * client with two engagements running at once is the normal case rather
     * than a workaround. A single space per company would have looked tidier
     * here and taught the wrong thing.
     *
     * One is archived, and it has to be: the "show archived" switch only
     * appears once there is something behind it, so a demo with none hides a
     * feature nobody would then think to look for. It is also the space in a
     * foreign zone, which is where the timezone field stops being decoration.
     *
     * One has nobody on it, so the empty team reads as a state rather than as
     * a loading failure.
     */
    private function seedSpaces(
        CustomerInterface $marie,
        CustomerInterface $jean,
        CustomerInterface $sophie,
    ): void {
        // Built once, like the decks and the contracts. A space has no natural
        // key to look one up by, so a second `make demo` would quietly double
        // a list that is meant to be read.
        if (0 !== $this->spaceRepository->count([])) {
            return;
        }

        $admin = $this->backendUser('dev@aurora.app');
        $marieAccount = $this->backendUser('marie.dupont@aurora.app');
        $jeanAccount = $this->backendUser('jean.martin@aurora.app');

        $social = $this->space(
            name: 'Atelier Dupont - Réseaux sociaux',
            description: 'Deux publications par semaine, Instagram et Facebook. Validation le jeudi.',
            customer: $marie,
            members: [$marieAccount => 'lead', $jeanAccount => 'member'],
        );

        // One board filled, and only one. A demo where every space holds the
        // same five cards teaches that the cards come with the product; a
        // single busy board beside four empty ones shows both states, which is
        // what the screens have to be able to draw.
        $this->seedBoard($social);

        $this->space(
            name: 'Atelier Dupont - Refonte du site',
            description: 'Reprise des textes et des photos de chantier, livraison au printemps.',
            customer: $marie,
            members: [$admin => 'lead'],
        );

        $this->space(
            name: 'Martin Documents - Contenus LinkedIn',
            description: 'Une publication hebdomadaire sur l\'archivage réglementaire.',
            customer: $jean,
            members: [$jeanAccount => 'lead', $admin => 'member'],
        );

        $this->space(
            name: 'Roux Photographie - Portfolio 2026',
            description: "Sélection des séries de l'année et mise à jour des pages du site.",
            customer: $sophie,
            members: [],
        );

        $this->space(
            name: 'Martin Documents - Marché espagnol',
            description: 'Campagne de lancement en Espagne. Chantier terminé, gardé pour ses contenus.',
            customer: $jean,
            members: [$jeanAccount => 'lead'],
            status: CustomerSpaceStatusEnum::Archived,
            timezone: 'Europe/Madrid',
        );
    }

    /**
     * A week of content on one board, spread across its steps.
     *
     * Dates are relative to today, like the contracts above: a calendar seeded
     * with dates written into the file reads as abandoned within a month, and
     * this is the data the calendar view will be photographed against.
     *
     * Three of the eight carry no date on purpose. An idea with no date is the
     * state the board exists for - it is the work that has not been scheduled
     * yet - and a demo where everything is scheduled hides half of what the
     * nullable column is for.
     */
    private function seedBoard(CustomerSpaceInterface $space): void
    {
        if ([] === $this->contentColumns->findForSpace($space)) {
            return;
        }

        // A sixth step, added after the fact and given a colour of its own.
        // The five a space is born with show the defaults; this one shows the
        // decision behind them - the steps belong to the space, so a client
        // whose posts go past a lawyer has one nobody else has. A demo that
        // only ever showed the default five would teach that they are the
        // product's, which is the opposite of what the table is for.
        $this->contentColumnManager->create($space, new SpaceContentColumnInput(
            name: 'Relecture juridique',
            colourSlot: 8,
        ));

        $columns = $this->contentColumns->findForSpace($space);

        // Keyed by the step's place, not its name: the names are translated at
        // creation and a demo that matched on "Idées" would seed nothing the
        // day somebody creates a space in English.
        $cards = [
            0 => [
                ['Portrait de l\'équipe', "Photo de groupe devant l'atelier, format carré.", null],
                ['Les essences de bois', 'Un fil sur le chêne, le noyer et le frêne.', null],
                ['Avant / après cuisine', 'La rénovation de septembre, en deux images.', null],
            ],
            1 => [
                ['Coulisses du chantier Morel', "Trois photos de l'escalier en cours.", '+3 days 09:00'],
            ],
            2 => [
                ['Offre de rentrée', 'Le devis gratuit jusqu\'au 30. À faire valider avant mardi.', '+5 days 18:00'],
            ],
            3 => [
                ['Journée portes ouvertes', "Rappel de l'événement du 12, avec le plan d'accès.", '+8 days 10:00'],
                ['Témoignage client', 'Le retour de Mme Lefèvre sur sa bibliothèque.', '+10 days 09:00'],
            ],
            4 => [
                ['Le nouvel atelier', "L'annonce du déménagement, parue la semaine dernière.", '-4 days 09:00'],
            ],
            5 => [
                ['Conditions du jeu concours', 'Le règlement relu par le cabinet avant publication.', '+14 days 09:00'],
            ],
        ];

        foreach ($cards as $at => $rows) {
            if (!isset($columns[$at])) {
                continue;
            }

            foreach ($rows as [$title, $body, $when]) {
                $this->contentItems->create($space, new SpaceContentItemInput(
                    title: $title,
                    body: $body,
                    columnId: $columns[$at]->getId(),
                    scheduledAt: null === $when
                        ? null
                        : new DateTimeImmutable($when)->format('Y-m-d\TH:i'),
                ));
            }
        }
    }

    /**
     * One space, through the Manager rather than around it.
     *
     * So the demo exercises the same path a person does: the colour is spread
     * across the palette by the same rule, and the audit log carries the same
     * rows. Fixtures that persist entities directly produce data no code ever
     * produced, which is how a demo stops resembling the product.
     *
     * @param array<int, string> $members account id => role value
     */
    private function space(
        string $name,
        string $description,
        CustomerInterface $customer,
        array $members,
        CustomerSpaceStatusEnum $status = CustomerSpaceStatusEnum::Active,
        string $timezone = 'Europe/Paris',
    ): CustomerSpaceInterface {
        $rows = [];
        foreach ($members as $userId => $role) {
            $rows[] = ['userId' => $userId, 'role' => $role];
        }

        return $this->spaces->create(new CustomerSpaceInput(
            name: $name,
            description: $description,
            customerId: $customer->getId(),
            status: $status,
            // Left null so the Manager spreads the palette itself. Naming a
            // slot here would have hard-coded five colours that stop being
            // free the moment somebody adds a space of their own.
            colourSlot: null,
            timezone: $timezone,
            members: $rows,
        ));
    }

    /** The demo account behind an address, which the core fixtures put there. */
    private function backendUser(string $email): int
    {
        $user = $this->userRepository->findOneBy([
            'email' => $email,
            'type' => UserTypeEnum::Backend->value,
        ]);

        if (!$user instanceof User) {
            throw new RuntimeException(sprintf('The demo account %s is missing - run the core fixtures first.', $email));
        }

        return (int) $user->getId();
    }

    /**
     * Two decks: one written to be looked at, one written to be duplicated.
     *
     * The first is a real talk, in the sense that it has a beginning, a claim
     * and an end - twelve slides that could be given to a client without
     * anybody apologising for the demo. That is what the module's screenshots
     * need: a deck built to fill a page shows the editor, not the thing the
     * editor is for.
     *
     * The second stays four slides on purpose. A trame is a skeleton somebody
     * duplicates and fills, and dressing it up would hide what it is.
     *
     * Between them they use every layout, images included: the full-page
     * picture reads nothing from the deck itself, it points at a document in
     * the library, so the two slides that carry one pull it by reference from
     * the GED fixtures rather than inventing a file of their own.
     */
    private function seedDecks(CustomerInterface $customer): void
    {
        // Built once, like the contracts above. Nothing here is looked up
        // before it is created - a deck has no natural key to look it up by -
        // so a second run would silently double a list meant to be read.
        if (0 !== $this->deckRepository->count([])) {
            return;
        }

        $audit = new DeckCategory();
        $audit->setName('Audit')->setColor('#f59e0b')->setPosition(0);

        $strategy = new DeckCategory();
        $strategy->setName('Stratégie')->setColor('#6366f1')->setPosition(1);

        $this->entityManager->persist($audit);
        $this->entityManager->persist($strategy);

        $this->seedAuditDeck($customer, $audit);
        $this->seedStrategyTemplate($strategy);
    }

    /**
     * The deck that gets shown: an audit, from the complaint to the quote.
     *
     * Ordered the way the conversation actually goes. The client says the site
     * is slow, so the numbers come before the causes, the causes before the
     * plan, and the plan before what it costs. The quote at the end is the
     * sentence that lets somebody decide, which is what a deck is for.
     */
    private function seedAuditDeck(CustomerInterface $customer, DeckCategory $category): void
    {
        $deck = $this->decks->create('Audit du site, septembre');
        $deck->setDescription('Ce que le site fait mal, ce que ça coûte, et dans quel ordre le reprendre.');
        $deck->setCategory($category);
        $deck->setCustomer($customer);

        $this->slide($deck, SlideLayoutEnum::Title, [
            'title' => 'Audit du site',
            'subtitle' => 'Atelier Dupont, septembre 2026',
        ], "Remercier pour l'accès aux statistiques. Annoncer vingt minutes, questions comprises.");

        $this->slide($deck, SlideLayoutEnum::Section, [
            'title' => 'Ce que disent les chiffres',
        ], null);

        $this->slide($deck, SlideLayoutEnum::Bullets, [
            'title' => 'Trois mesures, prises sur mobile',
            'bullets' => [
                '3,4 secondes avant que la première image apparaisse',
                "54 % des visiteurs repartent avant d'avoir vu quoi que ce soit",
                '4,2 Mo chargés pour une page qui en montre 300 Ko',
            ],
        ], 'Insister sur la deuxième ligne : le reste en découle.');

        // La photo de la médiathèque de démonstration, légendée pour ce
        // qu'elle est réellement : une image de bannière. Une légende qui
        // promettrait une capture d'écran mentirait sur la seule chose que
        // cette slide montre.
        $this->slide($deck, SlideLayoutEnum::Image, [
            'mediaId' => $this->mediaId(1),
            'caption' => "La bannière d'accueil : 1,4 Mo servis pour 180 Ko réellement affichés",
        ], "Laisser l'image dix secondes avant de commenter.");

        $this->slide($deck, SlideLayoutEnum::Quote, [
            'quote' => 'On ne répare pas un site lent, on arrête de le ralentir.',
            'attribution' => 'La seule règle de cet audit',
        ], 'Marquer un temps ici.');

        $this->slide($deck, SlideLayoutEnum::Section, [
            'title' => "D'où ça vient",
        ], null);

        $this->slide($deck, SlideLayoutEnum::Bullets, [
            'title' => 'Trois causes, dans cet ordre',
            'bullets' => [
                "Les images partent à leur taille d'origine, quelle que soit la place où elles s'affichent",
                'Six polices sont chargées, deux sont utilisées',
                'Chaque page rappelle la base quarante fois pour afficher le menu',
            ],
        ], "Ne pas s'excuser : ce sont des réglages, pas des fautes.");

        $this->slide($deck, SlideLayoutEnum::Split, [
            'title' => 'Avant, après',
            'left' => '3,4 secondes de chargement, un visiteur sur deux qui repart avant la première image, et un référencement qui plafonne parce que Google mesure la même chose que lui.',
            'right' => "Moins d'une seconde, les images servies à la taille réellement affichée, et le menu calculé une fois pour toutes au lieu de quarante requêtes par page.",
        ], 'Les deux colonnes se lisent en parallèle : laisser le temps.');

        $this->slide($deck, SlideLayoutEnum::Section, [
            'title' => 'Ce que je propose',
        ], null);

        $this->slide($deck, SlideLayoutEnum::Bullets, [
            'title' => 'Trois semaines, trois chantiers',
            'bullets' => [
                'Semaine 1 : les images, qui à elles seules rendent deux secondes',
                'Semaine 2 : les polices et le menu, moins spectaculaire, plus durable',
                'Semaine 3 : les mesures refaites, et la page qui les affiche',
            ],
        ], "Dire que la semaine 3 n'est pas négociable : sans mesure, rien ne prouve que ça a marché.");

        $this->slide($deck, SlideLayoutEnum::Quote, [
            'quote' => "Trois semaines pour passer de 3,4 secondes à moins d'une. Le reste du site n'y touche pas.",
            'attribution' => "Ce qu'il faut retenir",
        ], 'Fin. Laisser venir les questions sans enchaîner.');
    }

    /**
     * The other kind of deck: a skeleton, addressed to nobody.
     *
     * Deliberately short and deliberately vague, because it exists to be
     * duplicated per client rather than presented as it stands. The nullable
     * customer is the decision worth seeing on screen: a deck written for
     * oneself is the ordinary internal case, not a degraded one.
     */
    private function seedStrategyTemplate(DeckCategory $category): void
    {
        $deck = $this->decks->create('Trame de stratégie annuelle');
        $deck->setDescription("La forme que prend une revue de fin d'année. À dupliquer par client, puis à remplir.");
        $deck->setCategory($category);

        $this->slide($deck, SlideLayoutEnum::Title, [
            'title' => 'Revue annuelle',
            'subtitle' => '{client}, {année}',
        ], 'Remplacer les deux mentions avant de présenter.');

        $this->slide($deck, SlideLayoutEnum::Section, [
            'title' => 'Où en est-on',
        ], null);

        $this->slide($deck, SlideLayoutEnum::Split, [
            'title' => "L'année écoulée",
            'left' => 'Ce qui était prévu.',
            'right' => "Ce qui a été fait, et ce qui ne l'a pas été.",
        ], "La colonne de droite d'abord : c'est celle qu'on attend.");

        $this->slide($deck, SlideLayoutEnum::Bullets, [
            'title' => "L'année qui vient",
            'bullets' => [
                'Trois priorités, pas plus',
                'Ce que chacune demande, en semaines',
                'Ce qui est abandonné, et pourquoi',
            ],
        ], null);
    }

    /**
     * The id of a demo picture from the media library.
     *
     * By reference rather than by a hardcoded id: the fixtures run in whatever
     * order the loader chooses, and a number written here would point at
     * whatever happened to be created first.
     */
    private function mediaId(int $index): int
    {
        return (int) $this->getReference(GedDemoFixtures::mediaRef($index), Document::class)->getId();
    }

    /** @param array<string, mixed> $content */
    private function slide(DeckInterface $deck, SlideLayoutEnum $layout, array $content, ?string $notes): void
    {
        $slide = $this->decks->addSlide($deck, $layout);
        $this->decks->writeContent($slide, $content);
        $slide->setSpeakerNotes($notes);
    }

    /**
     * Twelve settings, overwritten whatever was there.
     *
     * Deliberately not "only when empty", which is what this did first and
     * which leaked immediately: a local database seeded from production
     * already held the real identity, so the demo contracts sealed a real
     * name, a real SIRET and a real IBAN into their snapshots - and a snapshot
     * cannot be corrected afterwards, which is the whole point of it.
     *
     * The reasoning is the same one that keeps the real trames out of a local
     * instance. A demo instance is for looking at and for taking pictures of,
     * so nothing that must not appear in a picture belongs in it.
     */
    private function seedProviderIdentity(): void
    {
        foreach (self::PROVIDER as $key => $value) {
            $this->settings->set(ApplicationParameterEnum::from($key)->value, $value);
        }
    }

    private function customer(
        string $legalName,
        string $legalForm,
        string $siret,
        string $office,
        string $firstName,
        string $lastName,
        string $role,
        string $email,
        string $sector,
        ?int $capitalCents,
    ): CustomerInterface {
        $existing = $this->customerRepository->findOneBy(['legalName' => $legalName]);

        if (null !== $existing) {
            return $existing;
        }

        return $this->customers->create(new CustomerInput(
            legalName: $legalName,
            legalForm: $legalForm,
            shareCapitalCents: $capitalCents,
            shareCapitalCurrency: null === $capitalCents ? null : CurrencyEnum::EUR,
            registeredOffice: $office,
            siret: $siret,
            vatNumber: null,
            activitySector: $sector,
            representativeFirstName: $firstName,
            representativeLastName: $lastName,
            representativeRole: $role,
            contractualEmail: $email,
        ));
    }

    /**
     * A trame with its first version published, or the one already there.
     *
     * Found by name, because that is what a person reads in the back office.
     * Returning the existing one rather than skipping matters on a database
     * that has been seeded from elsewhere: the demo has to be able to run on
     * top of whatever is already there without doubling it.
     *
     * @param list<array<string, mixed>> $blocks
     */
    private function template(string $name, ContractTemplateKindEnum $kind, array $blocks): ContractTemplateInterface
    {
        $existing = $this->templateRepository->findOneBy(['name' => $name]);

        if (null !== $existing) {
            return $existing;
        }

        $template = $this->templates->create(new ContractTemplateInput(name: $name, kind: $kind));
        $this->entityManager->flush();

        $version = $template->getDraft();

        if (!$version instanceof ContractTemplateVersionInterface) {
            return $template;
        }

        $this->templates->updateDraft($version, new ContractTemplateVersionInput(
            translations: ['fr' => ['title' => $name, 'content' => ['blocks' => $blocks]]],
        ));
        $this->templates->publish($version);

        $this->entityManager->flush();

        return $template;
    }

    /** @param array<string, string> $customFields */
    private function contract(
        CustomerInterface $customer,
        ContractTemplateInterface $body,
        ?ContractTemplateInterface $annex,
        int $amountCents,
        string $effectiveDate,
        array $customFields,
    ): ContractInterface {
        $contract = $this->contracts->create(new ContractInput(
            customerId: $customer->getId(),
            bodyTemplateId: $body->getId(),
            annexTemplateId: $annex?->getId(),
            locale: 'fr',
            amountCents: $amountCents,
            amountCurrency: CurrencyEnum::EUR->value,
            effectiveDate: new DateTimeImmutable($effectiveDate)->format('Y-m-d'),
            customFields: $customFields,
        ));

        $this->entityManager->flush();

        return $contract;
    }

    /** @param array<string, string> $customFields */
    private function amendment(
        ContractInterface $parent,
        ContractTemplateInterface $body,
        int $amountCents,
        string $effectiveDate,
        array $customFields,
    ): ContractInterface {
        $customer = $parent->getCustomer();

        $contract = $this->contracts->create(new ContractInput(
            customerId: $customer->getId(),
            bodyTemplateId: $body->getId(),
            locale: 'fr',
            amountCents: $amountCents,
            amountCurrency: CurrencyEnum::EUR->value,
            effectiveDate: new DateTimeImmutable($effectiveDate)->format('Y-m-d'),
            customFields: $customFields,
            amendsId: $parent->getId(),
        ));

        $this->entityManager->flush();

        return $contract;
    }

    /**
     * Seal, then move the clock back and set the status the demo wants.
     *
     * The seal itself goes through the manager, because everything worth
     * looking at on the document page is computed there: the canonical form,
     * the hash, the rendered HTML and the reference. Only the status is then
     * set directly - the real paths to `sent` and `countersigned` post emails,
     * and a fixture that fills a mailbox every time it loads is a fixture
     * people stop loading.
     *
     * The seal date is not back-dated, and cannot be: `frozenAt` is written by
     * the seal and has no setter, which is the immutability doing its job. So
     * every demo contract reads as sealed today, and the dates that do vary -
     * effective date, signature, notice - are set around that.
     */
    private function seal(ContractInterface $contract, ContractStatusEnum $status): void
    {
        $this->contracts->freeze($contract);
        $this->entityManager->flush();

        $contract->setStatus($status);
    }

    private function sign(
        ContractInterface $contract,
        ContractSignatureRoleEnum $role,
        CustomerInterface $customer,
        string $signedAt,
    ): void {
        $signature = new ContractSignature();
        $signature
            ->setContract($contract)
            ->setRole($role)
            ->setDeclaredFirstName(ContractSignatureRoleEnum::Provider === $role ? 'Camille' : (string) $customer->getRepresentativeFirstName())
            ->setDeclaredLastName(ContractSignatureRoleEnum::Provider === $role ? 'Vasseur' : (string) $customer->getRepresentativeLastName())
            ->setDeclaredEmail(ContractSignatureRoleEnum::Provider === $role ? 'contact@studio-aurora.test' : $customer->getContractualEmail())
            ->setDeclaredPlace(ContractSignatureRoleEnum::Provider === $role ? 'Lyon' : 'Pont-de-Chéruy')
            ->setDeclaredDate(new DateTimeImmutable($signedAt))
            ->setSignedAt(new DateTimeImmutable($signedAt))
            ->setSignedContentHash((string) $contract->getContentHash())
            ->setIpAddress(ContractSignatureRoleEnum::Provider === $role ? '198.51.100.7' : '203.0.113.24')
            ->setUserAgent('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)');

        if (ContractSignatureRoleEnum::Customer === $role) {
            $signature->setChallengeVerifiedAt(new DateTimeImmutable($signedAt));
        }

        $this->entityManager->persist($signature);
    }

    /** @return list<array<string, mixed>> */
    private function monthlyBody(): array
    {
        return [
            $this->header('Objet du contrat'),
            $this->paragraph('Le présent contrat définit les conditions dans lesquelles {{provider.name}}, représentée par {{provider.representative}}, assure pour {{customer.legal_name}} une prestation de suivi mensuel de son site internet.'),
            $this->header('Les parties'),
            $this->paragraph('Le prestataire : {{provider.name}}, {{provider.address}}, SIRET {{provider.siret}}, code APE {{provider.ape_code}}. {{provider.vat_mention}}.'),
            $this->paragraph('Le client : {{customer.legal_name}}, {{customer.legal_form}} au capital de {{customer.share_capital}}, dont le siège est {{customer.registered_office}}, SIRET {{customer.siret}}, représentée par {{customer.representative_full_name}} en qualité de {{customer.representative_role}}.'),
            $this->header('Durée et formule'),
            $this->paragraph('La formule retenue est la formule {{contract.custom.formule}}, pour une durée de {{contract.custom.duree}} à compter du {{contract.effective_date}}.'),
            $this->header('Prix'),
            $this->paragraph('La prestation est facturée {{contract.amount}} par mois, payable à réception de facture par virement sur le compte {{provider.bank_iban}} ouvert au nom de {{provider.bank_holder}} chez {{provider.bank_name}}.'),
            $this->header('Signature'),
            $this->paragraph('Fait à {{contract.signature_city}}, le {{contract.signature_date}}, en un exemplaire électronique valant original.'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function annexFormula(): array
    {
        return [
            $this->header('Annexe - contenu de la formule {{contract.custom.formule}}'),
            $this->paragraph('La présente annexe fait partie intégrante du contrat de référence {{contract.reference}} conclu avec {{customer.legal_name}}.'),
            $this->list([
                'Mises à jour de sécurité du socle et des dépendances, une fois par mois.',
                'Sauvegarde quotidienne, avec vérification de restauration une fois par trimestre.',
                "Deux heures d'évolutions incluses par mois, non reportables.",
                'Réponse aux demandes sous un jour ouvré.',
            ]),
            $this->paragraph("Toute prestation hors annexe fait l'objet d'un devis distinct."),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function oneShotBody(): array
    {
        return [
            $this->header('Objet'),
            $this->paragraph('{{provider.name}} réalise pour {{customer.legal_name}} la prestation ponctuelle décrite ci-dessous, sans engagement de durée.'),
            $this->header('Prix et règlement'),
            $this->paragraph('Le montant est de {{contract.amount}}, dont un acompte de {{contract.custom.acompte}} à la commande.'),
            $this->header('Signature'),
            $this->paragraph('Fait à {{contract.signature_city}}, le {{contract.signature_date}}.'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function amendmentBody(): array
    {
        return [
            $this->header('Avenant n° {{contract.amends_rank}} au contrat {{contract.amends_reference}}'),
            $this->paragraph('Le présent avenant modifie le contrat {{contract.amends_reference}} conclu le {{contract.amends_effective_date}} entre {{provider.name}} et {{customer.legal_name}}. Toutes les clauses du contrat initial non modifiées ci-dessous demeurent applicables.'),
            $this->header("Objet de l'avenant"),
            $this->paragraph('{{contract.custom.avenant_objet}}'),
            $this->header("Prise d'effet et durée"),
            $this->paragraph("Le présent avenant prend effet le {{contract.effective_date}} et s'applique {{contract.custom.avenant_duree}}."),
            $this->header('Prix'),
            $this->paragraph('Le montant de la prestation est porté à {{contract.amount}}.'),
            $this->header('Signature'),
            $this->paragraph('Fait à {{contract.signature_city}}, le {{contract.signature_date}}.'),
        ];
    }

    /** @return array<string, mixed> */
    private function header(string $text): array
    {
        return ['type' => 'header', 'data' => ['text' => $text, 'level' => 2]];
    }

    /** @return array<string, mixed> */
    private function paragraph(string $text): array
    {
        return ['type' => 'paragraph', 'data' => ['text' => $text]];
    }

    /**
     * @param list<string> $items
     *
     * @return array<string, mixed>
     */
    private function list(array $items): array
    {
        return [
            'type' => 'list',
            'data' => [
                'style' => 'unordered',
                'meta' => [],
                'items' => array_map(
                    static fn (string $content): array => ['content' => $content, 'meta' => [], 'items' => []],
                    $items,
                ),
            ],
        ];
    }
}
