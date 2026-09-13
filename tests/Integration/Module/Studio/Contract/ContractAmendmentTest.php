<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Contract;

use Aurora\Core\Content\BlockHtmlSanitizer;
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
use Aurora\Module\Studio\Contract\Enum\ContractStatusEnum;
use Aurora\Module\Studio\Contract\Enum\ContractTemplateKindEnum;
use Aurora\Module\Studio\Contract\Enum\ContractTerminationOriginEnum;
use Aurora\Module\Studio\Contract\Manager\ContractManager;
use Aurora\Module\Studio\Contract\Manager\ContractTemplateManager;
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
use Aurora\Module\Studio\Contract\Termination\Dto\ContractTerminationInput;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\Customer\Repository\CustomerRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function sprintf;

/**
 * Amending a signed contract, and ending a relationship.
 *
 * The design question these pin down is the one that decides everything else:
 * an amendment is a **document of its own**, not a new state on the one it
 * changes. It has to be, because a sealed contract is immutable - so "modify
 * the annex of a signed contract" has no implementation that is not a lie.
 * What the tests prove is that the original never moves: its reference, its
 * hash and its sealed HTML are the same before and after.
 *
 * Termination is the opposite shape: a fact, not a document. A concluded
 * contract stays concluded, because it was signed and that does not expire,
 * and what ends is the relationship - with two dates, since a notice period is
 * exactly the gap between them.
 */
final class ContractAmendmentTest extends IntegrationTestCase
{
    private ContractManager $contracts;

    private ContractTemplateManager $templates;

    private ContractRepository $repository;

    private EntityManagerInterface $entityManager;

    private ?CustomerInterface $customer = null;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->repository = $container->get(ContractRepository::class);
        $settings = $container->get(SettingRepository::class);

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
            new ContractVariableResolver(new ContractVariableCatalogue(), $settings),
            new ContractDocumentRenderer(new BlockHtmlSanitizer()),
            $canonicalizer,
            new ContractSeal($canonicalizer),
            $container->get(SequenceGenerator::class),
            $settings,
            $container->get(CustomerRepository::class),
            $container->get(ContractTemplateRepository::class),
            $container->get(TranslatorInterface::class),
            new ContractCustomFieldScanner(),
            new ContractRetentionPolicy($settings),
            $this->repository,
        );
    }

    protected function tearDown(): void
    {
        // Amendments point at their parent, so they go first: the other order
        // trips the foreign key rather than the cascade.
        $this->entityManager->createQuery(sprintf('UPDATE %s c SET c.amends = NULL', Contract::class))->execute();
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', Contract::class))->execute();
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', ContractTemplate::class))->execute();
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', Customer::class))->execute();

        parent::tearDown();
    }

    /**
     * The property the whole design rests on: the original does not move.
     *
     * If this ever fails, an amendment has become an edit, and every signature
     * taken on the parent has become a signature on a document that changed
     * afterwards.
     */
    public function testAnAmendmentLeavesTheOriginalUntouched(): void
    {
        $original = $this->concludedContract();

        $reference = $original->getReference();
        $hash = $original->getContentHash();
        $html = $original->getRenderedHtml();

        $amendment = $this->amendmentOf($original);
        $this->contracts->freeze($amendment);

        self::assertSame($reference, $original->getReference());
        self::assertSame($hash, $original->getContentHash());
        self::assertSame($html, $original->getRenderedHtml());
        // And the parent is still concluded: an amendment is not an event in
        // the parent's life cycle.
        self::assertSame(ContractStatusEnum::Countersigned, $original->getStatus());
    }

    /**
     * The reference carries the parentage, so a line in an export says what it
     * belongs to without a join.
     */
    public function testTheAmendmentReferenceIsDerivedFromItsParent(): void
    {
        $original = $this->concludedContract();

        $first = $this->amendmentOf($original);
        $this->contracts->freeze($first);

        $second = $this->amendmentOf($original);
        $this->contracts->freeze($second);

        self::assertSame($original->getReference().'-A1', $first->getReference());
        self::assertSame($original->getReference().'-A2', $second->getReference());
        self::assertSame(1, $first->getAmendmentRank());
        self::assertSame(2, $second->getAmendmentRank());
    }

    /** A draft that never went anywhere consumes no rank. */
    public function testAnAbandonedDraftDoesNotConsumeARank(): void
    {
        $original = $this->concludedContract();

        $abandoned = $this->amendmentOf($original);
        $this->contracts->delete($abandoned);

        $kept = $this->amendmentOf($original);
        $this->contracts->freeze($kept);

        self::assertSame($original->getReference().'-A1', $kept->getReference());
    }

    /** You do not amend a document nobody signed. */
    public function testOnlyAConcludedContractCanBeAmended(): void
    {
        $draft = $this->draftContract();

        $this->expectException(FieldException::class);

        $this->amendmentOf($draft);
    }

    /** Practice numbers every amendment against the original, not in a chain. */
    public function testAnAmendmentCannotItselfBeAmended(): void
    {
        $original = $this->concludedContract();
        $amendment = $this->amendmentOf($original);
        $this->contracts->freeze($amendment);
        $amendment->setStatus(ContractStatusEnum::Countersigned);
        $this->entityManager->flush();

        $this->expectException(FieldException::class);

        $this->amendmentOf($amendment);
    }

    public function testAnAmendmentCannotChangeTheCustomer(): void
    {
        $original = $this->concludedContract();

        $other = new Customer();
        $other
            ->setLegalName('Autre société')
            ->setSiret('90451233600028')
            ->setContractualEmail('autre@durand.test')
            ->setRepresentativeFirstName('Alex')
            ->setRepresentativeLastName('Autre');
        $this->entityManager->persist($other);
        $this->entityManager->flush();

        $this->expectException(FieldException::class);

        $this->contracts->create(new ContractInput(
            customerId: $other->getId(),
            bodyTemplateId: $original->getBodyVersion()?->getTemplate()->getId(),
            locale: 'fr',
            amendsId: $original->getId(),
        ));
    }

    /**
     * The token guard: an avenant trame sealed as a standalone contract would
     * print a blank where it names the document it modifies.
     */
    public function testAWordingThatNamesAParentRefusesToSealWithoutOne(): void
    {
        $template = $this->publishedTemplate('Le présent avenant modifie le contrat {{contract.amends_reference}}.');

        $standalone = $this->contracts->create(new ContractInput(
            customerId: $this->customer()->getId(),
            bodyTemplateId: $template,
            locale: 'fr',
        ));

        $this->expectException(FieldException::class);

        try {
            $this->contracts->freeze($standalone);
        } catch (FieldException $fieldException) {
            self::assertStringContainsString('contract.amends_reference', $fieldException->getMessage());
            // Nothing consumed: no reference minted on a refused freeze.
            self::assertNull($standalone->getReference());

            throw $fieldException;
        }
    }

    /** The same wording on a real amendment prints the parent's reference. */
    public function testTheParentReferenceIsSubstitutedIntoTheAmendment(): void
    {
        $original = $this->concludedContract();
        $template = $this->publishedTemplate('Le présent avenant modifie le contrat {{contract.amends_reference}}.');

        $amendment = $this->contracts->create(new ContractInput(
            customerId: $this->customer()->getId(),
            bodyTemplateId: $template,
            locale: 'fr',
            amendsId: $original->getId(),
        ));

        $this->contracts->freeze($amendment);

        self::assertStringContainsString(
            sprintf('modifie le contrat %s', (string) $original->getReference()),
            (string) $amendment->getRenderedHtml(),
        );
    }

    public function testTheChainIsReadableFromTheParent(): void
    {
        $original = $this->concludedContract();

        $first = $this->amendmentOf($original);
        $this->contracts->freeze($first);

        self::assertCount(1, $this->repository->findAmendmentsOf($original));
        self::assertSame(1, $this->repository->countSealedAmendmentsOf($original));
    }

    public function testTerminationRecordsBothDatesAndItsOrigin(): void
    {
        $contract = $this->concludedContract();

        $this->contracts->terminate($contract, new ContractTerminationInput(
            noticedAt: '2026-09-30',
            effectiveAt: '2026-10-31',
            origin: ContractTerminationOriginEnum::Customer->value,
            reason: 'Budget réaffecté.',
        ));

        self::assertTrue($contract->isTerminated());
        self::assertSame('2026-09-30', $contract->getTerminationNoticedAt()?->format('Y-m-d'));
        self::assertSame('2026-10-31', $contract->getTerminationEffectiveAt()?->format('Y-m-d'));
        self::assertSame(ContractTerminationOriginEnum::Customer, $contract->getTerminationOrigin());
        self::assertSame('Budget réaffecté.', $contract->getTerminationReason());
    }

    /**
     * The document stays signed. A termination is not a signature state, and
     * conflating the two would make a concluded contract stop being concluded
     * because the relationship ended.
     */
    public function testTerminationLeavesTheStatusAndTheSealAlone(): void
    {
        $contract = $this->concludedContract();
        $hash = $contract->getContentHash();

        $this->contracts->terminate($contract, new ContractTerminationInput(
            noticedAt: '2026-09-30',
            effectiveAt: '2026-10-31',
            origin: ContractTerminationOriginEnum::Mutual->value,
        ));

        self::assertSame(ContractStatusEnum::Countersigned, $contract->getStatus());
        self::assertSame($hash, $contract->getContentHash());
    }

    /** A notice period runs forward; the other order is a typo. */
    public function testAnEffectiveDateBeforeTheNoticeIsRefused(): void
    {
        $contract = $this->concludedContract();

        $this->expectException(FieldException::class);

        $this->contracts->terminate($contract, new ContractTerminationInput(
            noticedAt: '2026-10-31',
            effectiveAt: '2026-09-30',
            origin: ContractTerminationOriginEnum::Provider->value,
        ));
    }

    public function testOnlyAConcludedContractCanBeTerminated(): void
    {
        $draft = $this->draftContract();

        $this->expectException(FieldException::class);

        $this->contracts->terminate($draft, new ContractTerminationInput(
            noticedAt: '2026-09-30',
            effectiveAt: '2026-10-31',
            origin: ContractTerminationOriginEnum::Customer->value,
        ));
    }

    public function testTerminatingTwiceIsRefused(): void
    {
        $contract = $this->concludedContract();

        $input = new ContractTerminationInput(
            noticedAt: '2026-09-30',
            effectiveAt: '2026-10-31',
            origin: ContractTerminationOriginEnum::Customer->value,
        );

        $this->contracts->terminate($contract, $input);

        $this->expectException(FieldException::class);

        $this->contracts->terminate($contract, $input);
    }

    /** A terminated contract has nothing left to modify. */
    public function testATerminatedContractCannotBeAmended(): void
    {
        $contract = $this->concludedContract();

        $this->contracts->terminate($contract, new ContractTerminationInput(
            noticedAt: '2026-09-30',
            effectiveAt: '2026-10-31',
            origin: ContractTerminationOriginEnum::Customer->value,
        ));

        $this->expectException(FieldException::class);

        $this->amendmentOf($contract);
    }

    /**
     * Notice given is not the same screen as work stopped.
     *
     * A contract noticed today for the end of next month is still running, and
     * a list that showed it as over would be wrong for a month.
     */
    public function testANoticeInTheFutureIsNotYetEffective(): void
    {
        $contract = $this->concludedContract();

        $this->contracts->terminate($contract, new ContractTerminationInput(
            noticedAt: new DateTimeImmutable()->format('Y-m-d'),
            effectiveAt: new DateTimeImmutable('+30 days')->format('Y-m-d'),
            origin: ContractTerminationOriginEnum::Customer->value,
        ));

        self::assertTrue($contract->isTerminated());
        self::assertFalse($contract->isTerminationEffective());
    }

    private function amendmentOf(ContractInterface $parent): ContractInterface
    {
        return $this->contracts->create(new ContractInput(
            customerId: $parent->getCustomer()->getId(),
            bodyTemplateId: $parent->getBodyVersion()?->getTemplate()->getId(),
            locale: 'fr',
            amendsId: $parent->getId(),
        ));
    }

    private function concludedContract(): ContractInterface
    {
        $contract = $this->draftContract();
        $this->contracts->freeze($contract);
        $contract->setStatus(ContractStatusEnum::Countersigned);
        $this->entityManager->flush();

        return $contract;
    }

    private function draftContract(): ContractInterface
    {
        return $this->contracts->create(new ContractInput(
            customerId: $this->customer()->getId(),
            bodyTemplateId: $this->publishedTemplate("Le forfait est payable d'avance."),
            locale: 'fr',
        ));
    }

    /** @return int|null the template id, which is what the input takes */
    private function publishedTemplate(string $text): ?int
    {
        $template = $this->templates->create(new ContractTemplateInput('Contrat mensuel', ContractTemplateKindEnum::Body));
        $version = $template->getDraft();

        self::assertNotNull($version);

        $this->templates->updateDraft($version, new ContractTemplateVersionInput([
            'fr' => [
                'title' => 'CONTRAT DE PRESTATION DE SERVICES',
                'content' => ['blocks' => [
                    ['type' => 'paragraph', 'data' => ['text' => $text]],
                ]],
            ],
        ]));

        $this->templates->publish($version);

        return $template->getId();
    }

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
