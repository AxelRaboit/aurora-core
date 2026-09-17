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
use Aurora\Module\Studio\Customer\Enum\CustomerStatusEnum;
use Aurora\Module\Studio\Customer\Repository\CustomerRepository;
use Aurora\Module\Studio\CustomerSpace\Repository\CustomerSpaceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\Translation\TranslatorInterface;

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
            throw new FieldException('customer', $this->translator->trans('backend.studio.customers.errors.has_contracts', ['{count}' => (string) $contracts]));
        }

        $spaces = $this->spaceRepository->countForCustomer($customer);
        if ($spaces > 0) {
            throw new FieldException('customer', $this->translator->trans('backend.studio.customers.errors.has_spaces', ['{count}' => (string) $spaces]));
        }

        $this->auditDeleted($customer);

        $this->entityManager->remove($customer);
        $this->entityManager->flush();
    }

    /**
     * Un prospect devient client, en un geste.
     *
     * **Une operation a elle seule plutot qu'une mise a jour ordinaire**, parce
     * que c'est ce qu'elle est : on ne modifie pas une fiche, on dit qu'une
     * societe s'est engagee. Passer par `update` aurait demande de renvoyer la
     * raison sociale et tout le reste pour changer une colonne, et aurait ecrit
     * dans l'audit une modification la ou il s'est passe quelque chose.
     *
     * L'adresse est le seul champ accepte : c'est le seul que le statut impose.
     * Une fiche qui en a deja une peut donc etre convertie sans rien saisir.
     */
    public function convertToClient(CustomerInterface $customer, ?string $contractualEmail): void
    {
        if (null !== $contractualEmail && '' !== $contractualEmail) {
            $customer->setContractualEmail(mb_strtolower(mb_trim($contractualEmail)));
        }

        $email = $customer->getContractualEmail();

        if (null === $email || '' === $email) {
            throw new FieldException('contractualEmail', $this->translator->trans('backend.studio.customers.errors.contractual_email_required'));
        }

        $customer->setStatus(CustomerStatusEnum::Client);
        $this->entityManager->flush();

        $this->auditLogger->log('studio', 'customer.converted', 'Customer', $customer->getId(), [
            'legalName' => $customer->getLegalName(),
        ]);
    }

    /**
     * Un client a une adresse, un prospect pas forcement.
     *
     * **C'est la seule chose que le statut impose**, et elle porte sur la paire
     * plutot que sur le champ, donc elle est ici et pas dans le DTO : c'est le
     * Manager qui voit les deux. Un prospect peut n'etre qu'un nom - on le
     * rencontre, on ouvre un espace, on structure le travail, et on n'a rien
     * d'autre. Un client, lui, est quelqu'un a qui on envoie un contrat.
     *
     * Signale sous le champ de l'adresse et pas sous le statut : c'est
     * l'adresse qui manque, et c'est elle que le lecteur doit remplir.
     */
    protected function assertClientHasAnAddress(CustomerInputInterface $input): void
    {
        if ($input->getStatus()->isProspect()) {
            return;
        }

        $email = $input->getContractualEmail();

        if (null === $email || '' === $email) {
            throw new FieldException('contractualEmail', $this->translator->trans('backend.studio.customers.errors.contractual_email_required'));
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
