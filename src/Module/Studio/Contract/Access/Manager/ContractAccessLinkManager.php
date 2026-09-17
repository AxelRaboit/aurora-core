<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Access\Manager;

use Aurora\Core\Mail\Service\MailService;
use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Studio\Contract\Access\Entity\AbstractContractAccessLink;
use Aurora\Module\Studio\Contract\Access\Entity\ContractAccessLink;
use Aurora\Module\Studio\Contract\Access\Entity\ContractAccessLinkInterface;
use Aurora\Module\Studio\Contract\Access\Repository\ContractAccessLinkRepository;
use Aurora\Module\Studio\Contract\Entity\ContractInterface;
use Aurora\Module\Studio\Contract\Enum\ContractStatusEnum;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function hash_equals;

/**
 * The addresses that open a contract from outside.
 *
 * Three rules hold this together, and none of them is left to a caller:
 *
 * 1. **The secret exists once.** `create()` returns the URL because that is the
 *    only moment the plaintext token is available; nothing can recover it
 *    afterwards, which is the whole point of storing a hash.
 * 2. **Only a sealed contract gets a link.** A draft can still change, and an
 *    address that opens a moving document is the failure the entire snapshot
 *    design exists to prevent.
 * 3. **One live link at a time.** Minting a second revokes the first, so
 *    "which address is valid" always has one answer - and revoking is what a
 *    resend has to mean, not an extra door left open.
 */
#[AsAlias(ContractAccessLinkManagerInterface::class)]
class ContractAccessLinkManager implements ContractAccessLinkManagerInterface
{
    /**
     * How long an address stays valid, in days.
     *
     * Thirty, because an offer that can still be accepted a year later is a
     * liability and the paper version always carried a validity period. A
     * setting can come the day somebody wants a different number; a constant
     * that is documented beats a column nobody has a use for yet.
     */
    public const int DEFAULT_LIFETIME_DAYS = 30;

    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly AuditLogger $auditLogger,
        protected readonly ContractAccessLinkRepository $links,
        protected readonly UrlGeneratorInterface $urlGenerator,
        protected readonly MailService $mail,
        protected readonly TranslatorInterface $translator,
    ) {}

    public function send(ContractInterface $contract): ContractAccessLinkInterface
    {
        $link = $this->handOut(
            $contract,
            'studio.email.contract_link.subject',
            '@Studio/email/contract_link.html.twig',
        );

        $this->auditLogger->log('studio', 'contract.link_sent', 'Contract', $contract->getId(), $this->handOutPayload($link));

        return $link;
    }

    /**
     * Chases a contract that was sent and not signed.
     *
     * Deliberately the same act as a resend rather than a mail beside it: the
     * application stores only a hash of the token it handed out, so it cannot
     * rebuild the address it sent last week. A reminder that pointed at the
     * old link would be a link this code is unable to produce. It therefore
     * hands out a new one and says so in the mail.
     *
     * The counter lives on the contract, not on the link this replaces, or the
     * ceiling would reset every time it was reached.
     */
    public function remind(ContractInterface $contract): ContractAccessLinkInterface
    {
        $link = $this->handOut(
            $contract,
            'studio.email.contract_reminder.subject',
            '@Studio/email/contract_reminder.html.twig',
        );

        $contract->markReminded(new DateTimeImmutable());
        $this->entityManager->flush();

        $this->auditLogger->log('studio', 'contract.reminder_sent', 'Contract', $contract->getId(), [
            ...$this->handOutPayload($link),
            'reminderCount' => $contract->getReminderCount(),
        ]);

        return $link;
    }

    /**
     * What both hand-outs record: which address went where, and until when.
     *
     * @return array<string, mixed>
     */
    protected function handOutPayload(ContractAccessLinkInterface $link): array
    {
        return [
            'reference' => $link->getContract()->getReference(),
            'recipient' => $link->getRecipientEmail(),
            'selector' => $link->getSelector(),
            'expiresAt' => $link->getExpiresAt()->format(DATE_ATOM),
        ];
    }

    /**
     * Mints an address, revokes whatever came before, and mails it.
     *
     * One body for the first send and for every reminder, because they differ
     * only in what the mail says. Two copies of this would be two places for
     * the revocation rule to drift.
     *
     * It deliberately does *not* write the audit entry. The action has to be a
     * literal at its call site: the audit label check reads the source for
     * `log('module', 'action')` pairs, and an action passed in as a variable is
     * a blind spot it refuses to have. So each caller logs its own.
     */
    protected function handOut(
        ContractInterface $contract,
        string $subjectKey,
        string $template,
    ): ContractAccessLinkInterface {
        if (!$contract->isFrozen()) {
            throw new FieldException('status', $this->translator->trans('backend.studio.contracts.errors.seal_before_sending'));
        }

        if ($contract->getStatus()->isEngaged()) {
            // Somebody has signed. Re-sending would hand out a fresh address to
            // a document that is already committed, which is not a resend but a
            // second chance to sign the same thing.
            throw new FieldException('status', $this->translator->trans('backend.studio.contracts.errors.already_engaged'));
        }

        $recipient = $contract->getCustomer()->getContractualEmail();

        if (null === $recipient || '' === $recipient) {
            // Un prospect peut n'avoir qu'un nom, et c'est voulu. Mais on
            // n'envoie pas un contrat a personne : c'est ici, au moment de
            // l'envoi, que l'adresse devient indispensable - pas a la creation
            // de la fiche.
            throw new FieldException('customer', $this->translator->trans('backend.studio.contracts.errors.customer_has_no_address'));
        }

        $link = $this->createLink();
        $token = $link->mint();

        $link
            ->setContract($contract)
            ->setRecipientEmail($recipient)
            ->setExpiresAt(new DateTimeImmutable(sprintf('+%d days', self::DEFAULT_LIFETIME_DAYS)));

        // Any address handed out before is revoked. A resend replaces, it does
        // not add: two live links means two answers to "is this address valid".
        foreach ($this->links->findForContract($contract) as $previous) {
            if (!$previous->isRevoked()) {
                $previous->revoke(new DateTimeImmutable());
            }
        }

        $this->entityManager->persist($link);
        $this->entityManager->flush();

        $url = $this->urlFor($link, $token);

        $this->mail->send(
            to: $link->getRecipientEmail(),
            subjectKey: $subjectKey,
            template: $template,
            context: [
                'contract' => $contract,
                'customer' => $contract->getCustomer(),
                // The address, and nothing of the document itself. A mailbox is
                // not where a contract should be readable, and a forwarded mail
                // should carry a door rather than the room behind it.
                'url' => $url,
                'expiresAt' => $link->getExpiresAt(),
            ],
            locale: $contract->getLocale(),
        );

        $link->markSent(new DateTimeImmutable());
        $contract->setStatus(ContractStatusEnum::Sent);
        // A new address is a new ask, so a refusal recorded against the old
        // one stops being the current state. The audit trail keeps it, which
        // is where the history of an answer belongs.
        $contract->clearRefusal();

        $this->entityManager->flush();

        return $link;
    }

    public function revoke(ContractAccessLinkInterface $link): void
    {
        $link->revoke(new DateTimeImmutable());
        $this->entityManager->flush();

        $this->auditLogger->log('studio', 'contract.link_revoked', 'Contract', $link->getContract()->getId(), [
            'reference' => $link->getContract()->getReference(),
            'selector' => $link->getSelector(),
        ]);
    }

    /**
     * The link a selector and a secret name, or null.
     *
     * Null for every reason: unknown selector, wrong secret, revoked, expired.
     * The caller renders one page for all of them, because telling a stranger
     * which of those it was tells them which guesses landed.
     */
    public function resolveUsable(string $selector, string $token): ?ContractAccessLinkInterface
    {
        $link = $this->links->findBySelector($selector);

        if (!$link instanceof ContractAccessLinkInterface) {
            return null;
        }

        // Constant time, on the hash rather than the secret: a comparison that
        // returns early on the first wrong character tells somebody how much of
        // it they have right.
        if (!hash_equals($link->getHashedToken(), AbstractContractAccessLink::hashToken($token))) {
            return null;
        }

        if (!$link->isUsable(new DateTimeImmutable())) {
            return null;
        }

        return $link;
    }

    /**
     * Records that the document was opened.
     *
     * The first open moves the contract from sent to opened, which is the one
     * thing the back office can say about a link without asking the customer.
     */
    public function markOpened(ContractAccessLinkInterface $link): void
    {
        $wasNeverOpened = !$link->getFirstOpenedAt() instanceof DateTimeImmutable;
        $link->markUsed(new DateTimeImmutable());

        $contract = $link->getContract();

        if ($wasNeverOpened && ContractStatusEnum::Sent === $contract->getStatus()) {
            $contract->setStatus(ContractStatusEnum::Opened);
        }

        $this->entityManager->flush();
    }

    /**
     * The absolute address that opens this contract.
     *
     * Absolute because it goes in an email, and built by the router rather than
     * concatenated: a path written by hand has no way of noticing when the
     * route moves.
     */
    public function urlFor(ContractAccessLinkInterface $link, string $token): string
    {
        return $this->urlGenerator->generate(
            'public_contract_show',
            ['selector' => $link->getSelector(), 'token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    protected function createLink(): ContractAccessLinkInterface
    {
        return new ContractAccessLink();
    }
}
