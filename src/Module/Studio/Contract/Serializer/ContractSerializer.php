<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Serializer;

use Aurora\Module\Studio\Contract\Access\Entity\ContractAccessLinkInterface;
use Aurora\Module\Studio\Contract\Access\Repository\ContractAccessLinkRepository;
use Aurora\Module\Studio\Contract\Entity\ContractInterface;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateVersionInterface;
use Aurora\Module\Studio\Contract\Repository\ContractRepository;
use Aurora\Module\Studio\Contract\Service\ContractRetentionPolicy;
use Aurora\Module\Studio\Contract\Service\ContractSeal;
use Aurora\Module\Studio\Contract\Service\ContractSignedDocument;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

use const DATE_ATOM;

#[AsAlias(ContractSerializerInterface::class)]
class ContractSerializer implements ContractSerializerInterface
{
    public function __construct(
        protected readonly ContractSeal $seal,
        protected readonly ContractAccessLinkRepository $links,
        protected readonly ContractRetentionPolicy $retention,
        protected readonly ContractRepository $contracts,
        protected readonly ContractSignedDocument $signedDocument,
    ) {}

    /** @return array<string, mixed> */
    public function serialize(ContractInterface $contract): array
    {
        return $this->row($contract, $this->links->findActiveFor($contract));
    }

    public function serializeMany(array $contracts): array
    {
        $links = $this->links->findActiveForContracts($contracts);

        return array_map(
            fn (ContractInterface $contract): array => $this->row($contract, $links[(int) $contract->getId()] ?? null),
            $contracts,
        );
    }

    /** @return array<string, mixed> */
    protected function row(ContractInterface $contract, ?ContractAccessLinkInterface $link): array
    {
        $customer = $contract->getCustomer();

        return [
            'id' => $contract->getId(),
            'reference' => $contract->getReference(),
            'status' => $contract->getStatus()->value,
            'statusLabel' => $contract->getStatus()->getLabel(),
            'isFrozen' => $contract->isFrozen(),
            'isEditable' => $contract->getStatus()->isEditable() && !$contract->isFrozen(),
            'locale' => $contract->getLocale(),
            'customerId' => $customer->getId(),
            'customerName' => $customer->getLegalName(),
            'amountCents' => $contract->getAmountCents(),
            'amountCurrency' => $contract->getAmountCurrency()?->value,
            'effectiveDate' => $contract->getEffectiveDate()?->format('Y-m-d'),
            'customFields' => $contract->getCustomFields(),
            'frozenAt' => $contract->getFrozenAt()?->format(DATE_ATOM),
            'createdAt' => $contract->getCreatedAt()->format(DATE_ATOM),
            'body' => $this->part($contract->getBodyVersion()),
            'annex' => $this->part($contract->getAnnexVersion()),
            'link' => $this->link($link),
            'hasPdf' => $contract->hasPdf(),
            'pdfHash' => $contract->getPdfHash(),
            // A refusal is an answer, so it travels with the row rather than
            // only with the document: the list is where somebody decides
            // whether to chase a customer or to leave them alone.
            'refusal' => $contract->isRefused() ? [
                'refusedAt' => $contract->getRefusedAt()?->format(DATE_ATOM),
                'reason' => $contract->getRefusalReason(),
                'ip' => $contract->getRefusedFromIp(),
            ] : null,
            'reminders' => [
                'count' => $contract->getReminderCount(),
                'lastAt' => $contract->getLastReminderAt()?->format(DATE_ATOM),
            ],
            // The day the evidence stops being required, computed from the
            // policy in force rather than stored: a retention that changed
            // would otherwise leave old rows quoting the old rule.
            'retainedUntil' => $this->retention->until($contract)?->format(DATE_ATOM),
            // What this document changes, if anything. The reference comes
            // from the copy on the row rather than through the relation, so an
            // amendment whose parent was deleted after its retention still
            // says what it amended.
            'amends' => $contract->isAmendment() ? [
                'id' => $contract->getAmends()?->getId(),
                'reference' => $contract->getAmendsReference(),
                'rank' => $contract->getAmendmentRank(),
            ] : null,
            'termination' => $contract->isTerminated() ? [
                'noticedAt' => $contract->getTerminationNoticedAt()?->format('Y-m-d'),
                'effectiveAt' => $contract->getTerminationEffectiveAt()?->format('Y-m-d'),
                'origin' => $contract->getTerminationOrigin()?->value,
                'originLabel' => $contract->getTerminationOrigin()?->getLabel(),
                'reason' => $contract->getTerminationReason(),
                // Whether it has actually taken effect, because a notice given
                // today for the end of the month is not the same screen as a
                // contract that has already stopped.
                'isEffective' => $contract->isTerminationEffective(),
            ] : null,
        ];
    }

    /** @return array<string, mixed> */
    public function serializeDocument(ContractInterface $contract): array
    {
        return [
            ...$this->serialize($contract),
            // Null for a draft, which has no sealed text yet; otherwise the
            // sealed text with the signer's city and date written in.
            'renderedHtml' => null === $contract->getRenderedHtml() ? null : $this->signedDocument->html($contract),
            // The seal, as a block a human can read and check. A hash shown
            // without its algorithm and its canonical form is a string nobody
            // can do anything with.
            // The amendments this contract carries, oldest first: the last one
            // is what is in force, and reading forward is how somebody checks
            // that nothing is missing in between.
            'amendments' => array_map(
                fn (ContractInterface $amendment): array => [
                    'id' => $amendment->getId(),
                    'reference' => $amendment->getReference(),
                    'rank' => $amendment->getAmendmentRank(),
                    'status' => $amendment->getStatus()->value,
                    'statusLabel' => $amendment->getStatus()->getLabel(),
                    'frozenAt' => $amendment->getFrozenAt()?->format(DATE_ATOM),
                ],
                $this->contracts->findAmendmentsOf($contract),
            ),
            'seal' => [
                'contentHash' => $contract->getContentHash(),
                'hashAlgo' => $contract->getHashAlgo(),
                'canonicalVersion' => $contract->getCanonicalVersion(),
                'frozenAt' => $contract->getFrozenAt()?->format(DATE_ATOM),
                // Recomputed on every view rather than trusted. This page is
                // where somebody would look after suspecting something, so the
                // answer has to be current, not stored.
                'verified' => $contract->isFrozen() && $this->seal->verify($contract),
            ],
        ];
    }

    /**
     * What the back office can say about the address that was handed out.
     *
     * Never the secret. It exists for one request, the one that minted it, and
     * by the time anything is serialized it is gone - which is the whole point
     * of storing a hash. A reader who needs to reach the document opens it from
     * here, not from the customer's link.
     *
     * @return array<string, mixed>|null
     */
    private function link(?ContractAccessLinkInterface $link): ?array
    {
        if (!$link instanceof ContractAccessLinkInterface) {
            return null;
        }

        return [
            'recipientEmail' => $link->getRecipientEmail(),
            'sentAt' => $link->getSentAt()?->format(DATE_ATOM),
            'expiresAt' => $link->getExpiresAt()->format(DATE_ATOM),
            // The one thing a link answers that nothing else can: whether the
            // customer ever opened the document.
            'firstOpenedAt' => $link->getFirstOpenedAt()?->format(DATE_ATOM),
            'lastUsedAt' => $link->getLastUsedAt()?->format(DATE_ATOM),
        ];
    }

    /**
     * A pinned version, and whether the template has moved on since.
     *
     * @return array<string, mixed>|null
     */
    private function part(?ContractTemplateVersionInterface $version): ?array
    {
        if (!$version instanceof ContractTemplateVersionInterface) {
            return null;
        }

        $template = $version->getTemplate();
        $latest = $template->getLatestPublishedVersion();

        return [
            'templateId' => $template->getId(),
            'templateName' => $template->getName(),
            'versionId' => $version->getId(),
            'versionNumber' => $version->getNumber(),
            // Said out loud, because a draft pinned to version 2 while version
            // 3 is in force is not wrong - it is a choice somebody should be
            // able to see and redo.
            'latestVersionNumber' => $latest?->getNumber(),
            'isOutdated' => $latest instanceof ContractTemplateVersionInterface && $latest->getNumber() > $version->getNumber(),
        ];
    }
}
