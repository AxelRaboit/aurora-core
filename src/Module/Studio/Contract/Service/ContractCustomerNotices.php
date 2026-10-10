<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Service;

use Aurora\Core\Mail\Service\MailService;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Studio\Contract\Entity\ContractInterface;
use Aurora\Module\Studio\Contract\Enum\ContractStatusEnum;

/**
 * The mails a provider chooses to send when they end a contract.
 *
 * **Chosen, never automatic.** Cancelling and terminating are the provider's
 * gestures, recorded after the fact as often as not: a termination is usually
 * a customer's email being written down, and telling that customer what they
 * already wrote would be noise. So the screen asks: « Prévenir le client » is
 * ticked for a cancellation and for a termination the provider or both
 * parties decided, unticked for one the customer gave notice of, and this
 * class only runs when the answer was yes.
 *
 * Each mail goes to the address the contract names, in the contract's
 * language, and leaves a line in the audit trail: what the customer was told
 * is part of the contract's history, like the link that was sent.
 */
readonly class ContractCustomerNotices
{
    public function __construct(
        private MailService $mailService,
        private AuditLogger $auditLogger,
    ) {}

    /**
     * Whether a cancellation would be news to the customer at all.
     *
     * Asked before cancelling, while the status still says where the contract
     * stood. Only one that reached them: refused, expired or revoked all mean
     * a link went out, while a contract still « Scellé » was never sent, and
     * « le contrat est annulé » would be the first they heard of it.
     */
    public function cancellationIsNews(ContractInterface $contract): bool
    {
        return ContractStatusEnum::Sealed !== $contract->getStatus()
            && null !== $contract->getCustomer()->getContractualEmail();
    }

    public function cancelled(ContractInterface $contract): void
    {
        $this->mailService->send(
            to: $contract->getCustomer()->getContractualEmail() ?? '',
            subjectKey: 'studio.email.contract_cancelled.subject',
            template: '@Studio/email/contract_cancelled.html.twig',
            context: ['contract' => $contract, 'customer' => $contract->getCustomer()],
            locale: $contract->getLocale(),
            subjectParameters: ['{reference}' => (string) $contract->getReference()],
        );

        $this->auditLogger->log('studio', 'contract.cancellation_sent', 'Contract', $contract->getId(), [
            'reference' => $contract->getReference(),
            'recipient' => $contract->getCustomer()->getContractualEmail(),
        ]);
    }

    public function terminated(ContractInterface $contract): void
    {
        $this->mailService->send(
            to: $contract->getCustomer()->getContractualEmail() ?? '',
            subjectKey: 'studio.email.contract_terminated.subject',
            template: '@Studio/email/contract_terminated.html.twig',
            context: ['contract' => $contract, 'customer' => $contract->getCustomer()],
            locale: $contract->getLocale(),
            subjectParameters: ['{reference}' => (string) $contract->getReference()],
        );

        $this->auditLogger->log('studio', 'contract.termination_sent', 'Contract', $contract->getId(), [
            'reference' => $contract->getReference(),
            'recipient' => $contract->getCustomer()->getContractualEmail(),
        ]);
    }
}
