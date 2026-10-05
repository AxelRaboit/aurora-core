<?php

declare(strict_types=1);

namespace Aurora\Fixtures\Studio;

use Aurora\Core\Money\Enum\CurrencyEnum;
use Aurora\Fixtures\Core\AppFixtures;
use Aurora\Fixtures\Core\CoreDemoFixtures;
use Aurora\Fixtures\Ged\GedDemoFixtures;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Contract\Access\Entity\ContractAccessLink;
use Aurora\Module\Studio\Contract\Dto\ContractInput;
use Aurora\Module\Studio\Contract\Dto\ContractTemplateInput;
use Aurora\Module\Studio\Contract\Dto\ContractTemplateVersionInput;
use Aurora\Module\Studio\Contract\Entity\ContractInterface;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateInterface;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateVersionInterface;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateVersionTranslationInterface;
use Aurora\Module\Studio\Contract\Enum\ContractStatusEnum;
use Aurora\Module\Studio\Contract\Enum\ContractTemplateKindEnum;
use Aurora\Module\Studio\Contract\Enum\ContractTerminationOriginEnum;
use Aurora\Module\Studio\Contract\Manager\ContractManagerInterface;
use Aurora\Module\Studio\Contract\Manager\ContractTemplateManagerInterface;
use Aurora\Module\Studio\Contract\Repository\ContractRepository;
use Aurora\Module\Studio\Contract\Repository\ContractTemplateRepository;
use Aurora\Module\Studio\Contract\Service\ContractPdfGenerator;
use Aurora\Module\Studio\Contract\Signature\Entity\ContractSignature;
use Aurora\Module\Studio\Contract\Signature\Enum\ContractSignatureRoleEnum;
use Aurora\Module\Studio\Customer\Dto\CustomerInput;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\Customer\Enum\CustomerStatusEnum;
use Aurora\Module\Studio\Customer\Manager\CustomerManagerInterface;
use Aurora\Module\Studio\Customer\Repository\CustomerRepository;
use Aurora\Module\Studio\CustomerSpace\Dto\CustomerSpaceInput;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Enum\CustomerSpaceStatusEnum;
use Aurora\Module\Studio\CustomerSpace\Manager\CustomerSpaceManagerInterface;
use Aurora\Module\Studio\CustomerSpace\Repository\CustomerSpaceRepository;
use Aurora\Module\Studio\CustomerSpace\Security\DriveLock;
use Aurora\Module\Studio\Deck\Entity\DeckCategory;
use Aurora\Module\Studio\Deck\Entity\DeckInterface;
use Aurora\Module\Studio\Deck\Enum\SlideLayoutEnum;
use Aurora\Module\Studio\Deck\Manager\DeckManager;
use Aurora\Module\Studio\Deck\Repository\DeckRepository;
use Aurora\Module\Studio\Deck\Share\Entity\DeckShareLink;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceAccess\Manager\SpaceAccessLinkManagerInterface;
use Aurora\Module\Studio\SpaceAccess\Repository\SpaceAccessLinkRepository;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatMessage;
use Aurora\Module\Studio\SpaceChat\Manager\SpaceChatChannelManagerInterface;
use Aurora\Module\Studio\SpaceContent\Dto\SpaceContentColumnInput;
use Aurora\Module\Studio\SpaceContent\Dto\SpaceContentItemInput;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentComment;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItem;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItemInterface;
use Aurora\Module\Studio\SpaceContent\Enum\SpaceContentApprovalEnum;
use Aurora\Module\Studio\SpaceContent\Manager\SpaceContentAttachmentManagerInterface;
use Aurora\Module\Studio\SpaceContent\Manager\SpaceContentColumnManagerInterface;
use Aurora\Module\Studio\SpaceContent\Manager\SpaceContentItemManagerInterface;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentColumnRepository;
use Aurora\Module\Studio\SpaceFile\Entity\SpaceFile;
use Aurora\Module\Studio\SpaceNote\Entity\SpaceNote;
use Aurora\Module\Studio\SpaceNote\Enum\SpaceNoteVisibilityEnum;
use Aurora\Module\Studio\SpaceResource\Dto\SpaceResourceInput;
use Aurora\Module\Studio\SpaceResource\Enum\SpaceResourceKindEnum;
use Aurora\Module\Studio\SpaceResource\Manager\SpaceResourceManagerInterface;
use Aurora\Module\Studio\SpaceResource\Repository\SpaceResourceRepository;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use RuntimeException;

use function mb_substr;
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
        private readonly SpaceContentAttachmentManagerInterface $contentAttachments,
        private readonly DocumentRepository $documents,
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
        private readonly SpaceResourceRepository $spaceResources,
        private readonly SpaceResourceManagerInterface $spaceResourceManager,
        private readonly SpaceAccessLinkManagerInterface $accessLinks,
        private readonly SpaceAccessLinkRepository $accessLinkRepository,
        private readonly SpaceChatChannelManagerInterface $chatChannels,
        private readonly DriveLock $driveLock,
        private readonly AuditLogger $audit,
        private readonly ContractPdfGenerator $pdf,
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
            landline: '04 74 12 34 56',
            phone: '06 12 34 56 78',
            links: [
                ['label' => 'Site web', 'url' => 'https://atelier-dupont.example.com'],
                ['label' => 'Instagram', 'url' => 'https://instagram.example.com/atelierdupont'],
            ],
            notes: 'Validation le mardi matin. Marie relit tout avant publication, Léa prépare les visuels.',
            legalForm: 'SARL',
            siret: '11281704400004',
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
            landline: '04 78 55 22 10',
            phone: '06 98 76 54 32',
            links: [
                ['label' => 'Site web', 'url' => 'https://martin-documents.example.com'],
            ],
            notes: null,
            legalForm: 'SAS',
            siret: '73245630600008',
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
            landline: null,
            phone: '06 44 21 87 09',
            links: [],
            notes: null,
            legalForm: 'Entreprise individuelle',
            siret: '98700210200018',
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
        $this->seedTrashedDeck();
        $this->seedSpaces($marie, $jean, $sophie);
        $this->seedApprovals();

        // Nothing below is built if the instance already has contracts. The
        // seal mints a reference from a yearly sequence, so a second run would
        // not collide - it would just quietly double a list that is meant to be
        // read, which is worse.
        if (0 !== $this->contractRepository->count([])) {
            $this->entityManager->flush();

            return;
        }

        // 1. A draft, still editable, no reference yet, and its wording
        //    adapted for this client: one clause added to the trame's text,
        //    without a trame of its own. The list shows « Adapté », and the
        //    contract's screen leads to the text and what differs.
        $draft = $this->contract($marie, $monthly, $annex, 490_00, '+1 month', [
            'formule' => 'Suivi',
            'duree' => '12 mois',
        ]);
        $this->adaptForClient($draft);

        // 2. Sealed and sent, waiting for an answer. The state most of the list
        //    is in on any given day.
        $waiting = $this->contract($jean, $monthly, $annex, 690_00, '+2 weeks', [
            'formule' => 'Suivi',
            'duree' => '24 mois',
        ]);
        $this->seal($waiting, ContractStatusEnum::Sent, '-4 days');
        $this->link($waiting, sentAt: '-4 days');
        $waiting->markReminded(new DateTimeImmutable('-1 day'));
        $this->chronicle($waiting, ['contract.created' => '-5 days', 'contract.frozen' => '-4 days', 'contract.link_sent' => '-4 days', 'contract.reminder_sent' => '-1 day']);

        // 3. Signed by the customer and countersigned: a concluded contract,
        //    with both signatures on the same hash.
        $concluded = $this->contract($sophie, $monthly, $annex, 390_00, '+1 week', [
            'formule' => 'Essentiel',
            'duree' => '12 mois',
        ]);
        $this->seal($concluded, ContractStatusEnum::Countersigned, '-5 days');
        $this->link($concluded, sentAt: '-5 days', openedAt: '-2 days', revokedAt: '-1 day');
        $this->conclude($concluded, [
            $this->sign($concluded, ContractSignatureRoleEnum::Customer, $sophie, '-2 days'),
            $this->sign($concluded, ContractSignatureRoleEnum::Provider, $sophie, '-1 day'),
        ], '-1 day');
        $this->chronicle($concluded, ['contract.created' => '-6 days', 'contract.frozen' => '-5 days', 'contract.link_sent' => '-5 days', 'contract.signed_by_customer' => '-2 days', 'contract.countersigned' => '-1 day']);

        // 4. An amendment of the concluded one, which is the whole point of the
        //    design: the parent is untouched and this document says what it
        //    changes.
        $amendment = $this->amendment($concluded, $amendmentTrame, 490_00, '+1 month', [
            'avenant_objet' => 'Passage de la formule Essentiel à la formule Suivi',
            'avenant_duree' => "jusqu'au terme du contrat initial",
        ]);

        $this->seal($amendment, ContractStatusEnum::Countersigned, '-2 days');
        $this->link($amendment, sentAt: '-2 days', openedAt: '-1 day', revokedAt: 'now');
        $this->conclude($amendment, [
            $this->sign($amendment, ContractSignatureRoleEnum::Customer, $sophie, '-1 day'),
            $this->sign($amendment, ContractSignatureRoleEnum::Provider, $sophie, 'now'),
        ], 'now');
        $this->chronicle($amendment, ['contract.created' => '-3 days', 'contract.frozen' => '-2 days', 'contract.link_sent' => '-2 days', 'contract.signed_by_customer' => '-1 day', 'contract.countersigned' => 'now']);

        // 5. A refusal, because a list that only shows agreements teaches the
        //    wrong thing about what the module handles.
        $refused = $this->contract($marie, $monthly, $annex, 890_00, '+3 weeks', [
            'formule' => 'Suivi',
            'duree' => '36 mois',
        ]);
        $this->seal($refused, ContractStatusEnum::Sent, '-6 days');
        // A refusal revokes the address it came through, as the real path does.
        $this->link($refused, sentAt: '-6 days', openedAt: '-1 day', revokedAt: '-1 day');
        $refused->refuse(
            new DateTimeImmutable('-1 day'),
            'Budget reporte au prochain exercice.',
            '203.0.113.24',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
        );
        $this->chronicle($refused, ['contract.created' => '-7 days', 'contract.frozen' => '-6 days', 'contract.link_sent' => '-6 days', 'contract.refused' => '-1 day']);

        // 6. A terminated relationship: concluded, then ended with notice.
        $ended = $this->contract($jean, $monthly, $annex, 290_00, '-6 months', [
            'formule' => 'Essentiel',
            'duree' => '12 mois',
        ]);
        // Signed six months ago, before it took effect: a relationship that
        // ends began well before, and its dates have to say so. Within the
        // year, so its reference carries the year it was sealed in.
        $this->seal($ended, ContractStatusEnum::Countersigned, '-6 months -12 days');
        $this->link($ended, sentAt: '-6 months -12 days', openedAt: '-6 months -9 days', revokedAt: '-6 months -8 days');
        $this->conclude($ended, [
            $this->sign($ended, ContractSignatureRoleEnum::Customer, $jean, '-6 months -9 days'),
            $this->sign($ended, ContractSignatureRoleEnum::Provider, $jean, '-6 months -8 days'),
        ], '-6 months -8 days');
        $ended->terminate(
            new DateTimeImmutable('now'),
            new DateTimeImmutable('+2 months'),
            ContractTerminationOriginEnum::Customer,
            'Fin de la mission, arrêt au terme du préavis de deux mois.',
        );
        $this->chronicle($ended, ['contract.created' => '-6 months -13 days', 'contract.frozen' => '-6 months -12 days', 'contract.link_sent' => '-6 months -12 days', 'contract.signed_by_customer' => '-6 months -9 days', 'contract.countersigned' => '-6 months -8 days', 'contract.terminated' => 'now']);

        // 7 a 11. Les cinq etats qui manquaient, un par ligne.
        //
        // **Neuf etats existent, la demo en montrait quatre.** Les cinq autres
        // ne se voyaient donc nulle part : ni a l'ecran quand on apprend le
        // module, ni sur une capture, ni dans un parcours automatise - c'est
        // l'absence d'un contrat « envoye mais pas encore ouvert » qui faisait
        // echouer un flux entier, faute de ligne a cliquer.
        //
        // Un enum de statut est une promesse faite au lecteur : chacun de ses
        // cas doit etre representable, sans quoi personne ne sait de quoi il a
        // l'air.

        // Scelle mais pas encore envoye : le document est fige, la reference
        // est frappee, et rien n'est parti. L'etat d'un contrat relu avant
        // d'appuyer.
        $sealed = $this->contract($marie, $oneShot, null, 750_00, '+1 month', [
            'acompte' => '30 %',
        ]);
        $this->seal($sealed, ContractStatusEnum::Sealed);

        // Ouvert : le client a cliqué le lien, il n'a pas répondu. C'est
        // l'information qui change une relance en conversation.
        $opened = $this->contract($sophie, $monthly, $annex, 540_00, '+3 weeks', [
            'formule' => 'Suivi',
            'duree' => '12 mois',
        ]);
        $this->seal($opened, ContractStatusEnum::Opened, '-3 days');
        $this->link($opened, sentAt: '-3 days', openedAt: '-1 day');
        $this->chronicle($opened, ['contract.created' => '-4 days', 'contract.frozen' => '-3 days', 'contract.link_sent' => '-3 days']);

        // Signé par le client, en attente de contresignature : la balle est
        // dans votre camp, et c'est le seul etat qui le dit.
        $waitingCountersign = $this->contract($jean, $monthly, $annex, 620_00, '+2 weeks', [
            'formule' => 'Suivi',
            'duree' => '18 mois',
        ]);
        $this->seal($waitingCountersign, ContractStatusEnum::SignedByCustomer, '-7 days');
        $this->link($waitingCountersign, sentAt: '-7 days', openedAt: '-3 days', revokedAt: '-3 days');
        $this->sign($waitingCountersign, ContractSignatureRoleEnum::Customer, $jean, '-3 days');
        $this->chronicle($waitingCountersign, ['contract.created' => '-8 days', 'contract.frozen' => '-7 days', 'contract.link_sent' => '-7 days', 'contract.signed_by_customer' => '-3 days']);

        // Expire : personne n'a signe a temps. Une date d'effet passee, pour
        // que la ligne se lise sans avoir a la deduire.
        $expired = $this->contract($sophie, $oneShot, null, 320_00, '-3 weeks', [
            'acompte' => '50 %',
        ]);
        // Sent more than thirty days ago: the address lapsed unanswered.
        $this->seal($expired, ContractStatusEnum::Expired, '-40 days');
        $this->link($expired, sentAt: '-40 days');
        $this->chronicle($expired, ['contract.created' => '-41 days', 'contract.frozen' => '-40 days', 'contract.link_sent' => '-40 days', 'contract.expired' => '-10 days']);

        // Revoque : retire avant signature, de votre fait. A ne pas confondre
        // avec un refus, qui vient du client.
        $revoked = $this->contract($marie, $monthly, $annex, 410_00, '+1 month', [
            'formule' => 'Essentiel',
            'duree' => '12 mois',
        ]);
        $this->seal($revoked, ContractStatusEnum::Revoked, '-3 days');
        $this->link($revoked, sentAt: '-3 days', revokedAt: '-1 day');
        $this->chronicle($revoked, ['contract.created' => '-4 days', 'contract.frozen' => '-3 days', 'contract.link_sent' => '-3 days', 'contract.link_revoked' => '-1 day']);

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

        $admin = $this->suiteUser('dev@aurora.app');
        $marieAccount = $this->suiteUser('marie.dupont@aurora.app');
        $jeanAccount = $this->suiteUser('jean.martin@aurora.app');

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
        $this->seedCardComments($social, $this->seedChat($social));
        $this->seedAccessLinks($social);
        $this->seedChannels($social);
        $this->seedNotes($social);
        // Un exemplaire de chaque genre, et les deux visibilités : un état
        // qu'on ne voit jamais est un état dont personne ne sait de quoi il a
        // l'air, et c'est précisément le cas de « le client ne voit pas
        // celui-ci », qui n'a rien de visible par construction.
        $this->seedResources($social, [
            ['link', 'Maquette Canva', 'https://canva.example.com/atelier-dupont-reseaux', null, true],
            ['contact', 'Léa, communication', null, 'Prépare les visuels. Disponible le lundi et le jeudi.', true, 'lea@atelier-dupont.example.com', '06 55 44 33 22'],
            ['text', 'Ton et vocabulaire', null, 'Tutoiement, phrases courtes. On dit « atelier », jamais « entreprise ». Les prix ne sont jamais annoncés en publication.', true],
            ['link', "Tableau de bord de l'hébergeur", 'https://panel.example.com/atelier-dupont', "Accès par le gestionnaire de mots de passe de l'agence.", false],
            ['text', 'Facturation', null, 'Mensuelle, le 5. Relance automatique à J+15, relance manuelle à J+30.', false],
        ]);
        // Des fichiers qui n'illustrent rien : ce qu'on tend au client sans
        // l'épingler à une publication.
        $this->fileOnSpace($social, [
            'Logo Aurora - Fond sombre',
            'Plan des locaux - Étage 2',
            'Charte Graphique Aurora - Brand Guidelines',
        ]);

        // Le seul espace dont l'onglet Drive est ferme, et le seul qui designe
        // un dossier partage. Un reglage qu'aucun espace ne porte est un
        // reglage que personne ne voit : l'ecran des reglages sortait toujours
        // vide, et l'onglet Drive toujours ouvert.
        //
        // Le mot de passe est `demonstration` - un litteral dans un depot
        // public, comme le mot de passe du compte de developpement, et pour la
        // meme raison : il n'ouvre rien d'autre qu'une demonstration locale.
        $locked = $this->space(
            name: 'Atelier Dupont - Refonte du site',
            description: 'Reprise des textes et des photos de chantier, livraison au printemps.',
            customer: $marie,
            members: [$admin => 'lead'],
        );

        if (!$locked->isDriveLocked()) {
            // **Un identifiant manifestement faux**, et c'est le sujet : celui
            // d'un vrai dossier Drive a séjourné ici, et ce dépôt est public.
            // Un identifiant de dossier n'ouvre rien à lui seul, mais il nomme
            // une infrastructure réelle, ce qui n'a pas sa place dans une
            // démonstration. Google le refusera, l'écran dira que le dossier
            // ne répond pas, et c'est un état que la démo doit savoir montrer.
            $locked->setDriveFolderId('dossier-de-demonstration-aurora');
            $this->driveLock->set($locked, 'demonstration');
        }

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

        // Un espace ouvert pour un prospect, et c'est la seule chose qui le
        // distingue : même tableau, mêmes fiches, mêmes notes. On travaille
        // avec quelqu'un avant qu'il signe, et l'écran doit le montrer - une
        // démonstration où les prospects sont des coquilles vides enseignerait
        // le contraire.
        $fabre = $this->prospect('Menuiserie Fabre');

        $launch = $this->space(
            name: 'Menuiserie Fabre - Identité visuelle',
            description: 'Refonte du logo et de la charte. En discussion, rien de signé.',
            customer: $fabre,
            members: [$marieAccount => 'lead'],
        );

        $this->seedProspectBoard($launch);
        $this->seedNotes($launch);
        $this->seedResources($launch, [
            ['link', 'Moodboard', 'https://canva.example.com/menuiserie-fabre-moodboard', null, true],
            ['contact', 'Thomas Fabre', null, 'Gérant. Passe par son fils pour tout ce qui touche au site.', false, 'contact@menuiserie-fabre.example.com', '04 74 98 76 54'],
        ]);
        $this->seedChat(
            $launch,
            'contact@menuiserie-fabre.fr',
            'Thomas Fabre',
            [
                ['-3 days 11:05', false, "Bonjour, voici l'espace pour suivre le chantier. Vous y verrez les pistes au fur et à mesure."],
                ['-3 days 16:22', true, "Merci. Je regarde ça ce week-end avec mon fils, c'est lui qui gère le site."],
                ['-1 day 09:40', false, 'Trois directions de logo sont posées sur le tableau. Rien de définitif, dites-moi ce qui vous parle.'],
            ],
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
        // Fourth column: the pictures hung on the card, by document title.
        //
        // Most cards carry one, a couple carry several and one carries none,
        // because those are the three things the board has to be able to draw.
        // A demo where every card had a thumbnail would hide what a card with
        // nothing to show looks like, which is the commonest state of all while
        // a month is being planned.
        $cards = [
            0 => [
                ['Portrait de l\'équipe', "Photo de groupe devant l'atelier, format carré.", null, ["Photo d'équipe - Séminaire 2025"]],
                ['Les essences de bois', 'Un fil sur le chêne, le noyer et le frêne.', null, []],
                ['Avant / après cuisine', 'La rénovation de septembre, en deux images.', null, ['Bureau - Illustration article', 'Capture - Tableau de bord client']],
            ],
            1 => [
                ['Coulisses du chantier Morel', "Trois photos de l'escalier en cours.", '+3 days 09:00', ['Visuel de campagne - Automne 2025', 'Plan des locaux - Étage 2', 'Logo Aurora - Fond sombre']],
                // La seule carte datée qui ne paraît pas dans le mois : une
                // échéance de studio, pas une publication. Sans elle, la
                // démonstration n'aurait aucun exemple de la case décochée, et
                // le cas qu'elle existe pour porter resterait invisible.
                ['Relancer le photographe', 'Confirmer la date de la séance avant de programmer le reste.', '+2 days 10:00', [], false],
            ],
            2 => [
                ['Offre de rentrée', 'Le devis gratuit jusqu\'au 30. À faire valider avant mardi.', '+5 days 18:00', ['Affiche du salon 2026']],
            ],
            3 => [
                ['Journée portes ouvertes', "Rappel de l'événement du 12, avec le plan d'accès.", '+8 days 10:00', ['Plan des locaux - Étage 2']],
                ['Témoignage client', 'Le retour de Mme Lefèvre sur sa bibliothèque.', '+10 days 09:00', []],
            ],
            4 => [
                ['Le nouvel atelier', "L'annonce du déménagement, parue la semaine dernière.", '-4 days 09:00', ['Visuel de campagne - Automne 2025']],
            ],
            5 => [
                // A card whose only file is a PDF: no square on the board, an
                // icon in the form. The one case the tile logic has to get
                // right and that no image would exercise.
                ['Conditions du jeu concours', 'Le règlement relu par le cabinet avant publication.', '+14 days 09:00', ['Certification ISO 27001 - Audit 2024']],
            ],
        ];

        foreach ($cards as $at => $rows) {
            if (!isset($columns[$at])) {
                continue;
            }

            foreach ($rows as $row) {
                [$title, $body, $when, $pictures] = $row;

                $item = $this->contentItems->create($space, new SpaceContentItemInput(
                    title: $title,
                    body: $body,
                    columnId: $columns[$at]->getId(),
                    scheduledAt: null === $when
                        ? null
                        : new DateTimeImmutable($when)->format('Y-m-d\TH:i'),
                    // Le cinquième élément, absent partout sauf sur la carte
                    // interne : une liste de cartes reste lisible quand le cas
                    // courant ne l'écrit pas.
                    showOnCalendar: $row[4] ?? true,
                ));

                $this->hangPictures($item, $pictures);
            }
        }
    }

    /**
     * A conversation on the space, spread over two days.
     *
     * **Two days on purpose**: the panel draws a line whenever the day changes,
     * and a demo written inside one afternoon would photograph a feature that
     * never appears. The constructor stamps every row with now, so the dates
     * are moved afterwards in one statement - the entity has no setter for it
     * and should not grow one, because a conversation is a sequence of events
     * and events do not get re-dated.
     *
     * Paramétrée parce que deux espaces en ont une : celui d'un client et celui
     * d'un prospect, et faire parler le second avec les mots du premier serait
     * une démonstration qui se contredit.
     *
     * **The client's side is signed by a real access link**, issued here like
     * the studio would: a message signed any other way would be data no code
     * ever produces, which is how a demo stops resembling the product. The link
     * is also what the access screen needs to have something to show.
     */
    /**
     * Les états qu'un lien d'accès peut prendre, un par ligne.
     *
     * **Deux liens identiques n'apprennent rien.** L'écran d'accès client
     * montrait deux destinataires aux mêmes droits, valides tous les deux :
     * ni ce qu'un lien révoqué devient, ni qu'un lecteur peut n'avoir que le
     * droit de lire, ni qu'un droit de dépôt existe, ni qu'on peut ouvrir un
     * espace sans ouvrir son Drive.
     *
     * Un droit qu'on ne voit jamais coché ni décoché est un droit dont
     * personne ne sait qu'il existe.
     */
    private function seedAccessLinks(CustomerSpaceInterface $space): void
    {
        // Un lecteur, et rien de plus : l'associé du client, le supérieur, une
        // agence partenaire. Il regarde, il ne décide pas.
        $this->accessLink($space, 'lea@atelier-dupont.fr', 'Léa, communication', 90, false, false);

        // Le droit de déposer un fichier, qui est décoché par défaut : le
        // client envoie ses visuels au lieu de les mettre en pièce jointe d'un
        // courriel.
        $this->accessLink($space, 'studio@lumiere-photo.test', 'Studio Lumière, photographe', 60, false, true, canUpload: true);

        // Un lien qui n'a pas le droit de voir le dossier Drive. Le partage
        // contient parfois des pièces qui ne regardent pas tout le monde.
        $this->accessLink($space, 'audit@cabinet-verrier.test', 'Cabinet Verrier, audit', 30, false, true, canSeeDrive: false);

        // Révoqué : retiré à la main. La ligne reste, barrée, parce qu'effacer
        // un accès effacerait aussi qui avait répondu quoi.
        $this->accessLink($space, 'ancien.stagiaire@atelier-dupont.fr', 'Thomas, stage terminé', 90, false, true)
            ?->revoke(new DateTimeImmutable('-6 days'));

        // Expiré de lui-même. Un accès qui survit à la mission est un accès que
        // personne ne pense à fermer, d'où la date de fin obligatoire.
        $this->accessLink($space, 'salon@expo-artisans.test', "Salon des artisans, édition d'avril", 90, false, true)
            ?->setExpiresAt(new DateTimeImmutable('-3 days'));

        $this->entityManager->flush();
    }

    /** Un lien, une seule fois, quel que soit le nombre de rechargements. */
    private function accessLink(
        CustomerSpaceInterface $space,
        string $recipient,
        string $label,
        int $validForDays,
        bool $canApprove,
        bool $canComment,
        bool $canUpload = false,
        bool $canSeeDrive = true,
    ): ?SpaceAccessLinkInterface {
        if ($this->existingLinkFor($space, $recipient) instanceof SpaceAccessLinkInterface) {
            return null;
        }

        // Nommés plutôt que positionnels : la signature porte six droits, et
        // en insérer un au milieu décalerait silencieusement tous les autres.
        return $this->accessLinks->issue(
            $space,
            $recipient,
            $label,
            $validForDays,
            canApprove: $canApprove,
            canComment: $canComment,
            canChat: $canComment,
            canUpload: $canUpload,
            canSeeDrive: $canSeeDrive,
        );
    }

    private function seedChat(
        CustomerSpaceInterface $space,
        string $recipient = 'camille@atelier-dupont.fr',
        string $label = 'Camille, gérante',
        ?array $exchange = null,
    ): ?SpaceAccessLinkInterface {
        $marie = $this->userRepository->find($this->suiteUser('marie.dupont@aurora.app'));

        if (!$marie instanceof User) {
            return null;
        }

        // **Un lien par personne, quel que soit le nombre de passages.**
        // `issue()` en crée un à chaque appel, donc trois `make demo` donnaient
        // trois fois Camille dans l'écran d'accès client : une liste qui dit
        // qu'on a envoyé trois adresses à la même personne, ce qui n'est pas
        // ce que la démonstration décrit et ce qu'un lecteur prend pour un
        // défaut du produit.
        $link = $this->existingLinkFor($space, $recipient) ?? $this->accessLinks->issue(
            $space,
            $recipient,
            $label,
            90,
            canApprove: true,
            canComment: true,
        );

        // Studio, client, studio, client: a thread that only ever shows one
        // side does not show that the two are one stream.
        $exchange ??= [
            ['-2 days 09:12', false, "Bonjour Camille. Le brief d'octobre est prêt, je vous le partage dans la journée."],
            ['-2 days 14:40', true, "Parfait. On peut décaler la campagne portes ouvertes d'une semaine ? Le chantier a pris du retard."],
            ['-1 day 08:55', false, 'Aucun souci, je repousse les deux publications concernées et je vous remets le calendrier à jour.'],
            ['-1 day 09:30', true, "Merci. Je vous envoie le nouveau logo en fin de semaine, l'agence nous le livre jeudi."],
        ];

        $dates = [];

        // Le canal principal de l'espace : un message appartient à un canal
        // depuis que la discussion en a plusieurs, et l'espace est né avec
        // celui-là.
        $channel = $this->chatChannels->ensureMain($space);

        foreach ($exchange as [$when, $fromClient, $body]) {
            $message = new SpaceChatMessage();
            $message->setSpace($space)->setChannel($channel)->setBody($body);

            if ($fromClient) {
                $message->writtenByClient($link);
            } else {
                $message->writtenByStudio($marie, $marie->getName());
            }

            $this->entityManager->persist($message);
            $this->entityManager->flush();

            $dates[(int) $message->getId()] = new DateTimeImmutable($when);
        }

        foreach ($dates as $id => $at) {
            $this->entityManager->createQuery(
                'UPDATE '.SpaceChatMessage::class.' m SET m.createdAt = :at WHERE m.id = :id'
            )->setParameter('at', $at)->setParameter('id', $id)->execute();
        }

        return $link;
    }

    /**
     * Le lien déjà émis pour cette adresse, s'il y en a un.
     *
     * Cherché dans les liens de l'espace plutôt que par une requête à part :
     * la démonstration en pose deux ou trois par espace, et le dépôt sait déjà
     * les rendre.
     */
    private function existingLinkFor(CustomerSpaceInterface $space, string $recipient): ?SpaceAccessLinkInterface
    {
        foreach ($this->accessLinkRepository->findForSpace($space) as $link) {
            if ($link->getRecipientEmail() === $recipient) {
                return $link;
            }
        }

        return null;
    }

    /**
     * Deux canaux de plus sur l'espace le mieux rempli.
     *
     * Un interne et un ouvert, parce que c'est la distinction que la
     * fonctionnalité existe pour porter : une démonstration qui n'aurait que
     * des canaux ouverts ne montrerait pas où l'agence parle entre elle, et une
     * qui n'aurait que des canaux fermés ferait croire que le client n'en voit
     * jamais.
     *
     * Le canal principal n'est pas créé ici : un espace naît avec.
     */
    private function seedChannels(CustomerSpaceInterface $space): void
    {
        // Les deux comptes, et le compte de démonstration d'abord : un canal
        // n'est vu que par ceux qui y sont, donc un canal créé sans personne
        // dedans est un canal que le studio ne retrouve jamais - y compris
        // celui qui vient de le créer.
        $accounts = array_filter([
            $this->userRepository->find($this->suiteUser('dev@aurora.app')),
            $this->userRepository->find($this->suiteUser('marie.dupont@aurora.app')),
        ], static fn (?User $user): bool => $user instanceof User);

        $internal = $this->chatChannels->create($space, 'Entre nous');
        $upcoming = $this->chatChannels->create($space, 'Le mois prochain', openToClient: true);

        foreach ($accounts as $account) {
            $this->chatChannels->invite($internal, $account);
            $this->chatChannels->invite($upcoming, $account);
        }

        // Et une conversation privée entre les deux comptes : c'est la seconde
        // moitié de la discussion, et une démonstration où la section est vide
        // laisse croire qu'elle ne sert à rien.
        if (2 === count($accounts)) {
            [$first, $second] = array_values($accounts);
            $this->chatChannels->openDirect($space, $first, null, $second, null);
        }
    }

    /**
     * Deux fiches commentées, studio et client mêlés.
     *
     * La table était vide, donc le fil d'une fiche s'ouvrait toujours sur
     * « aucun commentaire » : la fonctionnalité existait et ne se voyait
     * nulle part. Deux fiches sur huit, pas huit sur huit - une fiche sans
     * discussion est l'état le plus courant du tableau et il faut qu'il se
     * voie aussi.
     *
     * Le client signe avec le lien d'accès de la conversation, pas avec un
     * autre : deux liens pour un même interlocuteur donneraient deux
     * personnes à l'écran.
     */
    private function seedCardComments(CustomerSpaceInterface $space, ?SpaceAccessLinkInterface $link): void
    {
        $marie = $this->userRepository->find($this->suiteUser('marie.dupont@aurora.app'));

        if (!$marie instanceof User || !$link instanceof SpaceAccessLinkInterface) {
            return;
        }

        $items = $this->entityManager->getRepository(SpaceContentItem::class)
            ->findBy(['space' => $space], ['id' => 'ASC']);

        $threads = [
            0 => [
                ['-2 days 10:15', false, "J'ai posé la photo de groupe, dites-moi si le cadrage vous va."],
                ['-2 days 15:02', true, 'Le cadrage est bon. On peut juste éclaircir un peu le fond ?'],
                ['-1 day 09:20', false, 'Repris, la nouvelle version est sur la fiche.'],
            ],
            2 => [
                ['-1 day 17:45', true, 'Les deux images avant / après sont parfaites, on garde cet ordre.'],
            ],
        ];

        $dates = [];

        foreach ($threads as $at => $thread) {
            $item = $items[$at] ?? null;
            if (!$item instanceof SpaceContentItemInterface) {
                continue;
            }

            foreach ($thread as [$when, $fromClient, $body]) {
                $comment = new SpaceContentComment();
                $comment->setItem($item)->setBody($body);

                if ($fromClient) {
                    $comment->writtenByClient($link);
                } else {
                    $comment->writtenByStudio($marie, $marie->getName());
                }

                $this->entityManager->persist($comment);
                $this->entityManager->flush();

                $dates[(int) $comment->getId()] = new DateTimeImmutable($when);
            }
        }

        // Comme la conversation : l'entité horodate à la création et n'a pas
        // de setter pour ça, ce qui est juste - un commentaire ne se
        // rédate pas.
        foreach ($dates as $id => $at) {
            $this->entityManager->createQuery(
                'UPDATE '.SpaceContentComment::class.' c SET c.createdAt = :at WHERE c.id = :id'
            )->setParameter('at', $at)->setParameter('id', $id)->execute();
        }
    }

    /**
     * Deux avis du client sur le tableau : un contenu validé, un à reprendre.
     *
     * Sans eux, la pastille de l'avis ne s'affichait sur aucune carte et la
     * fiche n'avait jamais de réponse à montrer, alors que c'est le geste que
     * l'espace existe pour recueillir. Hors du garde des espaces, et rejoué
     * sans doublon : un avis déjà donné est laissé tel quel, et le fil de la
     * fiche à reprendre ne s'écrit qu'une fois.
     */
    private function seedApprovals(): void
    {
        $space = null;
        foreach ($this->spaceRepository->findAll() as $one) {
            if ('Atelier Dupont - Réseaux sociaux' === $one->getName()) {
                $space = $one;
            }
        }

        $marie = $this->userRepository->find($this->suiteUser('marie.dupont@aurora.app'));
        $link = $space instanceof CustomerSpaceInterface ? $this->existingLinkFor($space, 'camille@atelier-dupont.fr') : null;

        if (!$link instanceof SpaceAccessLinkInterface || !$marie instanceof User) {
            return;
        }

        $byTitle = [];
        foreach ($this->entityManager->getRepository(SpaceContentItem::class)->findBy(['space' => $space]) as $item) {
            $byTitle[$item->getTitle()] = $item;
        }

        $answers = [
            'Offre de rentrée' => [SpaceContentApprovalEnum::ChangesRequested, '-6 hours', [
                ['-7 hours', true, "Le montant ne se voit pas assez : on peut l'écrire en gros sur le visuel ?"],
                ['-5 hours', false, 'Noté, je reprends le visuel ce soir et je vous renvoie la fiche.'],
            ]],
            'Journée portes ouvertes' => [SpaceContentApprovalEnum::Approved, '-1 day 11:30', [
                ['-1 day 11:28', true, "Parfait, le plan d'accès est clair. On valide."],
            ]],
        ];

        $dates = [];
        foreach ($answers as $title => [$verdict, $when, $thread]) {
            $item = $byTitle[$title] ?? null;
            if (!$item instanceof SpaceContentItemInterface) {
                continue;
            }

            if (SpaceContentApprovalEnum::Pending !== $item->getApproval()) {
                continue;
            }

            $item->answer($verdict, $link, new DateTimeImmutable($when));

            foreach ($thread as [$at, $fromClient, $body]) {
                $comment = new SpaceContentComment();
                $comment->setItem($item)->setBody($body);
                if ($fromClient) {
                    $comment->writtenByClient($link);
                } else {
                    $comment->writtenByStudio($marie, $marie->getName());
                }

                $this->entityManager->persist($comment);
                $this->entityManager->flush();
                $dates[(int) $comment->getId()] = new DateTimeImmutable($at);
            }
        }

        $this->entityManager->flush();

        foreach ($dates as $id => $at) {
            $this->entityManager->createQuery(
                'UPDATE '.SpaceContentComment::class.' c SET c.createdAt = :at WHERE c.id = :id'
            )->setParameter('at', $at)->setParameter('id', $id)->execute();
        }
    }

    /**
     * Ce qu'un espace épingle : un lien, un texte, un contact.
     *
     * Les adresses sont en `example.com` et les personnes sont inventées,
     * comme partout ailleurs dans la démonstration : rien ici ne doit désigner
     * une infrastructure ou quelqu'un de réel.
     *
     * **Les coordonnées sont écrites, pas dérivées du libellé.** Les déduire
     * donnait `léa.communication@…` : une adresse que le validateur refuse, et
     * que la démonstration affichait pourtant comme si on pouvait l'écrire.
     *
     * @param list<array{0: string, 1: string, 2: string|null, 3: string|null, 4: bool, 5?: string, 6?: string}> $rows
     */
    private function seedResources(CustomerSpaceInterface $space, array $rows): void
    {
        if ([] !== $this->spaceResources->findForSpace($space)) {
            return;
        }

        foreach ($rows as $row) {
            [$kind, $label, $url, $body, $visible] = $row;

            $this->spaceResourceManager->create($space, new SpaceResourceInput(
                kind: SpaceResourceKindEnum::from($kind),
                label: $label,
                url: $url,
                body: $body,
                email: $row[5] ?? null,
                phone: $row[6] ?? null,
                visibleToClient: $visible,
            ));
        }
    }

    /**
     * Une fiche ouverte pour quelqu'un dont on n'a rien d'autre que le nom.
     *
     * Sans adresse ni SIRET, parce que c'est l'état qu'un prospect décrit : on
     * rencontre quelqu'un, on ouvre un espace pour structurer le travail, et
     * tout le reste attend. Une démonstration où les prospects arrivent avec
     * leur identité légale complète montrerait un client déguisé.
     */
    private function prospect(string $legalName): CustomerInterface
    {
        return $this->customers->create(new CustomerInput(
            legalName: $legalName,
            status: CustomerStatusEnum::Prospect,
        ));
    }

    /**
     * Le tableau d'un prospect : moins avancé, et c'est tout.
     *
     * Trois fiches au lieu de huit, rien de publié, aucun avis rendu. Un
     * chantier qui n'a pas encore commencé ressemble à ça, et le mettre à côté
     * du tableau plein de l'autre espace est ce qui montre les deux états.
     */
    private function seedProspectBoard(CustomerSpaceInterface $space): void
    {
        $columns = $this->contentColumns->findForSpace($space);

        if ([] === $columns) {
            return;
        }

        $cards = [
            0 => [
                ['Pistes de logo', "Trois directions : menuisier d'art, atelier familial, bois brut.", null, ['Logo Aurora - Fond sombre', 'Visuel de campagne - Automne 2025']],
                ['Palette et typographie', 'À caler une fois la direction choisie.', null, []],
            ],
            1 => [
                ["Photos de l'atelier", 'Prévoir une demi-journée sur place, lumière du matin.', '+6 days 10:00', ["Photo d'équipe - Séminaire 2025"]],
            ],
        ];

        foreach ($cards as $at => $rows) {
            if (!isset($columns[$at])) {
                continue;
            }

            foreach ($rows as [$title, $body, $when, $pictures]) {
                $item = $this->contentItems->create($space, new SpaceContentItemInput(
                    title: $title,
                    body: $body,
                    columnId: $columns[$at]->getId(),
                    scheduledAt: null === $when
                        ? null
                        : new DateTimeImmutable($when)->format('Y-m-d\TH:i'),
                ));

                $this->hangPictures($item, $pictures);
            }
        }
    }

    /**
     * Des notes sur un espace : la seule surface que le client ne voit pas.
     *
     * Écrites comme on les écrit - le brief pris au téléphone, ce qu'il reste à
     * décider, ce qui a coincé - parce qu'une démonstration où les notes sont
     * des paragraphes de remplissage n'apprend pas à quoi elles servent.
     *
     * Persistées directement, comme la discussion : le Manager signe avec le
     * compte connecté, et une fixture n'en a pas.
     */
    private function seedNotes(CustomerSpaceInterface $space): void
    {
        $marie = $this->userRepository->find($this->suiteUser('marie.dupont@aurora.app'));
        // Les notes personnelles sont prises par le compte de développement,
        // et c'est le seul choix qui montre quelque chose : une note
        // personnelle ne remonte qu'à son auteur, donc signée par quelqu'un
        // d'autre elle laisserait l'onglet vide pour celui qui regarde.
        $admin = $this->userRepository->find($this->suiteUser('dev@aurora.app'));

        if (!$marie instanceof User || !$admin instanceof User) {
            return;
        }

        foreach ($this->noteContents() as [$title, $colour, $pinned, $visibility, $paragraphs]) {
            $author = $visibility->isPersonal() ? $admin : $marie;

            $note = new SpaceNote();
            $note
                ->setSpace($space)
                ->setTitle($title)
                ->setColourSlot($colour)
                ->setPinned($pinned)
                ->setVisibility($visibility)
                ->setBody(array_map(
                    static fn (string $text): array => ['type' => 'paragraph', 'data' => ['text' => $text]],
                    $paragraphs,
                ))
                ->takenBy($author, $author->getName());

            $this->entityManager->persist($note);
        }

        $this->entityManager->flush();
    }

    /**
     * Trois notes partagées et une personnelle.
     *
     * La personnelle n'est pas un quatrième exemple du même objet : c'est la
     * seule qui montre pourquoi les deux onglets existent, et elle dit ce qu'on
     * n'écrit pas sur un mur que l'équipe lit.
     *
     * @return list<array{0: string, 1: ?int, 2: bool, 3: SpaceNoteVisibilityEnum, 4: list<string>}>
     */
    private function noteContents(): array
    {
        return [
            ['Brief téléphonique', 4, true, SpaceNoteVisibilityEnum::Shared, [
                'Le client veut <b>éviter le vert</b> : trop proche de son concurrent de la zone.',
                'Livraison souhaitée avant les portes ouvertes. Marge réelle : trois semaines.',
                'Contact technique : son neveu, qui gère le site. Passer par lui pour les accès.',
            ]],
            ['À décider', null, false, SpaceNoteVisibilityEnum::Shared, [
                'Format des visuels : carré pour Instagram, ou 4:5 partout ?',
                "Est-ce qu'on reprend les photos existantes ou on refait une séance ?",
            ]],
            ['Ce qui a coincé en mars', 9, false, SpaceNoteVisibilityEnum::Shared, [
                "Les validations partaient par mail et se perdaient. D'où l'espace.",
                'Deux allers-retours sur un texte déjà validé, faute de trace écrite.',
            ]],
            ['À relancer', 2, true, SpaceNoteVisibilityEnum::Personal, [
                "Le devis photo avant la fin du mois : c'est passé deux fois à la trappe.",
                'Ne pas proposer le mardi pour les points, je suis en formation.',
            ]],
            ['Ce que je ne dirai pas comme ça', 6, false, SpaceNoteVisibilityEnum::Personal, [
                'La direction « artisanale » ne prend pas. Trouver comment le dire sans dire « ça ne marche pas ».',
                'Préparer deux planches avant le point, pas une seule à défendre.',
            ]],
        ];
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
    private function suiteUser(string $email): int
    {
        $user = $this->userRepository->findOneBy([
            'email' => $email,
            'type' => UserTypeEnum::Suite->value,
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
     * and an end - a dozen slides that could be given to a client without
     * anybody apologising for the demo. That is what the module's screenshots
     * need: a deck built to fill a page shows the editor, not the thing the
     * editor is for.
     *
     * The second stays four slides on purpose. A trame is a skeleton somebody
     * duplicates and fills, and dressing it up would hide what it is.
     *
     * Neither is an audit nor a strategy: those are written documents, and
     * they live in the deliverables (since 04/10/2026, Axel's call). A deck is
     * what is shown in a meeting - a kick-off, a monthly review.
     *
     * Between them they use every layout, images included: the full-page
     * picture reads nothing from the deck itself, it points at a document in
     * the library, so the two slides that carry one pull it by reference from
     * the GED fixtures rather than inventing a file of their own.
     */
    /**
     * Une présentation que l'équipe a mise à la corbeille, pour l'onglet des
     * présentations. À part de `seedDecks()`, qui ne joue qu'une fois sur une
     * base vide : celle-ci se pose aussi sur une démo déjà chargée, et une seule
     * fois, retrouvée par son titre.
     */
    private function seedTrashedDeck(): void
    {
        if (null !== $this->deckRepository->findOneBy(['title' => 'Trame de bilan trimestriel'])) {
            return;
        }

        $deck = $this->decks->create('Trame de bilan trimestriel');
        $deck->setDescription('Remplacée par la trame de point mensuel.');
        $this->slide($deck, SlideLayoutEnum::Title, ['title' => 'Bilan du trimestre', 'subtitle' => '{client}'], null);
        $deck->setDeletedAt(new DateTimeImmutable('-5 days'));
    }

    private function seedDecks(CustomerInterface $customer): void
    {
        // Built once, like the contracts above. Nothing here is looked up
        // before it is created - a deck has no natural key to look it up by -
        // so a second run would silently double a list meant to be read.
        if (0 !== $this->deckRepository->count([])) {
            return;
        }

        $kickOff = new DeckCategory();
        $kickOff->setName('Lancement')->setColor('#f59e0b')->setPosition(0);

        $review = new DeckCategory();
        $review->setName('Suivi')->setColor('#6366f1')->setPosition(1);

        $this->entityManager->persist($kickOff);
        $this->entityManager->persist($review);

        $this->seedKickOffDeck($customer, $kickOff);
        $this->seedMonthlyReviewTemplate($review);
    }

    /**
     * The deck that gets shown: a kick-off meeting, from what the client asked
     * for to the first date in the calendar.
     *
     * Ordered the way the meeting actually goes. What we heard comes first,
     * so the client recognises their own words; then how we will work, then
     * when. The quote at the end is the sentence everybody leaves the room
     * with, which is what a deck is for.
     */
    private function seedKickOffDeck(CustomerInterface $customer, DeckCategory $category): void
    {
        $deck = $this->decks->create('Réunion de lancement, refonte du site');
        $deck->setDescription('Ce que vous attendez du nouveau site, comment on travaille ensemble, et les six semaines qui viennent.');
        $deck->setCategory($category);
        $deck->setCustomer($customer);

        $this->slide($deck, SlideLayoutEnum::Title, [
            'title' => 'Réunion de lancement',
            'subtitle' => 'Atelier Dupont, octobre 2026',
        ], 'Remercier pour le temps pris. Annoncer quarante minutes, questions comprises.');

        $this->slide($deck, SlideLayoutEnum::Section, [
            'title' => 'Ce que vous nous avez dit',
        ], null);

        $this->slide($deck, SlideLayoutEnum::Bullets, [
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
        $this->slide($deck, SlideLayoutEnum::Image, [
            'mediaId' => $this->mediaId(1),
            'caption' => "Le ton visé pour l'accueil : une grande image, peu de mots",
        ], "Laisser l'image dix secondes avant de commenter.");

        $this->slide($deck, SlideLayoutEnum::Quote, [
            'quote' => "Un site qui ressemble à l'atelier, et qui ramène des demandes de devis.",
            'attribution' => 'Votre objectif, en une phrase',
        ], 'Marquer un temps ici : tout ce qui suit sert cette phrase.');

        $this->slide($deck, SlideLayoutEnum::Section, [
            'title' => 'Comment on travaille',
        ], null);

        $this->slide($deck, SlideLayoutEnum::Bullets, [
            'title' => 'Qui fait quoi',
            'bullets' => [
                'Vous : les photos des chantiers, les textes sur le métier, une personne pour valider',
                'Nous : les maquettes, la rédaction finale, la mise en ligne et les mesures',
                'Ensemble : un point de trente minutes chaque semaine, à heure fixe',
            ],
        ], 'Insister sur « une personne pour valider » : c\'est ce qui tient les délais.');

        $this->slide($deck, SlideLayoutEnum::Split, [
            'title' => "Ce qu'on vous demande, ce que vous recevez",
            'left' => 'Une vingtaine de photos de chantiers, trois textes sur votre métier, et une réponse sous deux jours à chaque validation.',
            'right' => 'Un site rapide sur téléphone, une page par type de chantier, un formulaire de devis qui arrive dans votre boîte, et un point de mesure un mois après.',
        ], 'Les deux colonnes se lisent en parallèle : laisser le temps.');

        $this->slide($deck, SlideLayoutEnum::Section, [
            'title' => 'Le calendrier',
        ], null);

        $this->slide($deck, SlideLayoutEnum::Bullets, [
            'title' => 'Six semaines, trois étapes',
            'bullets' => [
                'Semaines 1 et 2 : les maquettes, présentées puis ajustées une fois',
                'Semaines 3 et 4 : les contenus, rédigés à partir de vos photos et de vos notes',
                'Semaines 5 et 6 : la mise en ligne, puis les premières mesures',
            ],
        ], 'Dire tout de suite la date de mise en ligne visée, et ce qui la ferait glisser.');

        // Une slide libre, composée à la main : la démonstration de ce que le
        // canevas sait faire que les gabarits ne font pas. Un dégradé tiré des
        // couleurs du deck, une photo découpée en cercle, trois cartes groupées
        // qui entrent une à une, et une flèche posée en biais.
        $this->slide($deck, SlideLayoutEnum::Free, [
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

        $this->slide($deck, SlideLayoutEnum::Quote, [
            'quote' => 'Six semaines, une validation à chaque étape, et un site qui ramène des devis.',
            'attribution' => "Ce qu'il faut retenir",
        ], 'Fin. Fixer ensemble la date du premier point avant de se quitter.');

        // Un lien de partage, parce que l'écran qui les liste n'en montrait
        // aucun : un deck envoyé, ouvert une fois et qui expire dans deux
        // mois est l'état ordinaire d'un partage, pas un cas limite. La
        // lecture est posée à la main, aucune fixture n'ouvrant réellement
        // le lien.
        $link = new DeckShareLink($deck);
        $link
            ->setLabel('Atelier Dupont - envoi du 12')
            ->setExpiresAt(new DateTimeImmutable('+60 days'))
            ->touch(new DateTimeImmutable('-2 days 14:05'));

        $this->entityManager->persist($link);
    }

    /**
     * The other kind of deck: a skeleton, addressed to nobody.
     *
     * Deliberately short and deliberately vague, because it exists to be
     * duplicated per client rather than presented as it stands. The nullable
     * customer is the decision worth seeing on screen: a deck written for
     * oneself is the ordinary internal case, not a degraded one.
     */
    private function seedMonthlyReviewTemplate(DeckCategory $category): void
    {
        $deck = $this->decks->create('Trame de point mensuel');
        $deck->setDescription('La forme que prend le point du mois avec un client. À dupliquer, puis à remplir.');
        $deck->setCategory($category);

        $this->slide($deck, SlideLayoutEnum::Title, [
            'title' => 'Point du mois',
            'subtitle' => '{client}, {mois}',
        ], 'Remplacer les deux mentions avant de présenter.');

        $this->slide($deck, SlideLayoutEnum::Section, [
            'title' => 'Le mois écoulé',
        ], null);

        $this->slide($deck, SlideLayoutEnum::Split, [
            'title' => 'Prévu, fait',
            'left' => 'Ce qui était prévu ce mois-ci.',
            'right' => "Ce qui a été fait, et ce qui ne l'a pas été.",
        ], "La colonne de droite d'abord : c'est celle qu'on attend.");

        $this->slide($deck, SlideLayoutEnum::Bullets, [
            'title' => 'Le mois qui vient',
            'bullets' => [
                'Trois priorités, pas plus',
                'Ce que chacune demande de votre côté',
                'La date du prochain point',
            ],
        ], null);
    }

    /**
     * Les fichiers de l'espace lui-même, sur aucune fiche.
     *
     * Le pendant de `hangPictures` pour l'autre rattachement : la charte, le
     * logo, le plan des locaux. Pris dans la médiathèque par leur titre, pour
     * la même raison, et signés par le même compte.
     *
     * @param list<string> $titles
     */
    private function fileOnSpace(CustomerSpaceInterface $space, array $titles): void
    {
        $author = $this->userRepository->findOneBy(['email' => 'dev@aurora.app', 'type' => UserTypeEnum::Suite->value]);

        if (!$author instanceof User) {
            return;
        }

        foreach ($titles as $title) {
            $document = $this->documents->findOneBy(['title' => $title]);

            if (!$document instanceof Document) {
                continue;
            }

            $file = new SpaceFile();
            $file->setSpace($space)->setDocument($document)->addedByStudio($author, $author->getName());

            $this->entityManager->persist($file);
        }

        $this->entityManager->flush();
    }

    /**
     * Hangs demo pictures on a card, by document title.
     *
     * By title rather than by id, and looked up here rather than referenced,
     * because these documents are created by GED's own seeding table and are
     * not exposed as fixture references - only the two `mediaRef` ones are. A
     * title that stops existing silently hangs nothing, which is the right
     * failure for a demo: a missing thumbnail is visible, a fatal on
     * `make demo` is not.
     *
     * Signed through `attachAs()` rather than `attachAsStudio()` because a
     * fixture has no session to resolve an author from. The alternative was
     * building the row by hand, which is how the signing rules drift out of
     * step with the ones the product applies.
     *
     * @param list<string> $titles
     */
    private function hangPictures(SpaceContentItemInterface $item, array $titles): void
    {
        if ([] === $titles) {
            return;
        }

        $author = $this->userRepository->findOneBy(['email' => 'dev@aurora.app', 'type' => UserTypeEnum::Suite->value]);

        if (!$author instanceof User) {
            return;
        }

        foreach ($titles as $title) {
            $document = $this->documents->findOneBy(['title' => $title]);

            if (!$document instanceof Document) {
                continue;
            }

            $this->contentAttachments->attachAs($item, $document, $author, $author->getName());
        }
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

    /**
     * Une société sous contrat, avec la fiche que son espace montre.
     *
     * **Le SIREN est les neuf premiers chiffres du SIRET**, parce que c'est ce
     * qu'il est. La saisie refuse les deux quand ils ne s'accordent pas, donc
     * une démonstration où ils divergeraient montrerait un état que l'écran
     * n'accepte pas de produire.
     *
     * La fiche est posée après la création : `CustomerInput` porte l'identité
     * contractuelle, et ces colonnes-là appartiennent à l'autre saisie.
     *
     * @param list<array{label: string, url: string}> $links
     */
    private function customer(
        string $legalName,
        ?string $landline,
        string $phone,
        array $links,
        ?string $notes,
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

        $customer = $this->customers->create(new CustomerInput(
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
            // Des sociétés sous contrat : elles ont leur SIRET, leur siège et
            // leur représentant, donc elles ne sont pas des prospects - le
            // défaut du DTO décrit une fiche qu'on vient d'ouvrir, pas
            // celles-ci.
            status: CustomerStatusEnum::Client,
        ));

        $customer
            ->setSiren(mb_substr($siret, 0, 9))
            ->setPhone($phone)
            ->setLandline($landline)
            ->setLinks($links)
            ->setInformationNotes($notes);

        $this->entityManager->flush();

        return $customer;
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
     * `frozenAt` has no setter, which is the immutability doing its job, so the
     * demo moves it back in the database once the seal is written. It is not
     * part of what the hash covers, so the seal still verifies. Without it
     * every contract read as sealed today and signed two days earlier: signed
     * before it existed.
     */
    private function seal(ContractInterface $contract, ContractStatusEnum $status, string $sealedAt = 'now'): void
    {
        $this->contracts->freeze($contract);
        $this->entityManager->flush();

        if ('now' !== $sealedAt) {
            // Prepared the day before it was sealed, so the list's « last
            // activity » does not read today on every row.
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE core_contracts SET frozen_at = :at, created_at = :created WHERE id = :id',
                [
                    'at' => new DateTimeImmutable($sealedAt)->format('Y-m-d H:i:s'),
                    'created' => new DateTimeImmutable($sealedAt.' -1 day')->format('Y-m-d H:i:s'),
                    'id' => $contract->getId(),
                ],
            );
        }

        $contract->setStatus($status);
    }

    /**
     * The demo's adapted wording: the trame's body with one clause negotiated
     * by this client, through the same path as the screen.
     */
    private function adaptForClient(ContractInterface $contract): void
    {
        $translation = $contract->getBodyVersion()?->getTranslation($contract->getLocale());

        if (!$translation instanceof ContractTemplateVersionTranslationInterface) {
            return;
        }

        $blocks = $translation->getContent()['blocks'] ?? [];
        $blocks[] = $this->header('Clause particulière');
        $blocks[] = $this->paragraph("À la demande de {{customer.legal_name}}, les rendez-vous de suivi ont lieu le mardi matin, dans l'atelier, et le compte rendu est envoyé dans les deux jours ouvrés.");

        $this->contracts->adaptWording($contract, ContractTemplateKindEnum::Body, $translation->getTitle(), ['blocks' => $blocks]);
    }

    /**
     * Writes the signed PDF, as the countersignature does.
     *
     * Without it every concluded demo contract offered « Exporter en PDF »
     * where a real one offers its signed copy, and the morning seal check had
     * no file to verify.
     *
     * @param list<ContractSignature> $signatures
     */
    private function conclude(ContractInterface $contract, array $signatures, string $at): void
    {
        $this->entityManager->flush();

        $pdf = $this->pdf->generate($contract, $signatures);
        $contract->attachPdf($pdf['path'], $pdf['hash'], new DateTimeImmutable($at));
    }

    /**
     * The contract's history, dated as the scenario says.
     *
     * The demo sets statuses directly, so the history panel only ever held
     * « Contrat créé » and « Contrat scellé », both stamped with the day the
     * fixtures ran, under a summary that said it had been sent and signed.
     * What is written here is what the real path would have logged.
     *
     * @param array<string, string> $events action => relative date, in order
     */
    private function chronicle(ContractInterface $contract, array $events): void
    {
        $this->entityManager->flush();
        $connection = $this->entityManager->getConnection();

        $connection->executeStatement(
            "DELETE FROM core_audit_logs WHERE entity_type = 'Contract' AND entity_id = :id",
            ['id' => $contract->getId()],
        );

        // A minute apart: two events of the same day keep their order, the
        // seal before the link it allowed.
        $minute = 0;
        foreach ($events as $action => $at) {
            $at = new DateTimeImmutable($at)->modify(sprintf('+%d minutes', $minute++));
            $this->audit->log('studio', $action, 'Contract', $contract->getId(), ['reference' => $contract->getReference()]);

            $connection->executeStatement(
                "UPDATE core_audit_logs SET created_at = :at WHERE id = (SELECT MAX(id) FROM core_audit_logs WHERE entity_type = 'Contract' AND entity_id = :id AND action = :action)",
                ['at' => $at->format('Y-m-d H:i:s'), 'id' => $contract->getId(), 'action' => $action],
            );
        }
    }

    /**
     * The address the contract went out under.
     *
     * A contract « Envoyé » with no link behind it showed an empty panel and
     * a summary with no date, and the morning job would have marked it
     * expired: nothing it can find says it is still out with the customer.
     * The token is minted and thrown away; the demo only needs the dates.
     */
    private function link(
        ContractInterface $contract,
        string $sentAt,
        ?string $openedAt = null,
        ?string $revokedAt = null,
    ): void {
        $sent = new DateTimeImmutable($sentAt);

        $link = new ContractAccessLink();
        $link
            ->setContract($contract)
            ->setRecipientEmail((string) $contract->getCustomer()->getContractualEmail())
            ->setExpiresAt($sent->modify('+30 days'));
        $link->mint();
        $link->markSent($sent);

        if (null !== $openedAt) {
            $link->markUsed(new DateTimeImmutable($openedAt));
        }

        if (null !== $revokedAt) {
            $link->revoke(new DateTimeImmutable($revokedAt));
        }

        $this->entityManager->persist($link);
    }

    private function sign(
        ContractInterface $contract,
        ContractSignatureRoleEnum $role,
        CustomerInterface $customer,
        string $signedAt,
    ): ContractSignature {
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

        return $signature;
    }

    /** @return list<array<string, mixed>> */
    private function monthlyBody(): array
    {
        return [
            $this->header('Objet du contrat'),
            $this->paragraph('Le présent contrat définit les conditions dans lesquelles {{provider.name}}, représentée par {{provider.representative}}, assure pour {{customer.legal_name}} une prestation de suivi mensuel de son site internet.'),
            $this->header('Les parties'),
            $this->paragraph('Le prestataire : {{provider.name}}, {{provider.address}}, SIRET {{provider.siret}}, code APE {{provider.ape_code}}. {{provider.vat_mention}}.'),
            $this->paragraph('Le client : {{customer.legal_name}}, {{customer.legal_status}}, dont le siège est {{customer.registered_office}}, SIRET {{customer.siret}}, représentée par {{customer.representative_full_name}} en qualité de {{customer.representative_role}}.'),
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
