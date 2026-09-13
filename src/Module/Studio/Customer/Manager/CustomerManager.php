<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Customer\Manager;

use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Contract\Repository\ContractRepository;
use Aurora\Module\Studio\Customer\Dto\CustomerInputInterface;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\Customer\Repository\CustomerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsAlias(CustomerManagerInterface::class)]
class CustomerManager implements CustomerManagerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly AuditLogger $auditLogger,
        protected readonly CustomerRepository $customerRepository,
        protected readonly ContractRepository $contractRepository,
        protected readonly UserRepository $userRepository,
        protected readonly TranslatorInterface $translator,
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
     * Deleting a customer is refused as soon as a contract names them.
     *
     * The database said the same thing already - the foreign key is
     * `RESTRICT` - but it said it as an SQL error in the middle of a request,
     * which reached the screen as a 500. The rule is an accounting rule, so it
     * is stated here, in the language of the person who clicked: a client with
     * contracts is not a record anybody deletes by hand.
     */
    public function delete(CustomerInterface $customer): void
    {
        $contracts = $this->contractRepository->countForCustomer($customer);
        if ($contracts > 0) {
            throw new FieldException('customer', $this->translator->trans('backend.studio.customers.errors.has_contracts', ['{count}' => (string) $contracts]));
        }

        $this->auditDeleted($customer);

        $this->entityManager->remove($customer);
        $this->entityManager->flush();
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

        $customer
            ->setLegalName($input->getLegalName())
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
            ->setPhone($input->getPhone())
            ->setUser($this->resolveUser($input));
    }

    /**
     * The account to attach, or null.
     *
     * An id that no longer resolves lands as null rather than as an error: the
     * picker is fed from the account list, so the only way to send an unknown
     * one is to have edited the payload.
     */
    protected function resolveUser(CustomerInputInterface $input): ?CoreUserInterface
    {
        $userId = $input->getUserId();

        return null === $userId ? null : $this->userRepository->find($userId);
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

        throw new FieldException('siret', $this->translator->trans('backend.studio.customers.errors.siret_taken', ['{name}' => $existing->getLegalName()]));
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
        ];
    }
}
