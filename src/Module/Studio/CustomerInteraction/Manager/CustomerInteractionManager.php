<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerInteraction\Manager;

use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\CustomerInteraction\Dto\CustomerInteractionInputInterface;
use Aurora\Module\Studio\CustomerInteraction\Entity\CustomerInteraction;
use Aurora\Module\Studio\CustomerInteraction\Entity\CustomerInteractionInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(CustomerInteractionManagerInterface::class)]
class CustomerInteractionManager implements CustomerInteractionManagerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Records an exchange, and with it, when asked, the next follow-up.
     *
     * **The follow-up rides along on purpose.** Calling somebody back is what
     * settles the follow-up that was due, and the moment the call is written
     * down is the moment the next one is decided. Asked in the same form, the
     * old date is replaced - or cleared, when no new one is given - instead of
     * staying late on the dashboard after the call was made.
     */
    public function create(CustomerInterface $customer, CustomerInteractionInputInterface $input, ?CoreUserInterface $author): CustomerInteractionInterface
    {
        $interaction = $this->createInteraction();
        $interaction
            ->setCustomer($customer)
            ->setAuthor($author, $author instanceof CoreUserInterface ? $this->labelOf($author) : '');
        $this->applyInput($interaction, $input);

        if ($input->setsFollowUp()) {
            $customer
                ->setNextFollowUpOn($input->getNextFollowUpOn())
                ->setFollowUpNote($input->getNextFollowUpOn() instanceof DateTimeImmutable ? $input->getFollowUpNote() : null);
        }

        $this->entityManager->persist($interaction);
        $this->entityManager->flush();

        $this->auditCreated($interaction);

        return $interaction;
    }

    public function update(CustomerInteractionInterface $interaction, CustomerInteractionInputInterface $input): void
    {
        $this->applyInput($interaction, $input);
        $this->entityManager->flush();

        $this->auditUpdated($interaction);
    }

    public function delete(CustomerInteractionInterface $interaction): void
    {
        $this->auditDeleted($interaction);

        $this->entityManager->remove($interaction);
        $this->entityManager->flush();
    }

    /**
     * Hydrates the entity from the input DTO. Override in a subclass and
     * call `parent::applyInput()` FIRST so the base fields stay populated,
     * then read your own extra fields off the input.
     */
    protected function applyInput(CustomerInteractionInterface $interaction, CustomerInteractionInputInterface $input): void
    {
        $interaction
            ->setKind($input->getKind())
            ->setOccurredAt($input->getOccurredAt() ?? new DateTimeImmutable())
            ->setSummary($input->getSummary());
    }

    protected function labelOf(CoreUserInterface $user): string
    {
        return $user instanceof User ? $user->getName() : $user->getUserIdentifier();
    }

    /**
     * Instantiates the concrete entity. Override in a subclass to return a
     * client-substituted class - `resolve_target_entities` only affects
     * Doctrine relation resolution, not direct `new` calls.
     */
    protected function createInteraction(): CustomerInteractionInterface
    {
        return new CustomerInteraction();
    }

    protected function auditCreated(CustomerInteractionInterface $interaction): void
    {
        $this->auditLogger->log('studio', 'customer_interaction.created', 'CustomerInteraction', $interaction->getId(), $this->auditPayload($interaction));
    }

    protected function auditUpdated(CustomerInteractionInterface $interaction): void
    {
        $this->auditLogger->log('studio', 'customer_interaction.updated', 'CustomerInteraction', $interaction->getId(), $this->auditPayload($interaction));
    }

    protected function auditDeleted(CustomerInteractionInterface $interaction): void
    {
        $this->auditLogger->log('studio', 'customer_interaction.deleted', 'CustomerInteraction', $interaction->getId(), $this->auditPayload($interaction));
    }

    /**
     * Structured payload logged with every audit entry. Not the summary: it is
     * the studio's private account of a conversation, and the audit is read
     * by whoever administers the site.
     *
     * @return array<string, mixed>
     */
    protected function auditPayload(CustomerInteractionInterface $interaction): array
    {
        return [
            'customerId' => $interaction->getCustomer()->getId(),
            'kind' => $interaction->getKind()->value,
        ];
    }
}
