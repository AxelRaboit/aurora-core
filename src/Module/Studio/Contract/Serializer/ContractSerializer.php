<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Serializer;

use Aurora\Module\Dev\Audit\Entity\AuditLogInterface;
use Aurora\Module\Dev\Audit\Repository\AuditLogRepository;
use Aurora\Module\Studio\Contract\Access\Entity\ContractAccessLinkInterface;
use Aurora\Module\Studio\Contract\Access\Repository\ContractAccessLinkRepository;
use Aurora\Module\Studio\Contract\Entity\ContractInterface;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateVersionInterface;
use Aurora\Module\Studio\Contract\Enum\ContractStatusEnum;
use Aurora\Module\Studio\Contract\Enum\ContractTemplateKindEnum;
use Aurora\Module\Studio\Contract\Repository\ContractRepository;
use Aurora\Module\Studio\Contract\Service\ContractRetentionPolicy;
use Aurora\Module\Studio\Contract\Service\ContractSeal;
use Aurora\Module\Studio\Contract\Service\ContractSignedDocument;
use Aurora\Module\Studio\Contract\Signature\Entity\ContractSignatureInterface;
use Aurora\Module\Studio\Contract\Signature\Repository\ContractSignatureRepository;
use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_filter;
use function array_map;
use function max;

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
        protected readonly ContractSignatureRepository $signatures,
        protected readonly AuditLogRepository $auditLogs,
        protected readonly TranslatorInterface $translator,
    ) {}

    /** @return array<string, mixed> */
    public function serialize(ContractInterface $contract): array
    {
        return $this->row(
            $contract,
            $this->links->findActiveFor($contract),
            $this->activityOf([$contract])[(int) $contract->getId()] ?? [],
        );
    }

    public function serializeMany(array $contracts): array
    {
        $links = $this->links->findActiveForContracts($contracts);
        $activity = $this->activityOf($contracts);

        return array_map(
            fn (ContractInterface $contract): array => $this->row(
                $contract,
                $links[(int) $contract->getId()] ?? null,
                $activity[(int) $contract->getId()] ?? [],
            ),
            $contracts,
        );
    }

    /**
     * What happened to each contract away from its own row: on any of its
     * links, and in its signatures. Two grouped queries for a whole list.
     *
     * @param list<ContractInterface> $contracts
     *
     * @return array<int, list<DateTimeImmutable>>
     */
    protected function activityOf(array $contracts): array
    {
        $activity = [];

        foreach ($this->links->latestActivityForContracts($contracts) as $id => $at) {
            $activity[$id][] = $at;
        }

        foreach ($this->signatures->latestSignedAtForContracts($contracts) as $id => $at) {
            $activity[$id][] = $at;
        }

        return $activity;
    }

    /**
     * @param list<DateTimeImmutable> $activity what happened on its links and signatures
     *
     * @return array<string, mixed>
     */
    protected function row(ContractInterface $contract, ?ContractAccessLinkInterface $link, array $activity = []): array
    {
        $customer = $contract->getCustomer();

        return [
            'id' => $contract->getId(),
            'reference' => $contract->getReference(),
            'status' => $contract->getStatus()->value,
            'statusLabel' => $contract->getStatus()->getLabel(),
            // Where it stands in the journey, which is what the list's tabs
            // and the dashboard's counters are built on: one answer, given
            // here, rather than a status list repeated on every screen.
            'step' => $this->step($contract),
            // Sealed contracts can be deleted once their retention has run
            // out, drafts at any time; the screen only offers what will work.
            'isDeletable' => !$contract->isFrozen() || $this->retention->hasElapsed($contract),
            // Where a first send would go, so the confirmation can say it.
            'customerEmail' => $customer->getContractualEmail(),
            'lastActivityAt' => $this->lastActivity($contract, $activity)->format(DATE_ATOM),
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
            'body' => $this->part($contract->getBodyVersion(), $contract->getAdaptedWording(ContractTemplateKindEnum::Body)),
            'annex' => $this->part($contract->getAnnexVersion(), $contract->getAdaptedWording(ContractTemplateKindEnum::Annex)),
            'isAdapted' => $contract->isAdapted(),
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
            'signatures' => $this->signatures($contract),
            'history' => $this->history($contract),
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
     * The step of the journey a contract is at.
     *
     * - `draft`: being written;
     * - `to_send`: sealed and not out, or back after a refusal, an expiry or a
     *   revocation, waiting to be sent or cancelled;
     * - `with_customer`: out, no answer yet;
     * - `to_countersign`: signed by the customer, waiting for the provider;
     * - `active`: concluded and running, a notice included until it bites;
     * - `ended`: terminated for good, or cancelled.
     */
    protected function step(ContractInterface $contract): string
    {
        return match ($contract->getStatus()) {
            ContractStatusEnum::Draft => 'draft',
            ContractStatusEnum::Sealed, ContractStatusEnum::Refused, ContractStatusEnum::Expired, ContractStatusEnum::Revoked => 'to_send',
            ContractStatusEnum::Sent, ContractStatusEnum::Opened => 'with_customer',
            ContractStatusEnum::SignedByCustomer => 'to_countersign',
            ContractStatusEnum::Countersigned => $contract->isTerminationEffective() ? 'ended' : 'active',
            ContractStatusEnum::Cancelled => 'ended',
        };
    }

    /**
     * The most recent thing that happened to it, for a list sorted by what
     * moved. Never empty: a contract always has the day it was created.
     *
     * The links and signatures come in from outside (`activityOf`): a
     * customer's signature used to count for nothing, so a contract signed
     * yesterday read as last touched the day it was sent.
     *
     * @param list<DateTimeImmutable> $activity
     */
    protected function lastActivity(ContractInterface $contract, array $activity): DateTimeImmutable
    {
        $dates = array_filter([
            ...$activity,
            $contract->getCreatedAt(),
            $contract->getFrozenAt(),
            $contract->getRefusedAt(),
            $contract->getLastReminderAt(),
            $contract->getPdfGeneratedAt(),
            $contract->getTerminationNoticedAt(),
        ]);

        return max($dates);
    }

    /**
     * The signatures as proof: who, where, when, from which address, and how
     * they were identified.
     *
     * @return list<array<string, mixed>>
     */
    protected function signatures(ContractInterface $contract): array
    {
        return array_map(static fn (ContractSignatureInterface $signature): array => [
            'role' => $signature->getRole()->value,
            'name' => $signature->getDeclaredFullName(),
            'email' => $signature->getDeclaredEmail(),
            'place' => $signature->getDeclaredPlace(),
            'date' => $signature->getDeclaredDate()->format('Y-m-d'),
            'signedAt' => $signature->getSignedAt()->format(DATE_ATOM),
            'ip' => $signature->getIpAddress(),
            'codeSentTo' => $signature->getChallengeSentTo(),
            'codeVerifiedAt' => $signature->getChallengeVerifiedAt()?->format(DATE_ATOM),
            'byUser' => $signature->getUser()?->getName(),
            'hashMatches' => $signature->getSignedContentHash() === $contract->getContentHash(),
        ], $this->signatures->findForContract($contract));
    }

    /**
     * What happened to it, newest first, as the audit trail recorded it.
     *
     * @return list<array<string, mixed>>
     */
    protected function history(ContractInterface $contract): array
    {
        if (null === $contract->getId()) {
            return [];
        }

        $page = $this->auditLogs->findPaginatedForEntity('Contract', $contract->getId(), 1, 50);

        return array_map(fn (AuditLogInterface $log): array => [
            'label' => $this->translator->trans('suite.audit.actions.'.$log->getModule().'.'.$log->getAction()),
            'userName' => $log->getUserName(),
            'at' => $log->getCreatedAt()->format(DATE_ATOM),
        ], $page['items'] ?? []);
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
    /**
     * @param array{locale: string, title: string, blocks: list<mixed>, baseVersionId: int|null, adaptedAt: string}|null $adapted
     *
     * @return array<string, mixed>|null
     */
    private function part(?ContractTemplateVersionInterface $version, ?array $adapted = null): ?array
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
            // Written for this contract alone: the list says « adapté », the
            // contract's screen offers the text and what differs.
            'isAdapted' => null !== $adapted,
            'adaptedAt' => $adapted['adaptedAt'] ?? null,
            // The version it was adapted from, which can differ from the one
            // pinned when a duplicate moved to a newer version.
            'adaptedFromVersionId' => $adapted['baseVersionId'] ?? null,
        ];
    }
}
