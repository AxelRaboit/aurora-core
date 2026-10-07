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
use Aurora\Module\Notes\Favorite\Manager\NoteFavoriteManagerInterface;
use Aurora\Module\Notes\Folder\Dto\NoteFolderInputFactoryInterface;
use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;
use Aurora\Module\Notes\Folder\Manager\NoteFolderManagerInterface;
use Aurora\Module\Notes\Markdown\Dto\MarkdownNoteInputFactoryInterface;
use Aurora\Module\Notes\Markdown\Manager\MarkdownNoteManagerInterface;
use Aurora\Module\Notes\Space\Service\NoteSpaceAccess;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Contract\Access\Entity\ContractAccessLink;
use Aurora\Module\Studio\Contract\Dto\ContractInput;
use Aurora\Module\Studio\Contract\Dto\ContractTemplateInput;
use Aurora\Module\Studio\Contract\Dto\ContractTemplateVersionInput;
use Aurora\Module\Studio\Contract\Entity\ContractInterface;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateCategory;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateCategoryInterface;
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
use Aurora\Module\Studio\SpaceNote\Service\SpaceNoteSpaceProvider;
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

use function implode;
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
        private readonly SpaceNoteSpaceProvider $noteSpaces,
        private readonly NoteSpaceAccess $noteSpaceAccess,
        private readonly MarkdownNoteManagerInterface $markdownNotes,
        private readonly MarkdownNoteInputFactoryInterface $markdownNoteInputs,
        private readonly NoteFolderManagerInterface $noteFolders,
        private readonly NoteFolderInputFactoryInterface $noteFolderInputs,
        private readonly NoteFavoriteManagerInterface $noteFavorites,
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

        // Two categories, and one trame left without: the list shows its
        // coloured pills, its filter, and the "unclassified" state side by side.
        $recurring = $this->templateCategory('Accompagnement mensuel', '#6366f1', 0);
        $oneOff = $this->templateCategory('Prestations ponctuelles', '#f59e0b', 1);
        foreach ([[$monthly, $recurring], [$annex, $recurring], [$oneShot, $oneOff]] as [$filed, $category]) {
            if (!$filed->getCategory() instanceof ContractTemplateCategoryInterface) {
                $filed->setCategory($category);
            }
        }

        $this->entityManager->flush();

        // The draft that stays a draft. Opened after publication, so the trame
        // has both a version in force and a version being written - the pair
        // the version badge exists to tell apart. Only when there is not one
        // already: a trame may hold a single draft, guaranteed by a partial
        // index, and a second `make demo` must not go asking for a second.
        if (!$oneShot->getDraft() instanceof ContractTemplateVersionInterface) {
            $this->templates->openDraft($oneShot);
            $this->entityManager->flush();
        }

        // The presentations are slides deliverables now, seeded by
        // `DeliverableDemoFixtures`.
        $this->seedSpaces($marie, $jean, $sophie);
        $this->seedApprovals();
        $this->seedTrash($jean);
        $this->seedClientFile();

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

        // 7 to 11. The five missing states, one per row.
        //
        // **Nine states exist, the demo showed four.** The other five were
        // therefore seen nowhere: not on screen while learning the module, not
        // in a screenshot, not in an automated run - it was the lack of a
        // contract "sent but not yet opened" that made a whole flow fail, for
        // want of a row to click.
        //
        // A status enum is a promise made to the reader: each of its cases
        // must be representable, otherwise nobody knows what it looks like.

        // Sealed but not yet sent: the document is frozen, the reference is
        // struck, and nothing has gone out. The state of a contract reviewed
        // before pressing the button.
        $sealed = $this->contract($marie, $oneShot, null, 750_00, '+1 month', [
            'acompte' => '30 %',
        ]);
        $this->seal($sealed, ContractStatusEnum::Sealed);

        // Opened: the client clicked the link and has not answered. That is
        // the information that turns a reminder into a conversation.
        $opened = $this->contract($sophie, $monthly, $annex, 540_00, '+3 weeks', [
            'formule' => 'Suivi',
            'duree' => '12 mois',
        ]);
        $this->seal($opened, ContractStatusEnum::Opened, '-3 days');
        $this->link($opened, sentAt: '-3 days', openedAt: '-1 day');
        $this->chronicle($opened, ['contract.created' => '-4 days', 'contract.frozen' => '-3 days', 'contract.link_sent' => '-3 days']);

        // Signed by the client, waiting for countersignature: the ball is in
        // your court, and this is the only state that says so.
        $waitingCountersign = $this->contract($jean, $monthly, $annex, 620_00, '+2 weeks', [
            'formule' => 'Suivi',
            'duree' => '18 mois',
        ]);
        $this->seal($waitingCountersign, ContractStatusEnum::SignedByCustomer, '-7 days');
        $this->link($waitingCountersign, sentAt: '-7 days', openedAt: '-3 days', revokedAt: '-3 days');
        $this->sign($waitingCountersign, ContractSignatureRoleEnum::Customer, $jean, '-3 days');
        $this->chronicle($waitingCountersign, ['contract.created' => '-8 days', 'contract.frozen' => '-7 days', 'contract.link_sent' => '-7 days', 'contract.signed_by_customer' => '-3 days']);

        // Expired: nobody signed in time. A past effective date, so the row
        // reads without having to work it out.
        $expired = $this->contract($sophie, $oneShot, null, 320_00, '-3 weeks', [
            'acompte' => '50 %',
        ]);
        // Sent more than thirty days ago: the address lapsed unanswered.
        $this->seal($expired, ContractStatusEnum::Expired, '-40 days');
        $this->link($expired, sentAt: '-40 days');
        $this->chronicle($expired, ['contract.created' => '-41 days', 'contract.frozen' => '-40 days', 'contract.link_sent' => '-40 days', 'contract.expired' => '-10 days']);

        // Revoked: withdrawn before signature, by you. Not to be confused with
        // a refusal, which comes from the client.
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
        // Built once, like the contracts. A space has no natural key to look
        // one up by, so a second `make demo` would quietly double a list that
        // is meant to be read.
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
        // One of each kind, and both visibilities: a state nobody ever sees is
        // a state nobody knows the look of, and that is precisely the case of
        // "the client does not see this one", which by construction has
        // nothing visible.
        $this->seedResources($social, [
            ['link', 'Maquette Canva', 'https://canva.example.com/atelier-dupont-reseaux', null, true],
            ['contact', 'Léa, communication', null, 'Prépare les visuels. Disponible le lundi et le jeudi.', true, 'lea@atelier-dupont.example.com', '06 55 44 33 22'],
            ['text', 'Ton et vocabulaire', null, 'Tutoiement, phrases courtes. On dit « atelier », jamais « entreprise ». Les prix ne sont jamais annoncés en publication.', true],
            ['link', "Tableau de bord de l'hébergeur", 'https://panel.example.com/atelier-dupont', "Accès par le gestionnaire de mots de passe de l'agence.", false],
            ['text', 'Facturation', null, 'Mensuelle, le 5. Relance automatique à J+15, relance manuelle à J+30.', false],
        ]);
        // Files that illustrate nothing: what is handed to the client without
        // pinning it to a post, and what is worked from without handing it
        // over. Both states, for the reason of the resources above.
        $this->fileOnSpace($social, [
            'Logo Aurora - Fond sombre' => true,
            'Plan des locaux - Étage 2' => false,
            'Charte Graphique Aurora - Brand Guidelines' => true,
        ]);

        // The only space whose Drive tab is locked, and the only one that
        // names a shared folder. A setting no space carries is a setting
        // nobody sees: the settings screen always came out empty, and the
        // Drive tab always open.
        //
        // The password is `demonstration` - a literal in a public repository,
        // like the development account's password, and for the same reason:
        // it opens nothing but a local demonstration.
        $locked = $this->space(
            name: 'Atelier Dupont - Refonte du site',
            description: 'Reprise des textes et des photos de chantier, livraison au printemps.',
            customer: $marie,
            members: [$admin => 'lead'],
        );

        if (!$locked->isDriveLocked()) {
            // **A plainly fake identifier**, and that is the point: a real
            // Drive folder's identifier once sat here, and this repository is
            // public. A folder identifier opens nothing on its own, but it
            // names real infrastructure, which has no place in a
            // demonstration. Google will refuse it, the screen will say the
            // folder does not answer, and that is a state the demo must show.
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

        // A space opened for a prospect, and that is the only thing that sets
        // it apart: same board, same cards, same notes. People work with
        // somebody before they sign, and the screen must show it - a
        // demonstration where prospects are empty shells would teach the
        // opposite.
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
     * One content and one space in the trash, so the trash screen shows both
     * Studio tabs filled.
     *
     * Outside `seedSpaces()`, which runs only once: found by their title and
     * name, they are added to a demonstration already in place without
     * touching anything else, and do not double on the next run. Trashed by
     * the managers, as the screen would do, then dated a few days back: a
     * trash where everything arrived just now does not say how long is left
     * before the purge.
     */
    private function seedTrash(CustomerInterface $jean): void
    {
        $social = $this->spaceRepository->findOneBy(['name' => 'Atelier Dupont - Réseaux sociaux']);
        $columns = $social instanceof CustomerSpaceInterface ? $this->contentColumns->findForSpace($social) : [];

        if ($social instanceof CustomerSpaceInterface && [] !== $columns
            && null === $this->entityManager->getRepository(SpaceContentItem::class)->findOneBy(['space' => $social, 'title' => 'Promo de printemps'])) {
            $item = $this->contentItems->create($social, new SpaceContentItemInput(
                title: 'Promo de printemps',
                body: "Remise sur les tables en chêne. Abandonnée : l'atelier préfère ne pas annoncer de prix.",
                columnId: $columns[0]->getId(),
            ));
            $this->contentItems->trash($item);
            $item->setDeletedAt(new DateTimeImmutable('-2 days'));
            $this->entityManager->flush();
        }

        if (null === $this->spaceRepository->findOneBy(['name' => 'Martin Documents - Salon 2025'])) {
            $salon = $this->space(
                name: 'Martin Documents - Salon 2025',
                description: "Les publications autour du salon de l'archivage. Ouvert en double par erreur.",
                customer: $jean,
                members: [$this->suiteUser('jean.martin@aurora.app') => 'lead'],
            );
            $this->spaces->trash($salon);
            $salon->setDeletedAt(new DateTimeImmutable('-6 days'));
            $this->entityManager->flush();
        }
    }

    /**
     * One file the client sent to the space itself, from their page.
     *
     * Outside `seedSpaces()`, like the trash above, so an existing demo gains
     * it on the next `make demo` and keeps a single copy afterwards. Sent by
     * the photographer's link, the one that holds the right to send files, and
     * dated two days back: the Files tab then shows the three states side by
     * side - shown to the client, hidden from them, and sent by them.
     */
    private function seedClientFile(): void
    {
        $social = $this->spaceRepository->findOneBy(['name' => 'Atelier Dupont - Réseaux sociaux']);
        $document = $this->documents->findOneBy(['title' => "Photo d'équipe - Séminaire 2025"]);

        if (!$social instanceof CustomerSpaceInterface || !$document instanceof Document) {
            return;
        }

        $link = $this->existingLinkFor($social, 'studio@lumiere-photo.test');

        if (!$link instanceof SpaceAccessLinkInterface
            || null !== $this->entityManager->getRepository(SpaceFile::class)->findOneBy(['space' => $social, 'document' => $document])) {
            return;
        }

        $file = new SpaceFile();
        $file->setSpace($social)->setDocument($document)->addedByClient($link);
        $this->entityManager->persist($file);
        $this->entityManager->flush();

        $this->entityManager->createQuery('UPDATE '.SpaceFile::class.' f SET f.createdAt = :at WHERE f.id = :id')
            ->setParameter('at', new DateTimeImmutable('-2 days 16:20'))
            ->setParameter('id', $file->getId())
            ->execute();
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
        //
        // Hidden from the client, like any added stage: a lawyer's review is
        // an internal stage, and it is the example of the unticked box.
        $this->contentColumnManager->create($space, new SpaceContentColumnInput(
            name: 'Relecture juridique',
            colourSlot: 8,
        ));

        $columns = $this->contentColumns->findForSpace($space);

        // "Programmé" shown by hand: a new space shows the client only the
        // review and what is published, and this client wants to see the
        // month ahead. Without this step, the demonstration would have no
        // stage shown by somebody, and the card they approved yesterday would
        // disappear from their page.
        if (isset($columns[3])) {
            $columns[3]->setVisibleToClient(true);
            $this->entityManager->flush();
        }

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
                // The only dated card that does not appear in the month: a
                // studio deadline, not a post. Without it, the demonstration
                // would have no example of the unticked box, and the case it
                // exists to carry would stay invisible.
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
                    // The fifth element, absent everywhere except on the
                    // internal card: a list of cards stays readable when the
                    // common case does not write it.
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
     * Parameterised because two spaces have one: a client's and a prospect's,
     * and making the second speak with the first one's words would be a
     * demonstration that contradicts itself.
     *
     * **The client's side is signed by a real access link**, issued here like
     * the studio would: a message signed any other way would be data no code
     * ever produces, which is how a demo stops resembling the product. The link
     * is also what the access screen needs to have something to show.
     */
    /**
     * The states an access link can take, one per row.
     *
     * **Two identical links teach nothing.** The client access screen showed
     * two recipients with the same rights, both valid: not what a revoked
     * link becomes, not that a reader may have only the right to read, not
     * that an upload right exists, not that a space can be opened without
     * opening its Drive.
     *
     * A right nobody ever sees ticked or unticked is a right nobody knows
     * exists.
     */
    private function seedAccessLinks(CustomerSpaceInterface $space): void
    {
        // A reader, and nothing more: the client's partner, a superior, a
        // partner agency. They look, they do not decide.
        $this->accessLink($space, 'lea@atelier-dupont.fr', 'Léa, communication', 90, false, false);

        // The right to upload a file, unticked by default: the client sends
        // their visuals instead of attaching them to an email.
        $this->accessLink($space, 'studio@lumiere-photo.test', 'Studio Lumière, photographe', 60, false, true, canUpload: true);

        // A link without the right to see the Drive folder. The share
        // sometimes holds documents that are not everybody's business.
        $this->accessLink($space, 'audit@cabinet-verrier.test', 'Cabinet Verrier, audit', 30, false, true, canSeeDrive: false);

        // Revoked: withdrawn by hand. The row stays, struck through, because
        // erasing an access would also erase who had answered what.
        $this->accessLink($space, 'ancien.stagiaire@atelier-dupont.fr', 'Thomas, stage terminé', 90, false, true)
            ?->revoke(new DateTimeImmutable('-6 days'));

        // Expired on its own. An access that outlives the engagement is an
        // access nobody thinks of closing, hence the mandatory end date.
        $this->accessLink($space, 'salon@expo-artisans.test', "Salon des artisans, édition d'avril", 90, false, true)
            ?->setExpiresAt(new DateTimeImmutable('-3 days'));

        $this->entityManager->flush();
    }

    /** One link, once, however many reloads there are. */
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

        // Named rather than positional: the signature carries six rights, and
        // inserting one in the middle would silently shift all the others.
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

        // **One link per person, however many runs there are.**
        // `issue()` creates one on every call, so three `make demo` gave
        // Camille three times in the client access screen: a list saying
        // three addresses were sent to the same person, which is not what the
        // demonstration describes and what a reader takes for a product
        // defect.
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

        // The space's main channel: a message belongs to a channel since the
        // conversation has had several, and the space was born with this
        // one.
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
     * The link already issued for this address, if there is one.
     *
     * Looked up among the space's links rather than with a separate query:
     * the demonstration sets two or three per space, and the repository
     * already knows how to return them.
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
     * Two more channels on the best-filled space.
     *
     * One internal and one open, because that is the distinction the feature
     * exists to carry: a demonstration with only open channels would not show
     * where the agency talks among itself, and one with only closed channels
     * would suggest the client never sees any.
     *
     * The main channel is not created here: a space is born with it.
     */
    private function seedChannels(CustomerSpaceInterface $space): void
    {
        // Both accounts, and the demonstration account first: a channel is
        // seen only by those in it, so a channel created with nobody inside
        // is a channel the studio never finds again - including whoever just
        // created it.
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

        // And a private conversation between the two accounts: that is the
        // second half of the conversation, and a demonstration where the
        // section is empty suggests it serves no purpose.
        if (2 === count($accounts)) {
            [$first, $second] = array_values($accounts);
            $this->chatChannels->openDirect($space, $first, null, $second, null);
        }
    }

    /**
     * Two commented cards, studio and client mixed.
     *
     * The table was empty, so a card's thread always opened on "no comment":
     * the feature existed and was seen nowhere. Two cards out of eight, not
     * eight out of eight - a card without discussion is the board's most
     * common state and it has to be seen too.
     *
     * The client signs with the conversation's access link, not with another
     * one: two links for the same person would put two people on screen.
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

        // Like the conversation: the entity timestamps on creation and has no
        // setter for it, which is right - a comment does not get
        // re-dated.
        foreach ($dates as $id => $at) {
            $this->entityManager->createQuery(
                'UPDATE '.SpaceContentComment::class.' c SET c.createdAt = :at WHERE c.id = :id'
            )->setParameter('at', $at)->setParameter('id', $id)->execute();
        }
    }

    /**
     * Two client opinions on the board: one content approved, one to redo.
     *
     * Without them, the opinion badge showed on no card and the card never
     * had an answer to show, although that is the act the space exists to
     * collect. Outside the spaces guard, and replayed without duplicates: an
     * opinion already given is left as it is, and the thread of the card to
     * redo is written only once.
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
     * What a space pins: a link, a text, a contact.
     *
     * The addresses are on `example.com` and the people are made up, as
     * everywhere else in the demonstration: nothing here may name real
     * infrastructure or a real person.
     *
     * **The contact details are written, not derived from the label.**
     * Deriving them gave `léa.communication@…`: an address the validator
     * refuses, and that the demonstration still showed as if it could be
     * typed.
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
     * A record opened for somebody of whom nothing but the name is known.
     *
     * No address and no SIRET, because that is the state a prospect
     * describes: you meet somebody, open a space to structure the work, and
     * everything else waits. A demonstration where prospects arrive with
     * their full legal identity would show a client in disguise.
     */
    private function prospect(string $legalName): CustomerInterface
    {
        return $this->customers->create(new CustomerInput(
            legalName: $legalName,
            status: CustomerStatusEnum::Prospect,
        ));
    }

    /**
     * A prospect's board: less advanced, and that is all.
     *
     * Three cards instead of eight, nothing published, no opinion given. A
     * project that has not started yet looks like this, and putting it next
     * to the other space's full board is what shows both states.
     */
    private function seedProspectBoard(CustomerSpaceInterface $space): void
    {
        $columns = $this->contentColumns->findForSpace($space);

        if ([] === $columns) {
            return;
        }

        // The logo directions wait for their opinion, at the stage the client
        // sees: that is what the conversation announces to them, and the only
        // part of the board their link shows. The rest is internal work,
        // hidden like any ideas or writing stage.
        $cards = [
            0 => [
                ['Palette et typographie', 'À caler une fois la direction choisie.', null, []],
            ],
            1 => [
                ["Photos de l'atelier", 'Prévoir une demi-journée sur place, lumière du matin.', '+6 days 10:00', ["Photo d'équipe - Séminaire 2025"]],
            ],
            2 => [
                ['Pistes de logo', "Trois directions : menuisier d'art, atelier familial, bois brut.", '+4 days 18:00', ['Logo Aurora - Fond sombre', 'Visuel de campagne - Automne 2025']],
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
     * Notes on a space: the only surface the client does not see.
     *
     * Written the way people write them - the brief taken on the phone, what
     * is left to decide, what got stuck - because a demonstration where notes
     * are filler paragraphs does not teach what they are for.
     *
     * **In the Notes module, through its managers**: the note space of the
     * client space, opened the way the tab would, receives the team's notes;
     * private notes go into their author's personal space, filed in a folder
     * named after the client space - where the migration files the older
     * ones. A pinned note becomes a favourite of its author.
     */
    private function seedNotes(CustomerSpaceInterface $space): void
    {
        $marie = $this->userRepository->find($this->suiteUser('marie.dupont@aurora.app'));
        // The personal notes are taken by the development account, and that
        // is the only choice that shows anything: a personal note is read only
        // in its author's space, so signed by somebody else it would stay
        // invisible to whoever is looking.
        $admin = $this->userRepository->find($this->suiteUser('dev@aurora.app'));

        if (!$marie instanceof User || !$admin instanceof User) {
            return;
        }

        $team = $this->noteSpaces->resolve($space);
        $folder = null;

        foreach ($this->noteContents() as [$title, $pinned, $personal, $paragraphs]) {
            $author = $personal ? $admin : $marie;

            if ($personal && !$folder instanceof NoteFolderInterface) {
                $folder = $this->noteFolders->create($admin, $this->noteFolderInputs->fromArray([
                    'name' => $space->getName(),
                    'spaceId' => $this->noteSpaceAccess->personalSpace($admin)->getId(),
                ]));
            }

            $note = $this->markdownNotes->create($author, $this->markdownNoteInputs->fromArray([
                'title' => $title,
                'content' => implode("\n\n", $paragraphs),
                'spaceId' => $personal ? null : $team->getId(),
                'folderId' => $personal ? $folder?->getId() : null,
            ]));

            if ($pinned) {
                $this->noteFavorites->toggle($author, $note);
            }
        }
    }

    /**
     * Three team notes and two private ones, pinned ones first.
     *
     * The personal ones are not one more example of the same object: they are
     * the only ones that show a private note belongs somewhere other than the
     * space the team reads.
     *
     * @return list<array{0: string, 1: bool, 2: bool, 3: list<string>}>
     */
    private function noteContents(): array
    {
        return [
            ['Brief téléphonique', true, false, [
                'Le client veut **éviter le vert** : trop proche de son concurrent de la zone.',
                'Livraison souhaitée avant les portes ouvertes. Marge réelle : trois semaines.',
                'Contact technique : son neveu, qui gère le site. Passer par lui pour les accès.',
            ]],
            ['À décider', false, false, [
                "- Format des visuels : carré pour Instagram, ou 4:5 partout ?\n- Est-ce qu'on reprend les photos existantes ou on refait une séance ?",
            ]],
            ['Ce qui a coincé en mars', false, false, [
                "Les validations partaient par mail et se perdaient. D'où l'espace.",
                'Deux allers-retours sur un texte déjà validé, faute de trace écrite.',
            ]],
            ['À relancer', true, true, [
                "- [ ] Le devis photo avant la fin du mois : c'est passé deux fois à la trappe.\n- [ ] Ne pas proposer le mardi pour les points, je suis en formation.",
            ]],
            ['Ce que je ne dirai pas comme ça', false, true, [
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
     * The files of the space itself, on no card.
     *
     * The counterpart of `hangPictures` for the other attachment: the brand
     * guidelines, the logo, the floor plan. Taken from the media library by
     * their title, for the same reason, and signed by the same account.
     *
     * Each title says whether it is shown to the client: a space file is born
     * hidden, like everything a space can show them.
     *
     * @param array<string, bool> $titles
     */
    private function fileOnSpace(CustomerSpaceInterface $space, array $titles): void
    {
        $author = $this->userRepository->findOneBy(['email' => 'dev@aurora.app', 'type' => UserTypeEnum::Suite->value]);

        if (!$author instanceof User) {
            return;
        }

        foreach ($titles as $title => $visible) {
            $document = $this->documents->findOneBy(['title' => $title]);

            if (!$document instanceof Document) {
                continue;
            }

            $file = new SpaceFile();
            $file->setSpace($space)->setDocument($document)->addedByStudio($author, $author->getName())->setVisibleToClient($visible);

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
     * A company under contract, with the record its space shows.
     *
     * **The SIREN is the first nine digits of the SIRET**, because that is
     * what it is. The form refuses both when they disagree, so a
     * demonstration where they diverged would show a state the screen refuses
     * to produce.
     *
     * The record is set after creation: `CustomerInput` carries the
     * contractual identity, and these columns belong to the other form.
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
            // Companies under contract: they have their SIRET, their head
            // office and their representative, so they are not prospects - the
            // DTO's default describes a record just opened, not these.
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
     */
    /** A trame category, or the one already there under that name. */
    private function templateCategory(string $name, string $color, int $position): ContractTemplateCategoryInterface
    {
        $existing = $this->entityManager->getRepository(ContractTemplateCategory::class)->findOneBy(['name' => $name]);

        if ($existing instanceof ContractTemplateCategoryInterface) {
            return $existing;
        }

        $category = new ContractTemplateCategory();
        $category->setName($name)->setColor($color)->setPosition($position);
        $this->entityManager->persist($category);

        return $category;
    }

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
