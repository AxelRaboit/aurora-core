<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Contract;

use Aurora\Core\Content\BlockHtmlSanitizer;
use Aurora\Core\Money\Enum\CurrencyEnum;
use Aurora\Core\Sequence\SequenceGenerator;
use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Studio\Contract\Dto\ContractInput;
use Aurora\Module\Studio\Contract\Dto\ContractTemplateInput;
use Aurora\Module\Studio\Contract\Dto\ContractTemplateVersionInput;
use Aurora\Module\Studio\Contract\Entity\Contract;
use Aurora\Module\Studio\Contract\Entity\ContractInterface;
use Aurora\Module\Studio\Contract\Entity\ContractTemplate;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateVersionInterface;
use Aurora\Module\Studio\Contract\Enum\ContractStatusEnum;
use Aurora\Module\Studio\Contract\Enum\ContractTemplateKindEnum;
use Aurora\Module\Studio\Contract\Exception\FrozenContractIsImmutableException;
use Aurora\Module\Studio\Contract\Manager\ContractManager;
use Aurora\Module\Studio\Contract\Manager\ContractTemplateManager;
use Aurora\Module\Studio\Contract\Preview\ContractTemplatePreviewer;
use Aurora\Module\Studio\Contract\Repository\ContractRepository;
use Aurora\Module\Studio\Contract\Repository\ContractTemplateRepository;
use Aurora\Module\Studio\Contract\Repository\ContractTemplateVersionRepository;
use Aurora\Module\Studio\Contract\Service\ContractCanonicalizer;
use Aurora\Module\Studio\Contract\Service\ContractCustomFieldScanner;
use Aurora\Module\Studio\Contract\Service\ContractDocumentRenderer;
use Aurora\Module\Studio\Contract\Service\ContractRetentionPolicy;
use Aurora\Module\Studio\Contract\Service\ContractSeal;
use Aurora\Module\Studio\Contract\Service\ContractVariableCatalogue;
use Aurora\Module\Studio\Contract\Service\ContractVariableResolver;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\Customer\Repository\CustomerRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function mb_substr_count;
use function preg_match;
use function sprintf;

/**
 * The freeze, against a real database.
 *
 * This is the phase the whole module was designed around, so the tests are
 * about what must never happen rather than about the happy path alone: a
 * contract that changes after being sealed, a draft version reaching a signer,
 * a token that stands for nothing arriving as literal braces in a clause.
 */
final class ContractFreezeTest extends IntegrationTestCase
{
    private ContractManager $contractManager;

    private ContractTemplateManager $contractTemplateManager;

    private ContractSeal $seal;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        // The whole chain is built by hand. None of these services has an HTTP
        // consumer yet - the contract screens land in the next slice - so the
        // container removes them as unused private services. They have no
        // state and their own dependencies are pure, so constructing them here
        // exercises exactly the code that will run in production.
        $canonicalizer = new ContractCanonicalizer();
        $this->seal = new ContractSeal($canonicalizer);
        $resolver = new ContractVariableResolver(new ContractVariableCatalogue(), $container->get(SettingRepository::class), static::getContainer()->get(TranslatorInterface::class));
        $renderer = new ContractDocumentRenderer(new BlockHtmlSanitizer());

        // Built by hand: neither manager has a controller yet, so the container
        // removes them as unused private services. Their dependencies and the
        // database behind them are the real ones.
        $this->contractTemplateManager = new ContractTemplateManager(
            $this->entityManager,
            $container->get(AuditLogger::class),
            $container->get(ContractTemplateVersionRepository::class),
            $container->get(TranslatorInterface::class),
            $container->get(ContractRepository::class),
            $container->get(ContractTemplatePreviewer::class),
        );

        $this->contractManager = new ContractManager(
            $this->entityManager,
            $container->get(AuditLogger::class),
            $resolver,
            $renderer,
            $canonicalizer,
            $this->seal,
            $container->get(SequenceGenerator::class),
            $container->get(SettingRepository::class),
            $container->get(CustomerRepository::class),
            $container->get(ContractTemplateRepository::class),
            $container->get(TranslatorInterface::class),
            new ContractCustomFieldScanner(),
            new ContractRetentionPolicy($container->get(SettingRepository::class)),
            $container->get(ContractRepository::class),
        );
    }

    protected function tearDown(): void
    {
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', Contract::class))->execute();
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', ContractTemplate::class))->execute();
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', Customer::class))->execute();

        parent::tearDown();
    }

    public function testFreezingSealsTheDocumentInOneGo(): void
    {
        $contract = $this->draft();

        $this->contractManager->freeze($contract);

        self::assertTrue($contract->isFrozen());
        // Every part of the seal is written together: a contract can never hold
        // a snapshot without the hash that covers it.
        self::assertNotNull($contract->getReference());
        self::assertNotSame([], $contract->getContentSnapshot());
        self::assertNotNull($contract->getRenderedHtml());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $contract->getContentHash());
        self::assertSame('sha256', $contract->getHashAlgo());
        self::assertSame(ContractCanonicalizer::VERSION, $contract->getCanonicalVersion());
        // Sealed, not sent. Sealing makes the document final; sending is the
        // separate act that hands out an address.
        self::assertSame(ContractStatusEnum::Sealed, $contract->getStatus());
        self::assertTrue($this->seal->verify($contract));
    }

    public function testTheReferenceIsMintedAtFreezeAndPrintedInTheDocument(): void
    {
        $contract = $this->draft(body: [
            ['type' => 'paragraph', 'data' => ['text' => 'Référence : {{contract.reference}}']],
        ]);

        self::assertNull($contract->getReference());

        $this->contractManager->freeze($contract);

        $reference = (string) $contract->getReference();
        self::assertStringContainsString('-'.date('Y').'-', $reference);
        // Minted before the rendering, which is why it can appear inside the
        // document the hash covers.
        self::assertStringContainsString($reference, (string) $contract->getRenderedHtml());
    }

    public function testCustomerValuesAreSubstitutedAndSignatureOnesAreNot(): void
    {
        $contract = $this->draft(body: [
            ['type' => 'paragraph', 'data' => ['text' => 'Entre {{customer.legal_name}}, SIRET {{customer.siret}},']],
            ['type' => 'paragraph', 'data' => ['text' => 'Fait à {{contract.signature_city}}, le {{contract.signature_date}}.']],
        ]);

        $this->contractManager->freeze($contract);

        $html = (string) $contract->getRenderedHtml();

        self::assertStringContainsString('Boulangerie Durand', $html);
        // Printed in its groups, the way it is read off a document.
        self::assertStringContainsString('732 829 320 00074', $html);
        // Left standing on purpose: the signer states these, exactly as
        // "fait à …, le …" is a blank on paper.
        self::assertStringContainsString('{{contract.signature_city}}', $html);
        self::assertStringContainsString('{{contract.signature_date}}', $html);
    }

    /**
     * A sole trader has no share capital, and the trame used to say
     * « Entreprise individuelle au capital de , » all the same.
     */
    public function testTheLegalStatusSaysTheCapitalOnlyWhenThereIsOne(): void
    {
        $body = [['type' => 'paragraph', 'data' => ['text' => '{{customer.legal_name}}, {{customer.legal_status}}, dont le siège']]];

        $soleTrader = $this->customer();
        $soleTrader->setLegalForm('Entreprise individuelle');
        $withoutCapital = $this->draft(body: $body, customer: $soleTrader);
        $this->contractManager->freeze($withoutCapital);

        self::assertStringContainsString('Boulangerie Durand, Entreprise individuelle, dont le siège', (string) $withoutCapital->getRenderedHtml());

        $company = new Customer();
        $company
            ->setLegalName('Boulangerie Durand')
            ->setContractualEmail('contact@durand.test')
            ->setLegalForm('SARL')
            ->setShareCapitalCents(1_000_000)
            ->setShareCapitalCurrency(CurrencyEnum::EUR);
        $this->entityManager->persist($company);
        $this->entityManager->flush();
        $withCapital = $this->draft(body: $body, customer: $company);
        $this->contractManager->freeze($withCapital);

        self::assertMatchesRegularExpression('/SARL au capital de 10.000[^,]*€, dont le siège/u', (string) $withCapital->getRenderedHtml());
    }

    /**
     * The identity list of a sole trader: no RCS, no VAT number. The lines
     * printed « RCS : » with nothing after them; they are left out now, and
     * a line with a value, or with no variable at all, stays.
     */
    public function testAListLineWhoseVariablesAreAllEmptyIsLeftOut(): void
    {
        $contract = $this->draft(body: [
            ['type' => 'list', 'data' => ['style' => 'unordered', 'items' => [
                ['content' => '<b>Raison sociale</b> : {{customer.legal_name}}', 'items' => []],
                ['content' => '<b>RCS</b> : {{customer.trade_register}}', 'items' => []],
                ['content' => '<b>TVA</b> : {{customer.vat_number}}', 'items' => []],
                ['content' => 'Une ligne sans variable', 'items' => []],
            ]]],
            ['type' => 'list', 'data' => ['style' => 'unordered', 'items' => [
                ['content' => '<b>Téléphone</b> : {{customer.phone}}', 'items' => []],
            ]]],
        ]);

        $this->contractManager->freeze($contract);
        $html = (string) $contract->getRenderedHtml();

        self::assertStringContainsString('Boulangerie Durand', $html);
        self::assertStringContainsString('Une ligne sans variable', $html);
        self::assertStringNotContainsString('RCS', $html);
        self::assertStringNotContainsString('TVA', $html);
        // A list left with no line is no list at all.
        self::assertStringNotContainsString('Téléphone', $html);
        self::assertSame(1, mb_substr_count($html, '<ul>'));
    }

    public function testAFrozenContractRefusesEveryWrite(): void
    {
        $contract = $this->draft();
        $this->contractManager->freeze($contract);

        $this->expectException(FrozenContractIsImmutableException::class);

        $contract->setLocale('en');
    }

    public function testAFrozenContractCannotBeFrozenAgain(): void
    {
        $contract = $this->draft();
        $this->contractManager->freeze($contract);
        $firstHash = $contract->getContentHash();

        try {
            $this->contractManager->freeze($contract);
            self::fail('A second freeze should have been refused.');
        } catch (FrozenContractIsImmutableException) {
            self::assertSame($firstHash, $contract->getContentHash());
        }
    }

    public function testADraftVersionCannotBeSent(): void
    {
        $template = $this->contractTemplateManager->create(new ContractTemplateInput('Contrat mensuel', ContractTemplateKindEnum::Body));
        $draftVersion = $template->getDraft();

        $this->contractTemplateManager->updateDraft($draftVersion, new ContractTemplateVersionInput([
            'fr' => ['title' => 'CONTRAT', 'content' => ['blocks' => []]],
        ]));

        // Deliberately not published. The refusal now lands at draft creation,
        // on the picker that offered the template, rather than at freeze - the
        // earlier the better, and the DTO cannot even name a version.
        try {
            $this->contractFor($this->customer(), $draftVersion);
            self::fail('A template with nothing published should have been refused.');
        } catch (FieldException $exception) {
            self::assertSame('bodyTemplateId', $exception->getField());
            self::assertStringContainsString('Contrat mensuel', $exception->getMessage());
        }
    }

    /**
     * A token nobody will fill would reach the signer as literal braces.
     */
    /**
     * The title is plain text, escaped like everything else before it enters
     * the sealed HTML, which the signing page and the PDF print raw.
     */
    public function testTheTitleIsEscapedInTheSealedDocument(): void
    {
        $contract = $this->draft(title: 'Conditions <générales> & tarifs');

        $this->contractManager->freeze($contract);

        self::assertStringContainsString('Conditions &lt;générales&gt; &amp; tarifs', (string) $contract->getRenderedHtml());
    }

    /**
     * A refused seal draws no number.
     *
     * The sequence is committed as soon as it moves, so the checks have to
     * pass before it does: a seal refused for an unknown variable used to
     * consume CTR-2026-0001, and the next contract became 0002.
     */
    public function testARefusedFreezeConsumesNoReference(): void
    {
        $before = $this->draft();
        $this->contractManager->freeze($before);

        $refused = $this->draft(body: [['type' => 'paragraph', 'data' => ['text' => 'SIRET {{client.siret}}']]], customer: $before->getCustomer());

        try {
            $this->contractManager->freeze($refused);
            self::fail('An unknown token should have refused the freeze.');
        } catch (FieldException) {
        }

        self::assertNull($refused->getReference());

        $after = $this->draft(customer: $before->getCustomer());
        $this->contractManager->freeze($after);

        // Consecutive: the refusal in between took nothing.
        self::assertSame($this->sequenceOf($before) + 1, $this->sequenceOf($after));
    }

    /** Refused on the language picker, not later at the seal. */
    public function testALanguageTheTrameIsNotWrittenInIsRefusedAtCreation(): void
    {
        $contract = $this->draft();

        try {
            $this->contractManager->create(new ContractInput(
                customerId: $contract->getCustomer()->getId(),
                bodyTemplateId: $contract->getBodyVersion()?->getTemplate()->getId(),
                locale: 'es',
            ));
            self::fail('A language the trame is not written in should have been refused.');
        } catch (FieldException $exception) {
            self::assertSame('locale', $exception->getField());
        }
    }

    /** PHP rolls an impossible date over; the contract must not. */
    public function testAnImpossibleEffectiveDateIsRefused(): void
    {
        $contract = $this->draft();

        try {
            $this->contractManager->create(new ContractInput(
                customerId: $contract->getCustomer()->getId(),
                bodyTemplateId: $contract->getBodyVersion()?->getTemplate()->getId(),
                locale: 'fr',
                effectiveDate: '2026-13-45',
            ));
            self::fail('The 45th of the 13th month should have been refused.');
        } catch (FieldException $exception) {
            self::assertSame('effectiveDate', $exception->getField());
        }
    }

    public function testAnUnknownTokenRefusesTheFreezeAndNamesItself(): void
    {
        $contract = $this->draft(body: [
            ['type' => 'paragraph', 'data' => ['text' => 'SIRET {{client.siret}}']],
        ]);

        try {
            $this->contractManager->freeze($contract);
            self::fail('An unknown token should have refused the freeze.');
        } catch (FieldException $exception) {
            self::assertStringContainsString('client.siret', $exception->getMessage());
        }

        self::assertFalse($contract->isFrozen());
    }

    /**
     * A block the renderer cannot produce would silently drop a clause.
     */
    public function testAnUnrenderableBlockRefusesTheFreeze(): void
    {
        $contract = $this->draft(body: [
            ['type' => 'paragraph', 'data' => ['text' => 'Article 1']],
            ['type' => 'image', 'data' => ['file' => ['url' => '/x.png']]],
        ]);

        try {
            $this->contractManager->freeze($contract);
            self::fail('An unrenderable block should have refused the freeze.');
        } catch (FieldException $exception) {
            self::assertStringContainsString('image', $exception->getMessage());
        }

        self::assertFalse($contract->isFrozen());
    }

    public function testTamperingWithAStoredDocumentIsDetected(): void
    {
        $contract = $this->draft();
        $this->contractManager->freeze($contract);

        self::assertTrue($this->seal->verify($contract));

        // What a bad migration or a manual database edit would do. Written
        // straight through SQL because the entity itself refuses it, which is
        // the point: the guard stops the application, the hash catches
        // everything else.
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE core_contracts SET rendered_html = :html WHERE id = :id',
            ['html' => '<section><h1>CONTRAT</h1><p>Le forfait est de 1 €.</p></section>', 'id' => $contract->getId()],
        );

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Contract::class, $contract->getId());

        self::assertInstanceOf(ContractInterface::class, $reloaded);
        self::assertFalse($this->seal->verify($reloaded));
    }

    /**
     * A trame a frozen contract came from is not deleted.
     *
     * The document itself would survive it: a contract carries its own sealed
     * copy and reads nothing back from the trame. What would not survive is
     * the answer to "which version of our terms did they sign", since the
     * foreign key is `SET NULL` and the trail would simply be gone. So the
     * manager refuses, and archiving is the way to put a trame aside.
     *
     * This test used to assert the opposite, and the reasoning it recorded was
     * sound as far as it went: nothing of the wording is lost. The rule
     * changed because a record is more than its wording.
     */
    public function testATrameAFrozenContractCameFromCannotBeDeleted(): void
    {
        $contract = $this->draft();
        $this->contractManager->freeze($contract);

        $template = $contract->getBodyVersion()?->getTemplate();
        self::assertNotNull($template);

        try {
            $this->contractTemplateManager->delete($template);
            self::fail('Deleting a trame a frozen contract came from should be refused.');
        } catch (FieldException $refusal) {
            self::assertStringContainsString('1', $refusal->getMessage(), 'the refusal counts what it is protecting');
        }

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Contract::class, $contract->getId());

        self::assertInstanceOf(ContractInterface::class, $reloaded);
        // The trail is intact, which is the whole point of the refusal, and so
        // is the document.
        self::assertNotNull($reloaded->getBodyVersion());
        self::assertTrue($this->seal->verify($reloaded));
    }

    /**
     * A trame nothing went out from is still deletable.
     *
     * The rule is about records, not about trames: one written, tried and
     * abandoned before anything was sent is nobody's evidence, and holding it
     * forever would turn the refusal into clutter.
     */
    public function testATrameNoFrozenContractCameFromIsStillDeleted(): void
    {
        $template = $this->contractTemplateManager->create(new ContractTemplateInput('Trame jamais utilisée', ContractTemplateKindEnum::Body));
        $id = $template->getId();

        $this->contractTemplateManager->delete($template);

        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(ContractTemplate::class, $id));
    }

    /**
     * The preview reads the real contract and mints nothing.
     *
     * The screen exists so somebody can proofread before the point of no
     * return, so the two things worth asserting are that the values are this
     * contract's own, and that looking at it leaves no trace: no reference, no
     * status change, nothing the sequence has to explain.
     */
    public function testThePreviewShowsTheRealValuesAndSealsNothing(): void
    {
        $contract = $this->draft([
            ['type' => 'paragraph', 'data' => ['text' => 'Conclu avec {{customer.legal_name}} pour {{contract.amount}}.']],
            ['type' => 'paragraph', 'data' => ['text' => 'Référence {{contract.reference}}.']],
        ]);

        $preview = $this->contractManager->preview($contract);

        self::assertStringContainsString('Boulangerie Durand', $preview['html']);
        self::assertStringContainsString('850', $preview['html']);
        self::assertSame([], $preview['unknownTokens']);

        // Not yet minted, so it reads as a slot rather than as a hole.
        self::assertStringContainsString('[reference]', $preview['html']);

        // And nothing happened to the contract.
        self::assertNull($contract->getReference());
        self::assertSame(ContractStatusEnum::Draft, $contract->getStatus());
    }

    /**
     * A token nobody will ever fill is named rather than thrown.
     *
     * The freeze refuses on these because it is about to seal them. Here,
     * saying which ones is the whole service: finding them costs nothing now
     * and costs a trip back through the form at the freeze.
     */
    public function testThePreviewNamesTheTokensThatWouldStopTheSeal(): void
    {
        $contract = $this->draft([
            ['type' => 'paragraph', 'data' => ['text' => 'Objet : {{contract.objet_invente}}.']],
        ]);

        $preview = $this->contractManager->preview($contract);

        self::assertSame(['contract.objet_invente'], $preview['unknownTokens']);

        // Reported, not thrown: the document still comes back so the rest of
        // it can be read.
        self::assertStringContainsString('Objet', $preview['html']);
    }

    /** @param list<array<string, mixed>>|null $body */
    private function draft(?array $body = null, string $title = 'CONTRAT DE PRESTATION DE SERVICES', ?CustomerInterface $customer = null): ContractInterface
    {
        $template = $this->contractTemplateManager->create(new ContractTemplateInput('Contrat mensuel', ContractTemplateKindEnum::Body));
        $version = $template->getDraft();

        $this->contractTemplateManager->updateDraft($version, new ContractTemplateVersionInput([
            'fr' => [
                'title' => $title,
                'content' => ['blocks' => $body ?? [
                    ['type' => 'header', 'data' => ['text' => 'ARTICLE 1', 'level' => 2]],
                    ['type' => 'paragraph', 'data' => ['text' => 'Le forfait mensuel est de {{contract.amount}}.']],
                ]],
            ],
        ]));

        // A wording written by the test is published as it stands, around the
        // manager's own check: publishing now refuses an unknown variable or
        // a block a contract cannot print, and these tests are about the
        // freeze still refusing them in a version published before that rule.
        if (null === $body) {
            $this->contractTemplateManager->publish($version);
        } else {
            $version->publish(new DateTimeImmutable());
            $this->entityManager->flush();
        }

        return $this->contractFor($customer ?? $this->customer(), $version);
    }

    /**
     * Through the DTO, like the controller does.
     *
     * The template is named rather than the version: the manager resolves the
     * version published today and pins it, which is the behaviour worth
     * exercising here.
     */
    /** The last group of digits of a reference: 12 for CTR-2026-0012. */
    private function sequenceOf(ContractInterface $contract): int
    {
        self::assertSame(1, preg_match('/(\d+)$/', (string) $contract->getReference(), $match));

        return (int) $match[1];
    }

    private function contractFor(CustomerInterface $customer, ContractTemplateVersionInterface $version): ContractInterface
    {
        return $this->contractManager->create(new ContractInput(
            customerId: $customer->getId(),
            bodyTemplateId: $version->getTemplate()->getId(),
            locale: 'fr',
            amountCents: 85000,
            effectiveDate: '2026-10-01',
        ));
    }

    private function customer(): CustomerInterface
    {
        $customer = new Customer();
        $customer
            ->setLegalName('Boulangerie Durand')
            ->setSiret('73282932000074')
            ->setContractualEmail('contact@durand.test')
            ->setRepresentativeFirstName('Camille')
            ->setRepresentativeLastName('Durand');

        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        return $customer;
    }
}
