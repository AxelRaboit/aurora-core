<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Contract;

use Aurora\Core\Content\BlockHtmlSanitizer;
use Aurora\Core\Sequence\SequenceGenerator;
use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Studio\Contract\Access\Entity\ContractAccessLink;
use Aurora\Module\Studio\Contract\Access\Manager\ContractAccessLinkManagerInterface;
use Aurora\Module\Studio\Contract\Dto\ContractInput;
use Aurora\Module\Studio\Contract\Dto\ContractTemplateInput;
use Aurora\Module\Studio\Contract\Dto\ContractTemplateVersionInput;
use Aurora\Module\Studio\Contract\Entity\Contract;
use Aurora\Module\Studio\Contract\Entity\ContractInterface;
use Aurora\Module\Studio\Contract\Entity\ContractTemplate;
use Aurora\Module\Studio\Contract\Enum\ContractStatusEnum;
use Aurora\Module\Studio\Contract\Enum\ContractTemplateKindEnum;
use Aurora\Module\Studio\Contract\Manager\ContractManager;
use Aurora\Module\Studio\Contract\Manager\ContractTemplateManager;
use Aurora\Module\Studio\Contract\Refusal\Dto\ContractRefusalInput;
use Aurora\Module\Studio\Contract\Refusal\Manager\ContractRefusalManagerInterface;
use Aurora\Module\Studio\Contract\Repository\ContractRepository;
use Aurora\Module\Studio\Contract\Repository\ContractTemplateRepository;
use Aurora\Module\Studio\Contract\Repository\ContractTemplateVersionRepository;
use Aurora\Module\Studio\Contract\Service\ContractCanonicalizer;
use Aurora\Module\Studio\Contract\Service\ContractCustomFieldScanner;
use Aurora\Module\Studio\Contract\Service\ContractDocumentRenderer;
use Aurora\Module\Studio\Contract\Service\ContractPrivacyNotice;
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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

use function sprintf;

/**
 * The three answers a sent contract can get, and how long the answer is kept.
 *
 * A refusal, a reminder and a retention look unrelated until you notice they
 * are the same subject: what happens to a document after it leaves, and what
 * the application is allowed to forget.
 *
 * What each test pins down is the refusal rather than the happy path, because
 * the happy paths here are one line each and the refusals are the design:
 * nobody undoes an engagement by clicking a page, a reminder never chases a
 * contract that has answered, and evidence cannot be deleted while it is still
 * required.
 */
final class ContractAnswerAndRetentionTest extends IntegrationTestCase
{
    private ContractManager $contracts;

    private ContractTemplateManager $templates;

    private ContractRefusalManagerInterface $refusals;

    private ContractAccessLinkManagerInterface $links;

    private ContractRepository $repository;

    private EntityManagerInterface $entityManager;

    private SettingRepository $settings;

    private ?CustomerInterface $customer = null;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->settings = $container->get(SettingRepository::class);
        $this->refusals = $container->get(ContractRefusalManagerInterface::class);
        $this->links = $container->get(ContractAccessLinkManagerInterface::class);
        $this->repository = $container->get(ContractRepository::class);

        $canonicalizer = new ContractCanonicalizer();

        $this->templates = new ContractTemplateManager(
            $this->entityManager,
            $container->get(AuditLogger::class),
            $container->get(ContractTemplateVersionRepository::class),
            $container->get(TranslatorInterface::class),
            $container->get(ContractRepository::class),
        );

        $this->contracts = new ContractManager(
            $this->entityManager,
            $container->get(AuditLogger::class),
            new ContractVariableResolver(new ContractVariableCatalogue(), $this->settings),
            new ContractDocumentRenderer(new BlockHtmlSanitizer()),
            $canonicalizer,
            new ContractSeal($canonicalizer),
            $container->get(SequenceGenerator::class),
            $this->settings,
            $container->get(CustomerRepository::class),
            $container->get(ContractTemplateRepository::class),
            $container->get(TranslatorInterface::class),
            new ContractCustomFieldScanner(),
            new ContractRetentionPolicy($this->settings),
            $container->get(ContractRepository::class),
        );
    }

    protected function tearDown(): void
    {
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', ContractAccessLink::class))->execute();
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', Contract::class))->execute();
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', ContractTemplate::class))->execute();
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', Customer::class))->execute();

        parent::tearDown();
    }

    public function testARefusalIsRecordedWithItsTraceAndItsReason(): void
    {
        $contract = $this->sentContract();
        $link = $this->links->send($contract);

        $this->refusals->refuseAsCustomer(
            $link,
            new ContractRefusalInput('Le budget ne passe pas cette année.'),
            $this->requestFrom('203.0.113.7', 'Mozilla/5.0 (Test)'),
        );

        self::assertSame(ContractStatusEnum::Refused, $contract->getStatus());
        self::assertTrue($contract->isRefused());
        self::assertSame('Le budget ne passe pas cette année.', $contract->getRefusalReason());
        self::assertSame('203.0.113.7', $contract->getRefusedFromIp());
        self::assertSame('Mozilla/5.0 (Test)', $contract->getRefusedUserAgent());
    }

    /** No justification is owed, and an empty one is stored as none. */
    public function testARefusalNeedsNoReason(): void
    {
        $contract = $this->sentContract();
        $link = $this->links->send($contract);

        $this->refusals->refuseAsCustomer($link, new ContractRefusalInput(''), $this->requestFrom());

        self::assertTrue($contract->isRefused());
        self::assertNull($contract->getRefusalReason());
    }

    /** The page the customer answered on keeps working, so they can see it landed. */
    public function testTheAddressStaysUsableAfterARefusal(): void
    {
        $contract = $this->sentContract();
        $link = $this->links->send($contract);

        $this->refusals->refuseAsCustomer($link, new ContractRefusalInput(''), $this->requestFrom());

        self::assertFalse($link->isRevoked());
    }

    public function testRefusingTwiceIsRefused(): void
    {
        $contract = $this->sentContract();
        $link = $this->links->send($contract);

        $this->refusals->refuseAsCustomer($link, new ContractRefusalInput(''), $this->requestFrom());

        $this->expectException(FieldException::class);

        $this->refusals->refuseAsCustomer($link, new ContractRefusalInput(''), $this->requestFrom());
    }

    /**
     * The reversal, and the only party who has it.
     *
     * A refusal taken by mistake costs a resend rather than a rebuild, and the
     * current state is then "sent" again - the refusal survives in the audit
     * trail, which is where the history of an answer belongs.
     */
    public function testSendingAgainClearsTheRefusal(): void
    {
        $contract = $this->sentContract();
        $link = $this->links->send($contract);

        $this->refusals->refuseAsCustomer($link, new ContractRefusalInput('Erreur de ma part.'), $this->requestFrom());

        $this->links->send($contract);

        self::assertFalse($contract->isRefused());
        self::assertNull($contract->getRefusalReason());
        self::assertSame(ContractStatusEnum::Sent, $contract->getStatus());
    }

    public function testADraftCannotBeRefused(): void
    {
        $contract = $this->draftContract();
        // A link cannot exist without a seal, so the refusal is reached with a
        // link belonging to another, sealed contract: the guard has to read the
        // contract's own state rather than trust that a link implies a seal.
        $sealed = $this->sentContract();
        $link = $this->links->send($sealed);
        $link->setContract($contract);

        $this->expectException(FieldException::class);

        $this->refusals->refuseAsCustomer($link, new ContractRefusalInput(''), $this->requestFrom());
    }

    /**
     * A reminder is a resend, so the previous address dies with it.
     *
     * The application stores only a hash of the token it handed out, so it
     * cannot rebuild the address it sent last week: a reminder that pointed at
     * the old link would be a link this code is unable to produce.
     */
    public function testAReminderMintsANewAddressAndRevokesTheOldOne(): void
    {
        $contract = $this->sentContract();
        $first = $this->links->send($contract);

        $second = $this->links->remind($contract);

        self::assertNotSame($first->getSelector(), $second->getSelector());
        self::assertTrue($first->isRevoked());
        self::assertFalse($second->isRevoked());
        self::assertSame(1, $contract->getReminderCount());
        self::assertInstanceOf(DateTimeImmutable::class, $contract->getLastReminderAt());
    }

    public function testAReminderIsRefusedOnceSomebodyHasSigned(): void
    {
        $contract = $this->sentContract();
        $this->links->send($contract);
        $contract->setStatus(ContractStatusEnum::SignedByCustomer);
        $this->entityManager->flush();

        $this->expectException(FieldException::class);

        $this->links->remind($contract);
    }

    /**
     * What the scheduled job selects, and what it leaves alone.
     *
     * The query is the gate that matters: a contract that answered must not be
     * chased, and the loop is not the place to remember that.
     */
    public function testOnlyWaitingContractsAreDueForAReminder(): void
    {
        $waiting = $this->sentContract();
        $this->links->send($waiting);

        $refused = $this->sentContract();
        $refusedLink = $this->links->send($refused);
        $this->refusals->refuseAsCustomer($refusedLink, new ContractRefusalInput(''), $this->requestFrom());

        // Everything that was sent counts as due, since the window is open.
        $due = $this->repository->findDueForReminder(new DateTimeImmutable('+1 day'), 2);
        $ids = array_map(static fn (ContractInterface $each): ?int => $each->getId(), $due);

        self::assertContains($waiting->getId(), $ids);
        self::assertNotContains($refused->getId(), $ids);
    }

    public function testTheCeilingStopsTheChasing(): void
    {
        $contract = $this->sentContract();
        $this->links->send($contract);

        $this->links->remind($contract);
        $this->links->remind($contract);

        $due = $this->repository->findDueForReminder(new DateTimeImmutable('+1 day'), 2);
        $ids = array_map(static fn (ContractInterface $each): ?int => $each->getId(), $due);

        self::assertNotContains($contract->getId(), $ids);
    }

    /** A draft is not evidence of anything, so it goes freely. */
    public function testADraftIsDeletedFreely(): void
    {
        $contract = $this->draftContract();
        $id = $contract->getId();

        $this->contracts->delete($contract);

        self::assertNull($this->repository->find($id));
    }

    public function testASealedContractIsKeptForTheRetentionAndSaysUntilWhen(): void
    {
        $this->settings->set(ApplicationParameterEnum::StudioContractRetentionYears->value, '10');

        $contract = $this->sentContract();

        $this->expectException(FieldException::class);

        try {
            $this->contracts->delete($contract);
        } catch (FieldException $fieldException) {
            // The date is in the message: "not yet" alone leaves the reader
            // guessing whether they are a day or a decade early.
            $until = $contract->retainedUntil(10);
            self::assertInstanceOf(DateTimeImmutable::class, $until);
            self::assertStringContainsString($until->format('d/m/Y'), $fieldException->getMessage());

            throw $fieldException;
        }
    }

    /**
     * The floor, because a field emptied by hand must not become permission to
     * destroy signed contracts.
     */
    public function testTheRetentionNeverFallsBelowFiveYears(): void
    {
        $policy = new ContractRetentionPolicy($this->settings);

        $this->settings->set(ApplicationParameterEnum::StudioContractRetentionYears->value, '0');
        self::assertSame(ContractRetentionPolicy::MINIMUM_YEARS, $policy->years());

        $this->settings->set(ApplicationParameterEnum::StudioContractRetentionYears->value, '-4');
        self::assertSame(ContractRetentionPolicy::MINIMUM_YEARS, $policy->years());

        $this->settings->set(ApplicationParameterEnum::StudioContractRetentionYears->value, '12');
        self::assertSame(12, $policy->years());
    }

    /** Once the retention has run out, the deletion becomes possible. */
    public function testASealedContractIsDeletableOnceTheRetentionHasElapsed(): void
    {
        $this->settings->set(ApplicationParameterEnum::StudioContractRetentionYears->value, '5');

        $contract = $this->sentContract();
        $id = $contract->getId();

        // Sealed six years ago: the freeze date is what the retention counts
        // from, and it is the one date that cannot move afterwards.
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE core_contracts SET frozen_at = :frozenAt WHERE id = :id',
            ['frozenAt' => (new DateTimeImmutable('-6 years'))->format('Y-m-d H:i:s'), 'id' => $id],
        );
        $this->entityManager->clear();

        $reloaded = $this->repository->find($id);
        self::assertInstanceOf(ContractInterface::class, $reloaded);

        $this->contracts->delete($reloaded);

        self::assertNull($this->repository->find($id));
    }

    /**
     * The notice cannot quote a retention nobody applies.
     *
     * This is the whole point of building it from the settings: a written
     * promise contradicted by the code beside it is worse than no notice at
     * all, and prose drifts silently where a shared read cannot.
     */
    public function testThePrivacyNoticeStatesTheRetentionThatIsEnforced(): void
    {
        $this->settings->set(ApplicationParameterEnum::StudioContractRetentionYears->value, '7');

        $notice = new ContractPrivacyNotice($this->settings, new ContractRetentionPolicy($this->settings));
        $contract = $this->draftContract();

        self::assertSame(7, $notice->forContract($contract)['retentionYears']);
        self::assertSame(
            $notice->forContract($contract)['retentionYears'],
            (new ContractRetentionPolicy($this->settings))->years(),
        );
    }

    /** It is read by the person the document was addressed to, in its language. */
    public function testThePrivacyNoticeCarriesTheContractLocale(): void
    {
        $notice = new ContractPrivacyNotice($this->settings, new ContractRetentionPolicy($this->settings));

        self::assertSame('fr', $notice->forContract($this->draftContract())['locale']);
    }

    /** An unfilled setting is skipped, not printed as a blank line. */
    public function testAnUnsetProviderSettingLeavesTheNoticeReadable(): void
    {
        foreach ([
            ApplicationParameterEnum::StudioProviderName,
            ApplicationParameterEnum::StudioProviderAddress,
            ApplicationParameterEnum::StudioProviderEmail,
        ] as $parameter) {
            $this->settings->set($parameter->value, '');
        }

        $notice = new ContractPrivacyNotice($this->settings, new ContractRetentionPolicy($this->settings));
        $built = $notice->forContract($this->draftContract());

        self::assertSame([], $built['controller']);
        self::assertSame('', $built['email']);
        // The part that does not depend on the settings still stands.
        self::assertGreaterThanOrEqual(ContractRetentionPolicy::MINIMUM_YEARS, $built['retentionYears']);
    }

    private function requestFrom(string $ip = '198.51.100.4', string $userAgent = 'Test'): Request
    {
        return new Request(server: ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => $userAgent]);
    }

    private function sentContract(): ContractInterface
    {
        $contract = $this->draftContract();
        $this->contracts->freeze($contract);

        return $contract;
    }

    private function draftContract(): ContractInterface
    {
        $template = $this->templates->create(new ContractTemplateInput('Contrat mensuel', ContractTemplateKindEnum::Body));
        $version = $template->getDraft();

        self::assertNotNull($version);

        $this->templates->updateDraft($version, new ContractTemplateVersionInput([
            'fr' => [
                'title' => 'CONTRAT DE PRESTATION DE SERVICES',
                'content' => ['blocks' => [
                    ['type' => 'paragraph', 'data' => ['text' => 'Le forfait est payable d\'avance.']],
                ]],
            ],
        ]));

        $this->templates->publish($version);

        return $this->contracts->create(new ContractInput(
            customerId: $this->customer()->getId(),
            bodyTemplateId: $template->getId(),
            locale: 'fr',
        ));
    }

    /**
     * One customer per test, reused.
     *
     * The SIRET is unique in the schema, so a helper that made a new one per
     * contract failed the moment a test needed two contracts - which several
     * here do.
     */
    private function customer(): CustomerInterface
    {
        if ($this->customer instanceof CustomerInterface) {
            return $this->customer;
        }

        $customer = new Customer();
        $customer
            ->setLegalName('Boulangerie Durand')
            ->setSiret('73282932000074')
            ->setContractualEmail('contact@durand.test')
            ->setRepresentativeFirstName('Camille')
            ->setRepresentativeLastName('Durand');

        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        return $this->customer = $customer;
    }
}
