<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Customer\Manager;

use Aurora\Core\Locale\Service\LocaleContextInterface;
use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Studio\Contract\Repository\ContractRepository;
use Aurora\Module\Studio\Customer\Dto\CustomerInputInterface;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\Customer\Enum\CustomerStatusEnum;
use Aurora\Module\Studio\Customer\Repository\CustomerRepository;
use Aurora\Module\Studio\CustomerSpace\Repository\CustomerSpaceRepository;
use Aurora\Module\Studio\Pipeline\Manager\PipelineManagerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\Translation\TranslatorInterface;

use function in_array;
use function mb_strtolower;
use function mb_trim;

#[AsAlias(CustomerManagerInterface::class)]
class CustomerManager implements CustomerManagerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly AuditLogger $auditLogger,
        protected readonly CustomerRepository $customerRepository,
        protected readonly ContractRepository $contractRepository,
        protected readonly CustomerSpaceRepository $spaceRepository,
        protected readonly TranslatorInterface $translator,
        protected readonly PipelineManagerInterface $pipelineManager,
        protected readonly LocaleContextInterface $localeContext,
    ) {}

    public function create(CustomerInputInterface $input): CustomerInterface
    {
        $customer = $this->createCustomer();
        $this->applyInput($customer, $input);

        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $this->auditCreated($customer);

        return $customer;
    }

    public function update(CustomerInterface $customer, CustomerInputInterface $input): void
    {
        $this->applyInput($customer, $input);
        $this->entityManager->flush();

        $this->auditUpdated($customer);
    }

    /**
     * Deleting a customer is refused as soon as a contract or a space names them.
     *
     * The database said the same thing already - both foreign keys are
     * `RESTRICT` - but it said it as an SQL error in the middle of a request,
     * which reached the screen as a 500. The rules are business rules, so they
     * are stated here, in the language of the person who clicked: a client with
     * contracts is not a record anybody deletes by hand, and a client whose
     * work is still open somewhere is not one either.
     *
     * Contracts are checked first on purpose. Both refusals are true at once
     * often enough, and the contract is the one that cannot be undone by
     * tidying up: a space can be deleted, a signed contract cannot.
     */
    public function delete(CustomerInterface $customer): void
    {
        $contracts = $this->contractRepository->countForCustomer($customer);
        if ($contracts > 0) {
            throw new FieldException('customer', $this->translator->trans('suite.studio.customers.errors.has_contracts', ['{count}' => (string) $contracts, '%count%' => $contracts]));
        }

        $spaces = $this->spaceRepository->countForCustomer($customer);
        if ($spaces > 0) {
            throw new FieldException('customer', $this->translator->trans('suite.studio.customers.errors.has_spaces', ['{count}' => (string) $spaces, '%count%' => $spaces]));
        }

        $this->auditDeleted($customer);

        $this->entityManager->remove($customer);
        $this->entityManager->flush();
    }

    /**
     * A prospect becomes a customer, in one gesture.
     *
     * **An operation of its own rather than an ordinary update**, because that
     * is what it is: a sheet is not being edited, a company is being declared
     * committed. Going through `update` would have meant sending the company
     * name and everything else again to change one column, and would have
     * written an edit into the audit where something actually happened.
     *
     * The address is the only field accepted: it is the only one the status
     * requires. A sheet that already has one can therefore be converted
     * without entering anything.
     *
     * Converting is winning the deal, wherever it is done from: the client is
     * filed under the pipeline's won stage, so the board and the list never
     * disagree about what happened.
     */
    public function convertToClient(CustomerInterface $customer, ?string $contractualEmail): void
    {
        if (null !== $contractualEmail && '' !== $contractualEmail) {
            $customer->setContractualEmail(mb_strtolower(mb_trim($contractualEmail)));
        }

        $email = $customer->getContractualEmail();

        if (null === $email || '' === $email) {
            throw new FieldException('contractualEmail', $this->translator->trans('suite.studio.customers.errors.contractual_email_required'));
        }

        $customer->setStatus(CustomerStatusEnum::Client);
        $this->pipelineManager->fileAsWon($customer);
        $this->entityManager->flush();

        $this->auditLogger->log('studio', 'customer.converted', 'Customer', $customer->getId(), [
            'legalName' => $customer->getLegalName(),
        ]);
    }

    /**
     * A customer has an address, a prospect not necessarily.
     *
     * **It is the only thing the status requires**, and it covers the pair
     * rather than the field, so it is here and not in the DTO: the Manager is
     * the one that sees both. A prospect can be just a name - you meet them,
     * open a space, structure the work, and have nothing else. A customer, on
     * the other hand, is someone you send a contract to.
     *
     * Reported under the address field and not under the status: the address
     * is what is missing, and it is what the reader must fill in.
     */
    protected function assertClientHasAnAddress(CustomerInputInterface $input): void
    {
        if ($input->getStatus()->isProspect()) {
            return;
        }

        $email = $input->getContractualEmail();

        if (null === $email || '' === $email) {
            throw new FieldException('contractualEmail', $this->translator->trans('suite.studio.customers.errors.contractual_email_required'));
        }
    }

    /**
     * Only a language the site speaks: a mail cannot be written in one it
     * has no words for, and the translator would quietly fall back to
     * another.
     */
    protected function assertLocaleIsActive(?string $locale): void
    {
        if (null !== $locale && !in_array($locale, $this->localeContext->getActiveLocales(), true)) {
            throw new FieldException('locale', $this->translator->trans('suite.studio.customers.errors.locale_invalid'));
        }
    }

    /**
     * Instantiates the concrete entity. Override in a subclass to return a
     * client-substituted class - `resolve_target_entities` only affects
     * Doctrine relation resolution, not direct `new` calls.
     */
    protected function createCustomer(): CustomerInterface
    {
        return new Customer();
    }

    /**
     * Hydrates the entity from the input DTO. Override in a subclass and
     * call `parent::applyInput()` FIRST so the base fields stay populated,
     * then read your own extra fields off the input.
     */
    protected function applyInput(CustomerInterface $customer, CustomerInputInterface $input): void
    {
        $this->assertSiretIsFree($input->getSiret(), $customer->getId());
        $this->assertClientHasAnAddress($input);
        $this->assertLocaleIsActive($input->getLocale());

        // A prospect turned client from its sheet won the deal just as surely
        // as one converted from the list: same filing.
        $becomesClient = null !== $customer->getId() && $customer->isProspect() && !$input->getStatus()->isProspect();

        $customer
            ->setLegalName($input->getLegalName())
            ->setStatus($input->getStatus())
            ->setLegalForm($input->getLegalForm())
            ->setShareCapitalCents($input->getShareCapitalCents())
            ->setShareCapitalCurrency($input->getShareCapitalCurrency())
            ->setRegisteredOffice($input->getRegisteredOffice())
            ->setSiret($input->getSiret())
            ->setTradeRegister($input->getTradeRegister())
            ->setVatNumber($input->getVatNumber())
            ->setActivitySector($input->getActivitySector())
            ->setRepresentativeFirstName($input->getRepresentativeFirstName())
            ->setRepresentativeLastName($input->getRepresentativeLastName())
            ->setRepresentativeRole($input->getRepresentativeRole())
            ->setContractualEmail($input->getContractualEmail())
            ->setLocale($input->getLocale())
            ->setPhone($input->getPhone())
            ->setSiren($input->getSiren())
            ->setLandline($input->getLandline())
            ->setLinks($input->getLinks())
            ->setInformationNotes($input->getInformationNotes())
            ->setNextFollowUpOn($input->getNextFollowUpOn())
            ->setFollowUpNote($input->getFollowUpNote())
            ->setSource($input->getSource())
            ->setEstimatedValueCents($input->getEstimatedValueCents())
            ->setEstimatedValueCurrency($input->getEstimatedValueCurrency())
            ->setLostReason($input->getLostReason());

        if ($becomesClient) {
            $this->pipelineManager->fileAsWon($customer);
        }
    }

    /**
     * One company, one row.
     *
     * The column is unique, so this would fail anyway - as a driver exception
     * with a 500 attached. Checked here so the answer is a sentence under the
     * SIRET field naming the company that already holds it, which is the one
     * piece of information that makes the collision actionable.
     */
    protected function assertSiretIsFree(?string $siret, ?int $customerId): void
    {
        if (null === $siret) {
            return;
        }

        $existing = $this->customerRepository->findOneBySiret($siret);

        if (!$existing instanceof CustomerInterface || $existing->getId() === $customerId) {
            return;
        }

        throw new FieldException('siret', $this->translator->trans('suite.studio.customers.errors.siret_taken', ['{name}' => $existing->getLegalName()]));
    }

    protected function auditCreated(CustomerInterface $customer): void
    {
        $this->auditLogger->log('studio', 'customer.created', 'Customer', $customer->getId(), $this->auditPayload($customer));
    }

    protected function auditUpdated(CustomerInterface $customer): void
    {
        $this->auditLogger->log('studio', 'customer.updated', 'Customer', $customer->getId(), $this->auditPayload($customer));
    }

    protected function auditDeleted(CustomerInterface $customer): void
    {
        $this->auditLogger->log('studio', 'customer.deleted', 'Customer', $customer->getId(), $this->auditPayload($customer));
    }

    /**
     * Structured payload logged with every audit entry. Override to add
     * extra fields: `[...parent::auditPayload($customer), 'code' => $customer->getCode()]`.
     *
     * @return array<string, mixed>
     */
    protected function auditPayload(CustomerInterface $customer): array
    {
        return [
            'legalName' => $customer->getLegalName(),
            'siret' => $customer->getSiret(),
            'contractualEmail' => $customer->getContractualEmail(),
            'locale' => $customer->getLocale(),
        ];
    }
}
