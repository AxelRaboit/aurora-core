<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\Manager;

use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\Customer\Repository\CustomerRepository;
use Aurora\Module\Studio\CustomerSpace\Dto\CustomerSpaceInputInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceMember;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceMemberInterface;
use Aurora\Module\Studio\CustomerSpace\Enum\CustomerSpaceMemberRoleEnum;
use Aurora\Module\Studio\CustomerSpace\Repository\CustomerSpaceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsAlias(CustomerSpaceManagerInterface::class)]
class CustomerSpaceManager implements CustomerSpaceManagerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly AuditLogger $auditLogger,
        protected readonly CustomerSpaceRepository $spaceRepository,
        protected readonly CustomerRepository $customerRepository,
        protected readonly UserRepository $userRepository,
        protected readonly TranslatorInterface $translator,
    ) {}

    public function create(CustomerSpaceInputInterface $input): CustomerSpaceInterface
    {
        $space = $this->createSpace();

        // The colour is picked before the row exists, so the count it spreads
        // against does not include the space being created.
        if (null === $input->getColourSlot()) {
            $space->setColourSlot($this->spaceRepository->leastUsedColourSlot());
        }

        $this->applyInput($space, $input);

        $this->entityManager->persist($space);
        $this->entityManager->flush();

        $this->auditCreated($space);

        return $space;
    }

    public function update(CustomerSpaceInterface $space, CustomerSpaceInputInterface $input): void
    {
        $this->applyInput($space, $input);
        $this->entityManager->flush();

        $this->auditUpdated($space);
    }

    /**
     * Deleting a space removes its membership rows and nothing else.
     *
     * There is nothing else to remove yet. The moment content items hang off a
     * space, this becomes the place that refuses - in words, the way
     * `CustomerManager::delete` refuses a customer a contract names - rather
     * than letting a foreign key answer with a 500 halfway through a request.
     */
    public function delete(CustomerSpaceInterface $space): void
    {
        $this->auditDeleted($space);

        $this->entityManager->remove($space);
        $this->entityManager->flush();
    }

    /**
     * Instantiates the concrete entity. Override in a subclass to return a
     * client-substituted class - `resolve_target_entities` only affects
     * Doctrine relation resolution, not direct `new` calls.
     */
    protected function createSpace(): CustomerSpaceInterface
    {
        return new CustomerSpace();
    }

    /** Same contract as `createSpace`, for the row that joins a space to an account. */
    protected function createMember(): CustomerSpaceMemberInterface
    {
        return new CustomerSpaceMember();
    }

    /**
     * Hydrates the entity from the input DTO. Override in a subclass and
     * call `parent::applyInput()` FIRST so the base fields stay populated,
     * then read your own extra fields off the input.
     */
    protected function applyInput(CustomerSpaceInterface $space, CustomerSpaceInputInterface $input): void
    {
        $space
            ->setName($input->getName())
            ->setDescription($input->getDescription())
            ->setCustomer($this->resolveCustomer($input))
            ->setStatus($input->getStatus())
            ->setTimezone($input->getTimezone());

        $colourSlot = $input->getColourSlot();
        if (null !== $colourSlot) {
            $space->setColourSlot($colourSlot);
        }

        $this->applyMembers($space, $input);
    }

    /**
     * The company this space is for.
     *
     * Reported as a field rejection rather than resolved to null: the relation
     * is required, so an id that does not resolve has to stop the save, and it
     * has to stop it under the picker that produced it.
     */
    protected function resolveCustomer(CustomerSpaceInputInterface $input): CustomerInterface
    {
        $customerId = $input->getCustomerId();
        $customer = null === $customerId ? null : $this->customerRepository->find($customerId);

        if (!$customer instanceof CustomerInterface) {
            throw new FieldException('customerId', $this->translator->trans('backend.studio.spaces.errors.customer_required'));
        }

        return $customer;
    }

    /**
     * Brings the membership rows in line with what the form sent.
     *
     * Reconciled rather than cleared and rebuilt: dropping every row and
     * inserting the set back would change each member's id on every save, and
     * ids that churn are what makes an audit trail stop being one. Existing
     * rows keep theirs and only their role moves.
     *
     * An id that no longer resolves to an account is skipped. The picker is fed
     * from the account list, so the only way to send an unknown one is to have
     * edited the payload, or to have had somebody deleted mid-form.
     */
    protected function applyMembers(CustomerSpaceInterface $space, CustomerSpaceInputInterface $input): void
    {
        $wanted = [];
        foreach ($input->getMembers() as $row) {
            $wanted[$row['userId']] = CustomerSpaceMemberRoleEnum::tryFrom($row['role']) ?? CustomerSpaceMemberRoleEnum::Member;
        }

        foreach ($space->getMembers()->toArray() as $member) {
            $userId = (int) $member->getUser()->getId();

            if (!isset($wanted[$userId])) {
                $space->removeMember($member);

                continue;
            }

            $member->setRole($wanted[$userId]);
            unset($wanted[$userId]);
        }

        if ([] === $wanted) {
            return;
        }

        $users = $this->userRepository->findBy(['id' => array_keys($wanted)]);

        foreach ($users as $user) {
            $member = $this->createMember();
            $member->setUser($user);
            $member->setRole($wanted[(int) $user->getId()]);

            $space->addMember($member);
        }
    }

    protected function auditCreated(CustomerSpaceInterface $space): void
    {
        $this->auditLogger->log('studio', 'customer_space.created', 'CustomerSpace', $space->getId(), $this->auditPayload($space));
    }

    protected function auditUpdated(CustomerSpaceInterface $space): void
    {
        $this->auditLogger->log('studio', 'customer_space.updated', 'CustomerSpace', $space->getId(), $this->auditPayload($space));
    }

    protected function auditDeleted(CustomerSpaceInterface $space): void
    {
        $this->auditLogger->log('studio', 'customer_space.deleted', 'CustomerSpace', $space->getId(), $this->auditPayload($space));
    }

    /**
     * Structured payload logged with every audit entry. Override to add
     * extra fields: `[...parent::auditPayload($space), 'code' => $space->getCode()]`.
     *
     * @return array<string, mixed>
     */
    protected function auditPayload(CustomerSpaceInterface $space): array
    {
        return [
            'name' => $space->getName(),
            'customerId' => $space->getCustomer()->getId(),
            'customerName' => $space->getCustomer()->getLegalName(),
            'status' => $space->getStatus()->value,
            'memberCount' => $space->getMembers()->count(),
        ];
    }
}
