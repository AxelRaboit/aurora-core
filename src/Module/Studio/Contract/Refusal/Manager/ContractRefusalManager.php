<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Refusal\Manager;

use Aurora\Core\Mail\Service\MailService;
use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Studio\Contract\Access\Entity\ContractAccessLinkInterface;
use Aurora\Module\Studio\Contract\Entity\ContractInterface;
use Aurora\Module\Studio\Contract\Enum\ContractStatusEnum;
use Aurora\Module\Studio\Contract\Refusal\Dto\ContractRefusalInputInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The other answer a contract can get.
 *
 * Without this, declining meant not clicking - and a contract nobody ever
 * opened looked exactly like one the customer had decided against. The
 * provider could not tell "waiting" from "no", which is the difference between
 * chasing somebody and leaving them alone.
 *
 * Three properties make it safe to offer to a stranger holding a link:
 *
 * 1. **It refuses to touch an engagement.** A signed or concluded contract
 *    cannot be declined; nobody undoes a commitment by clicking a page.
 * 2. **It is reversible by the provider, and only by them.** Sending the
 *    contract again clears the refusal and mints a new address, so a refusal
 *    taken in error costs a resend rather than a rebuild.
 * 3. **It leaves the address open, and that is on purpose.** Revoking on the
 *    way out would send the person who just answered to the same 404 as a
 *    stranger with a wrong secret, and leave them wondering whether the
 *    refusal registered. The page keeps working and says what it recorded;
 *    the form is gone because the server refuses to sign a refused contract,
 *    not because the door slammed.
 *
 * No email code is asked for, and that is a decision rather than an omission.
 * A code proves a mailbox, which is what a signature needs because a signature
 * binds; a refusal binds nobody and is undone by a resend. Requiring one would
 * mean a mailbox problem could stand between somebody and the word no.
 */
#[AsAlias(ContractRefusalManagerInterface::class)]
class ContractRefusalManager implements ContractRefusalManagerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly AuditLogger $auditLogger,
        protected readonly MailService $mail,
        protected readonly TranslatorInterface $translator,
    ) {}

    public function refuseAsCustomer(
        ContractAccessLinkInterface $link,
        ContractRefusalInputInterface $input,
        Request $request,
    ): void {
        $contract = $link->getContract();

        $this->assertRefusable($contract);

        $now = new DateTimeImmutable();

        $contract->refuse(
            $now,
            $input->getReason(),
            $request->getClientIp(),
            $request->headers->get('User-Agent'),
        );

        $this->entityManager->flush();

        $this->auditLogger->log('studio', 'contract.refused', 'Contract', $contract->getId(), [
            'reference' => $contract->getReference(),
            'customer' => $contract->getCustomer()->getLegalName(),
            'selector' => $link->getSelector(),
            'ip' => $contract->getRefusedFromIp(),
            'userAgent' => $contract->getRefusedUserAgent(),
            // Kept in the trail, because clearing the refusal on a resend
            // clears the current state and not the history of it.
            'reason' => $contract->getRefusalReason(),
            'refusedAt' => $now->format(DATE_ATOM),
        ]);

        $this->notifyProvider($contract);
    }

    /**
     * Whether this contract can still be declined.
     *
     * Two refusals and each is a different mistake: a document that was never
     * sealed cannot have been read, and a commitment cannot be taken back.
     */
    protected function assertRefusable(ContractInterface $contract): void
    {
        if (!$contract->isFrozen()) {
            throw new FieldException('status', $this->translator->trans('backend.studio.contracts.errors.seal_before_signing'));
        }

        if ($contract->getStatus()->isEngaged()) {
            throw new FieldException('status', $this->translator->trans('backend.studio.contracts.errors.already_engaged'));
        }

        if (ContractStatusEnum::Refused === $contract->getStatus()) {
            throw new FieldException('status', $this->translator->trans('backend.studio.contracts.errors.already_refused'));
        }
    }

    /**
     * Tells the provider, because a refusal is news.
     *
     * To the administrator's address rather than to the identity settings: the
     * person who has to do something about it is whoever runs the application,
     * and that is the address the rest of the module already notifies.
     */
    protected function notifyProvider(ContractInterface $contract): void
    {
        $this->mail->sendToAdmin(
            subjectKey: 'studio.email.customer_refused.subject',
            // Without it the subject read « Contrat {reference} : refus du
            // client », braces included.
            subjectParams: ['{reference}' => (string) $contract->getReference()],
            template: '@Studio/email/customer_refused.html.twig',
            context: [
                'contract' => $contract,
                'customer' => $contract->getCustomer(),
                'reason' => $contract->getRefusalReason(),
                'refusedAt' => $contract->getRefusedAt(),
            ],
        );
    }
}
