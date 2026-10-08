<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Manager;

use Aurora\Core\Money\Enum\CurrencyEnum;
use Aurora\Core\Sequence\SequenceGenerator;
use Aurora\Core\Sequence\SequencePrefixEnum;
use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Studio\Contract\Access\Entity\ContractAccessLinkInterface;
use Aurora\Module\Studio\Contract\Dto\ContractInput;
use Aurora\Module\Studio\Contract\Dto\ContractInputInterface;
use Aurora\Module\Studio\Contract\Entity\Contract;
use Aurora\Module\Studio\Contract\Entity\ContractInterface;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateInterface;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateVersionInterface;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateVersionTranslationInterface;
use Aurora\Module\Studio\Contract\Enum\ContractStatusEnum;
use Aurora\Module\Studio\Contract\Enum\ContractTemplateKindEnum;
use Aurora\Module\Studio\Contract\Enum\ContractTerminationOriginEnum;
use Aurora\Module\Studio\Contract\Exception\UnrenderableBlockException;
use Aurora\Module\Studio\Contract\Repository\ContractRepository;
use Aurora\Module\Studio\Contract\Repository\ContractTemplateRepository;
use Aurora\Module\Studio\Contract\Service\ContractCanonicalizer;
use Aurora\Module\Studio\Contract\Service\ContractCustomFieldScanner;
use Aurora\Module\Studio\Contract\Service\ContractDocumentRenderer;
use Aurora\Module\Studio\Contract\Service\ContractRetentionPolicy;
use Aurora\Module\Studio\Contract\Service\ContractSeal;
use Aurora\Module\Studio\Contract\Service\ContractVariableResolver;
use Aurora\Module\Studio\Contract\Signature\Entity\ContractSignatureInterface;
use Aurora\Module\Studio\Contract\Termination\Dto\ContractTerminationInputInterface;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\Customer\Repository\CustomerRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_keys;
use function array_map;
use function array_values;
use function count;
use function implode;
use function is_array;
use function mb_strlen;
use function mb_trim;
use function sprintf;

/**
 * Contracts, and the one moment that matters: the freeze.
 *
 * Everything before it is ordinary editing. The freeze is where a set of
 * choices becomes a document: the reference is minted, the wording is read out
 * of the template versions, the variables are substituted, the HTML is
 * rendered, and the hash is taken over both halves. All of it in one call, so
 * there is no instant at which a contract holds half a seal.
 *
 * It happens before the link goes out, never at signature. That ordering is
 * what makes every signature safe: nothing can move between the moment the
 * document is read and the moment it is signed, so no signature can ever be
 * invalidated by an edit - there is no edit to make.
 */
#[AsAlias(ContractManagerInterface::class)]
class ContractManager implements ContractManagerInterface
{
    /** Same ceiling as a trame's title. */
    protected const int WORDING_TITLE_MAX = 250;

    /**
     * The tokens only an amendment can fill.
     *
     * Grouped under one prefix so the guard is a prefix scan rather than a
     * list to keep in step with the catalogue.
     */
    public const string AMENDS_PREFIX = 'contract.amends_';

    /**
     * The reference a contract carries while it is rendered to be checked,
     * before a real one is drawn. As long as a real one, so a clause that
     * wraps around it wraps the same way.
     */
    public const string PROBE_REFERENCE = 'XXX-0000-0000';

    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly AuditLogger $auditLogger,
        protected readonly ContractVariableResolver $contractVariableResolver,
        protected readonly ContractDocumentRenderer $renderer,
        protected readonly ContractCanonicalizer $canonicalizer,
        protected readonly ContractSeal $seal,
        protected readonly SequenceGenerator $sequenceGenerator,
        protected readonly SettingRepository $settingRepository,
        protected readonly CustomerRepository $customerRepository,
        protected readonly ContractTemplateRepository $templateRepository,
        protected readonly TranslatorInterface $translator,
        protected readonly ContractCustomFieldScanner $customFields,
        protected readonly ContractRetentionPolicy $contractRetentionPolicy,
        protected readonly ContractRepository $contractRepository,
    ) {}

    public function create(ContractInputInterface $input): ContractInterface
    {
        $contract = $this->createContract();
        $this->applyInput($contract, $input);

        $this->entityManager->persist($contract);
        $this->entityManager->flush();

        $this->auditLogger->log('studio', 'contract.created', 'Contract', $contract->getId(), $this->auditPayload($contract));

        return $contract;
    }

    public function update(ContractInterface $contract, ContractInputInterface $input): void
    {
        $contract->assertEditable();

        $this->applyInput($contract, $input);
        $this->entityManager->flush();

        $this->auditLogger->log('studio', 'contract.updated', 'Contract', $contract->getId(), $this->auditPayload($contract));
    }

    /**
     * Turns the choices into the rows a contract pins.
     *
     * The version is resolved here, at draft time, and not at freeze. A
     * template that publishes a new version tomorrow must not silently change
     * a contract somebody is in the middle of preparing - and the screen can
     * say "a newer version exists" precisely because the two are different.
     */
    protected function applyInput(ContractInterface $contract, ContractInputInterface $input): void
    {
        $customer = null === $input->getCustomerId()
            ? null
            : $this->customerRepository->find($input->getCustomerId());

        if (!$customer instanceof CustomerInterface) {
            throw new FieldException('customerId', $this->translator->trans('suite.studio.contracts.errors.customer_required'));
        }

        $contract
            ->setAmends($this->amendedContract($input, $customer))
            ->setCustomer($customer)
            ->setCustomFields($input->getCustomFields())
            ->setLocale($input->getLocale())
            ->setAmountCents($input->getAmountCents())
            ->setAmountCurrency(null === $input->getAmountCurrency() ? null : CurrencyEnum::tryFrom($input->getAmountCurrency()))
            ->setEffectiveDate($this->effectiveDate($input))
            ->setBodyVersion($this->publishedVersionOf($input->getBodyTemplateId(), ContractTemplateKindEnum::Body, 'bodyTemplateId'))
            ->setAnnexVersion($this->publishedVersionOf($input->getAnnexTemplateId(), ContractTemplateKindEnum::Annex, 'annexTemplateId'));

        // Checked here, on the language picker, rather than at the freeze: a
        // contract in Spanish on a trame written only in French was accepted,
        // then its preview and its export failed and its seal was refused.
        foreach ([$contract->getBodyVersion(), $contract->getAnnexVersion()] as $version) {
            if ($version instanceof ContractTemplateVersionInterface && !$version->getTranslation($contract->getLocale()) instanceof ContractTemplateVersionTranslationInterface) {
                throw new FieldException('locale', $this->translator->trans('suite.studio.contracts.errors.locale_missing', ['{locale}' => $contract->getLocale(), '{template}' => $version->getTemplate()->getName()]));
            }
        }
    }

    /**
     * The contract an amendment changes, checked before it is attached.
     *
     * Four refusals, and each is a different mistake:
     *
     * - **Nothing to amend.** A reference that names no row.
     * - **Not concluded.** You do not amend a document nobody signed: while it
     *   is a draft you edit it, and once it is sent you send a new one. An
     *   amendment only makes sense against something that binds.
     * - **Somebody else's contract.** The customer of an amendment is the
     *   customer of what it amends, and the two are checked against each other
     *   rather than trusted to have been picked consistently.
     * - **An amendment of an amendment.** Practice numbers them all against
     *   the original - "avenant n°2 au contrat CM-2026-0001" - and a chain
     *   would make "which annex is in force" a walk instead of a lookup.
     *
     * A terminated contract is refused too: there is nothing left to modify.
     */
    protected function amendedContract(ContractInputInterface $input, CustomerInterface $customer): ?ContractInterface
    {
        $amendsId = $input->getAmendsId();

        if (null === $amendsId) {
            return null;
        }

        $parent = $this->contractRepository->find($amendsId);

        if (!$parent instanceof ContractInterface) {
            throw new FieldException('amendsId', $this->translator->trans('suite.studio.contracts.errors.amends_not_found'));
        }

        if (!$parent->getStatus()->isConcluded()) {
            throw new FieldException('amendsId', $this->translator->trans('suite.studio.contracts.errors.amends_not_concluded'));
        }

        if ($parent->isAmendment()) {
            throw new FieldException('amendsId', $this->translator->trans('suite.studio.contracts.errors.amends_is_amendment'));
        }

        // Refused once the termination has taken effect, not from the day
        // notice was given: during the notice the contract still binds, and an
        // amendment is how its last months get changed.
        if ($parent->isTerminationEffective()) {
            throw new FieldException('amendsId', $this->translator->trans('suite.studio.contracts.errors.amends_terminated'));
        }

        if ($parent->getCustomer()->getId() !== $customer->getId()) {
            throw new FieldException('amendsId', $this->translator->trans('suite.studio.contracts.errors.amends_other_customer'));
        }

        return $parent;
    }

    /**
     * The version in force of a chosen template.
     *
     * A template with nothing published is refused here rather than at freeze,
     * so the refusal lands on the picker that offered it.
     */
    protected function publishedVersionOf(?int $templateId, ContractTemplateKindEnum $kind, string $field): ?ContractTemplateVersionInterface
    {
        if (null === $templateId) {
            return null;
        }

        $template = $this->templateRepository->find($templateId);

        if (!$template instanceof ContractTemplateInterface || $template->getKind() !== $kind) {
            throw new FieldException($field, $this->translator->trans('suite.studio.contracts.errors.template_not_found'));
        }

        $version = $template->getLatestPublishedVersion();

        if (!$version instanceof ContractTemplateVersionInterface) {
            throw new FieldException($field, $this->translator->trans('suite.studio.contracts.errors.template_never_published', ['{template}' => $template->getName()]));
        }

        return $version;
    }

    protected function effectiveDate(ContractInputInterface $input): ?DateTimeImmutable
    {
        $raw = $input->getEffectiveDate();

        if (null === $raw) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);

        // Compared back, because PHP rolls an impossible date over rather
        // than refusing it: 2026-13-45 became the 14th of February 2027.
        if (false === $date || $date->format('Y-m-d') !== $raw) {
            throw new FieldException('effectiveDate', $this->translator->trans('suite.studio.contracts.errors.effective_date_invalid'));
        }

        return $date;
    }

    public function delete(ContractInterface $contract): void
    {
        // A draft, freely. A frozen contract went out to somebody, and
        // deleting the record of what they were sent is not an editing
        // operation - it is the destruction of the only copy that proves what
        // was agreed. It becomes possible when the retention has run out, and
        // not one day before.
        if ($contract->isFrozen()) {
            $this->assertRetentionElapsed($contract);
        }

        $this->auditLogger->log('studio', 'contract.deleted', 'Contract', $contract->getId(), [
            ...$this->auditPayload($contract),
            'wasFrozen' => $contract->isFrozen(),
            'frozenAt' => $contract->getFrozenAt()?->format(DATE_ATOM),
        ]);

        // The signatures first. Their key refuses a cascade on purpose, so
        // that no signature can vanish with a careless delete; the retention
        // having run out is the one moment they may go, and they go here,
        // explicitly. Left to the key, deleting a signed contract answered a
        // 500 however old it was.
        foreach ($this->entityManager->getRepository(ContractSignatureInterface::class)->findBy(['contract' => $contract]) as $signature) {
            $this->entityManager->remove($signature);
        }

        $this->entityManager->remove($contract);
        $this->entityManager->flush();
    }

    /**
     * Withdraws a contract sealed by mistake.
     *
     * Sealed, refused, expired or revoked: nobody has signed and, once its
     * links are revoked, nobody holds an address that opens it. The row stays,
     * reference and document included, marked « Annulé »: the number was drawn
     * and may already have been written down somewhere, and a numbering with a
     * hole is harder to explain than a line that says why.
     */
    public function cancel(ContractInterface $contract): void
    {
        if (!$contract->getStatus()->canBeCancelled()) {
            throw new FieldException('status', $this->translator->trans('suite.studio.contracts.errors.cannot_cancel'));
        }

        $now = new DateTimeImmutable();

        foreach ($this->entityManager->getRepository(ContractAccessLinkInterface::class)->findBy(['contract' => $contract, 'revokedAt' => null]) as $link) {
            $link->revoke($now);
        }

        $contract->setStatus(ContractStatusEnum::Cancelled);
        $this->entityManager->flush();

        $this->auditLogger->log('studio', 'contract.cancelled', 'Contract', $contract->getId(), $this->auditPayload($contract));
    }

    /**
     * A new draft with the same customer, trames, amount, dates and blanks.
     *
     * For the contract to correct: a wrong amount sealed, a refusal to answer.
     * The trames are taken at their version in force today, as any new draft
     * is, and the copy is a draft like any other, sealed when it is ready.
     */
    public function duplicate(ContractInterface $contract): ContractInterface
    {
        $copy = $this->create(new ContractInput(
            customerId: $contract->getCustomer()->getId(),
            bodyTemplateId: $contract->getBodyVersion()?->getTemplate()->getId(),
            annexTemplateId: $contract->getAnnexVersion()?->getTemplate()->getId(),
            locale: $contract->getLocale(),
            amountCents: $contract->getAmountCents(),
            amountCurrency: $contract->getAmountCurrency()?->value,
            effectiveDate: $contract->getEffectiveDate()?->format('Y-m-d'),
            customFields: $contract->getCustomFields(),
            amendsId: $contract->getAmends()?->getId(),
        ));

        // The negotiated text travels with the copy: a contract cancelled to
        // correct one detail would otherwise lose every clause adapted for
        // this client.
        $copy->adoptWordingFrom($contract);

        $this->entityManager->flush();

        $this->auditLogger->log('studio', 'contract.duplicated', 'Contract', $copy->getId(), [
            ...$this->auditPayload($copy),
            'from' => $contract->getReference() ?? $contract->getId(),
        ]);

        return $copy;
    }

    public function terminate(ContractInterface $contract, ContractTerminationInputInterface $input): void
    {
        if (!$contract->getStatus()->isConcluded()) {
            // Nothing to end. A contract that was never concluded is withdrawn
            // by revoking its link, or refused by the customer, and calling
            // either of those a termination would put three different events
            // under one word.
            throw new FieldException('status', $this->translator->trans('suite.studio.contracts.errors.terminate_not_concluded'));
        }

        if ($contract->isTerminated()) {
            throw new FieldException('status', $this->translator->trans('suite.studio.contracts.errors.already_terminated'));
        }

        $origin = ContractTerminationOriginEnum::tryFrom($input->getOrigin());

        if (!$origin instanceof ContractTerminationOriginEnum) {
            throw new FieldException('origin', $this->translator->trans('suite.studio.contracts.errors.termination_origin_invalid'));
        }

        $noticedAt = $this->dateOrFail($input->getNoticedAt(), 'noticedAt');
        $effectiveAt = $this->dateOrFail($input->getEffectiveAt(), 'effectiveAt');

        if ($effectiveAt < $noticedAt) {
            // A notice period runs forward. The other order is not a shorter
            // notice, it is a typo, and storing it would make every report
            // that subtracts the two dates produce a negative period.
            throw new FieldException('effectiveAt', $this->translator->trans('suite.studio.contracts.errors.termination_before_notice'));
        }

        $contract->terminate($noticedAt, $effectiveAt, $origin, $input->getReason());
        $this->entityManager->flush();

        $this->auditLogger->log('studio', 'contract.terminated', 'Contract', $contract->getId(), [
            'reference' => $contract->getReference(),
            'customer' => $contract->getCustomer()->getLegalName(),
            'noticedAt' => $noticedAt->format('Y-m-d'),
            'effectiveAt' => $effectiveAt->format('Y-m-d'),
            'origin' => $origin->value,
            'reason' => $contract->getTerminationReason(),
        ]);
    }

    /** A `Y-m-d` string, or a field error naming the field that carried it. */
    protected function dateOrFail(string $raw, string $field): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);

        if (false === $date) {
            throw new FieldException($field, $this->translator->trans('suite.studio.contracts.errors.termination_date_invalid'));
        }

        return $date;
    }

    public function retentionYears(): int
    {
        return $this->contractRetentionPolicy->years();
    }

    /**
     * Refuses to destroy evidence the retention still covers.
     *
     * The date is in the message, because "not yet" without a date leaves the
     * reader guessing whether they are a day or a decade early. This is the
     * only guard that stands between a signed contract and its own deletion,
     * so it belongs in the manager and not in a controller that a second entry
     * point could bypass.
     */
    protected function assertRetentionElapsed(ContractInterface $contract): void
    {
        if (!$this->contractRetentionPolicy->hasElapsed($contract)) {
            $until = $this->contractRetentionPolicy->until($contract);

            throw new FieldException('status', $this->translator->trans('suite.studio.contracts.errors.retention_not_elapsed', ['{date}' => $until?->format('d/m/Y') ?? '-']));
        }
    }

    /**
     * The contract as it will read, before anything is sealed.
     *
     * Writes nothing, mints nothing, and takes the same road the freeze takes:
     * the same parts in the same order, rendered by the same renderer, with
     * the governing-language clause appended in the same place. A preview
     * assembled separately would be a preview of a different document, which
     * is the one thing it must not be.
     *
     * **The values are the real ones**, not examples. This contract has a
     * customer, an amount and a date, so there is nothing to invent - and a
     * field the customer record leaves blank renders blank here, because that
     * is what the signer would read. Seeing the hole is the point.
     *
     * Two kinds of token cannot be real yet and are shown as slots rather than
     * as blanks: the reference, minted at the freeze, and everything filled at
     * signature. A blank there would read as a defect rather than as a step
     * that has not happened.
     *
     * **Refusals are reported, not thrown.** The freeze stops on an unknown
     * token because it is about to seal it; here, naming them is the service
     * being rendered.
     *
     * @return array{html: string, unknownTokens: list<string>}
     */
    public function preview(ContractInterface $contract): array
    {
        $values = $this->contractVariableResolver->resolve($contract);
        $deferred = $this->contractVariableResolver->deferredTokens();
        $shown = [...$values, ...$this->pendingPlaceholders($contract, $values, $deferred)];

        $html = '';

        foreach ($this->partsOf($contract) as $role => $version) {
            $html .= $this->renderPart($contract, $role, $version, $shown)['html'];
        }

        $governingLocale = $this->governingLocaleOf($contract);

        if (null !== $governingLocale) {
            $html .= $this->governingLanguageClause($contract->getLocale(), $governingLocale);
        }

        return [
            'html' => $html,
            // Checked against the real values, so what comes back is what the
            // freeze would refuse - not an artefact of the placeholders.
            'unknownTokens' => $this->renderer->unknownTokens($html, $values, $deferred),
        ];
    }

    /**
     * What is not knowable yet, drawn as a slot.
     *
     * `[référence]` rather than nothing, because an empty space where the
     * contract number belongs reads as a bug to whoever is proofreading.
     *
     * @param array<string, string> $values
     * @param list<string>          $deferred
     *
     * @return array<string, string>
     */
    protected function pendingPlaceholders(ContractInterface $contract, array $values, array $deferred): array
    {
        $pending = [];

        foreach ($deferred as $token) {
            $pending[$token] = $this->slot($token);
        }

        // Only while it is still unminted: an amendment carries its parent's
        // reference from the day it is created.
        if ('' === ($values['contract.reference'] ?? '')) {
            $pending['contract.reference'] = $this->slot('contract.reference');
        }

        return $pending;
    }

    private function slot(string $token): string
    {
        $name = str_contains($token, '.') ? mb_substr($token, (int) mb_strrpos($token, '.') + 1) : $token;

        return sprintf('[%s]', str_replace('_', ' ', $name));
    }

    public function freeze(ContractInterface $contract): void
    {
        $contract->assertEditable();

        $body = $contract->getBodyVersion();

        if (!$body instanceof ContractTemplateVersionInterface) {
            throw new FieldException('bodyVersion', $this->translator->trans('suite.studio.contracts.errors.body_required'));
        }

        // Only published wording can be sent. A draft version is work in
        // progress by definition, and freezing one would seal a document its
        // author had not finished writing.
        $this->assertPublished($body, 'bodyVersion');

        $annex = $contract->getAnnexVersion();

        if ($annex instanceof ContractTemplateVersionInterface) {
            $this->assertPublished($annex, 'annexVersion');
        }

        // Checked again here and at each signature, not only when the parent
        // was picked: a contract can end between the day its amendment is
        // drafted and the day it is sealed.
        if ($contract->getAmends()?->isTerminationEffective() ?? false) {
            throw new FieldException('amendsId', $this->translator->trans('suite.studio.contracts.errors.amends_terminated'));
        }

        // Checked before a reference is minted: a contract refused here has
        // consumed nothing, and the sequence has no gap to explain.
        $missing = $this->missingCustomFields($contract);

        if ([] !== $missing) {
            throw new FieldException('customFields', $this->translator->trans('suite.studio.contracts.errors.custom_fields_missing', ['{fields}' => implode(', ', $missing)]));
        }

        // The provider's own identity comes from the settings, so a blank
        // there is not something this screen can fix. Named as a settings
        // problem rather than as an unknown token, which is what it looks like
        // from the renderer's side.
        $unsetProvider = $this->unsetProviderSettings($contract, $this->contractVariableResolver->providerValues());

        if ([] !== $unsetProvider) {
            throw new FieldException('bodyVersion', $this->translator->trans('suite.studio.contracts.errors.provider_settings_missing', ['{fields}' => implode(', ', $unsetProvider)]));
        }

        // Refused before a reference is minted, like every other guard here: a
        // trame written as an amendment, sealed as a standalone contract, would
        // print blanks where it names the document it modifies.
        $unsetAmendment = $this->unsetAmendmentTokens($contract);

        if ([] !== $unsetAmendment) {
            throw new FieldException('amendsId', $this->translator->trans('suite.studio.contracts.errors.amendment_tokens_without_parent', ['{fields}' => implode(', ', $unsetAmendment)]));
        }

        // Rendered once with a stand-in reference, so every refusal below
        // (a language the trame lacks, a block it cannot print, a variable
        // that fills nothing) lands before a number is drawn. The sequence is
        // committed as soon as it is incremented, and a refused seal used to
        // leave a gap nobody could explain.
        $previous = $contract->getReference();
        $contract->setReference($this->amendmentReference($contract) ?? self::PROBE_REFERENCE);

        try {
            $this->renderDocument($contract);
        } finally {
            $contract->setReference($previous);
        }

        // Minted before the real rendering, because the reference is printed
        // inside the document and therefore has to be part of what the hash
        // covers.
        $reference = $this->amendmentReference($contract) ?? $this->nextReference();
        $contract->setReference($reference);

        ['values' => $values, 'deferred' => $deferred, 'parts' => $parts, 'html' => $html, 'governingLocale' => $governingLocale] = $this->renderDocument($contract);

        $snapshot = [
            'canonicalVersion' => ContractCanonicalizer::VERSION,
            'locale' => $contract->getLocale(),
            'governingLocale' => $governingLocale,
            'reference' => $reference,
            'customerId' => $contract->getCustomer()->getId(),
            'values' => $values,
            'customFields' => $contract->getCustomFields(),
            'deferredTokens' => $deferred,
            'parts' => $parts,
        ];

        $contract->freeze(
            new DateTimeImmutable(),
            $reference,
            $snapshot,
            $html,
            $this->seal->hash($snapshot, $html),
            ContractCanonicalizer::ALGO,
            ContractCanonicalizer::VERSION,
        );

        $this->entityManager->flush();

        $this->auditLogger->log('studio', 'contract.frozen', 'Contract', $contract->getId(), [
            ...$this->auditPayload($contract),
            'contentHash' => $contract->getContentHash(),
            'bodyVersion' => $body->getNumber(),
            'annexVersion' => $annex?->getNumber(),
        ]);
    }

    /**
     * The document as it would be sealed, refused if it cannot be.
     *
     * @return array{values: array<string, string>, deferred: list<string>, parts: list<array<string, mixed>>, html: string, governingLocale: ?string}
     *
     * @throws FieldException when a part cannot be rendered or a variable fills nothing
     */
    protected function renderDocument(ContractInterface $contract): array
    {
        $values = $this->contractVariableResolver->resolve($contract);
        $deferred = $this->contractVariableResolver->deferredTokens();

        $parts = [];
        $html = '';

        foreach ($this->partsOf($contract) as $role => $version) {
            $part = $this->renderPart($contract, $role, $version, $values);
            $parts[] = $part['snapshot'];
            $html .= $part['html'];
        }

        // Appended after the last part, which is where a governing-language
        // clause belongs on paper too: after everything it arbitrates.
        $governingLocale = $this->governingLocaleOf($contract);

        if (null !== $governingLocale) {
            $html .= $this->governingLanguageClause($contract->getLocale(), $governingLocale);
        }

        $unknown = $this->renderer->unknownTokens($html, $values, $deferred);

        if ([] !== $unknown) {
            // Named, and refused. A token nobody will ever fill would reach the
            // signer as literal braces in the middle of a clause, and by then
            // the document is sealed.
            throw new FieldException('bodyVersion', $this->translator->trans('suite.studio.contracts.errors.unknown_tokens', ['{tokens}' => implode(', ', $unknown)]));
        }

        return ['values' => $values, 'deferred' => $deferred, 'parts' => $parts, 'html' => $html, 'governingLocale' => $governingLocale];
    }

    /**
     * The blanks the chosen trames ask for and this contract has not filled.
     *
     * Read from the wording, which is where the question is asked: a version
     * using `{{contract.custom.acompte}}` makes `acompte` mandatory, and no
     * list kept elsewhere can drift away from it.
     *
     * An empty string counts as missing. A trame asks for a value because the
     * sentence around it needs one, and sealing "un acompte de  % à la
     * signature" would produce a signed document with a hole in it.
     *
     * @return list<string>
     */
    protected function missingCustomFields(ContractInterface $contract): array
    {
        $filled = [];

        foreach ($contract->getCustomFields() as $key => $value) {
            if ('' !== $value) {
                $filled[$key] = true;
            }
        }

        $missing = [];

        foreach ($this->keysInParts($contract) as $key) {
            if (!isset($filled[$key])) {
                $missing[$key] = true;
            }
        }

        return array_keys($missing);
    }

    /**
     * The provider tokens a trame asks for and the settings do not hold.
     *
     * Read from the resolved values rather than from the settings directly:
     * the resolver already decided what counts as set, and asking twice is how
     * two answers appear.
     *
     * @param array<string, string> $values
     *
     * @return list<string>
     */
    protected function unsetProviderSettings(ContractInterface $contract, array $values): array
    {
        $missing = [];

        foreach ($this->keysInParts($contract, ContractCustomFieldScanner::PROVIDER_PREFIX) as $key) {
            $token = ContractCustomFieldScanner::PROVIDER_PREFIX.$key;

            if (!isset($values[$token])) {
                $missing[$token] = true;
            }
        }

        return array_keys($missing);
    }

    /**
     * The language that prevails over the whole document.
     *
     * Read from the parts rather than stored on the contract, because it is a
     * property of the wording: a trame written in three languages answered the
     * question when it was published, and a contract built from it inherits the
     * answer.
     *
     * Parts that name none are simply single-language wordings and have nothing
     * to say here. Parts that name two different ones are a contradiction
     * nobody can resolve at freeze time, and sealing it would produce a
     * document whose body and annex each claim authority. Refused, named.
     *
     * @throws FieldException when the parts disagree
     */
    protected function governingLocaleOf(ContractInterface $contract): ?string
    {
        $declared = [];

        foreach ($this->partsOf($contract) as $version) {
            $locale = $version->getGoverningLocale();

            if (null !== $locale) {
                $declared[$locale] = true;
            }
        }

        if (1 < count($declared)) {
            throw new FieldException('annexVersion', $this->translator->trans('suite.studio.contracts.errors.governing_locale_conflict', ['{locales}' => implode(', ', array_keys($declared))]));
        }

        return array_key_first($declared);
    }

    /**
     * The clause, written in the language of the document that carries it.
     *
     * Two sentences rather than one when the reader is holding a translation:
     * somebody signing the Spanish version of a French contract is entitled to
     * be told, in Spanish, that the French text is the one a judge will read.
     */
    protected function governingLanguageClause(string $documentLocale, string $governingLocale): string
    {
        $language = $this->translator->trans('studio.contract.language.'.$governingLocale, [], null, $documentLocale);

        $paragraphs = [
            $this->translator->trans('studio.contract.governing_language.body', ['{language}' => $language], null, $documentLocale),
        ];

        if ($documentLocale !== $governingLocale) {
            $paragraphs[] = $this->translator->trans('studio.contract.governing_language.translation_notice', [
                '{language}' => $this->translator->trans('studio.contract.language.'.$documentLocale, [], null, $documentLocale),
            ], null, $documentLocale);
        }

        return $this->renderer->governingLanguageSection(
            $this->translator->trans('studio.contract.governing_language.heading', [], null, $documentLocale),
            $paragraphs,
        );
    }

    /**
     * The versions that make up the document, body first.
     *
     * Order is part of the document: an annex printed before the body it
     * annexes is a different document, and the hash would say so.
     *
     * @return array<string, ContractTemplateVersionInterface>
     */
    protected function partsOf(ContractInterface $contract): array
    {
        $parts = [];
        $body = $contract->getBodyVersion();
        $annex = $contract->getAnnexVersion();

        if ($body instanceof ContractTemplateVersionInterface) {
            $parts[ContractTemplateKindEnum::Body->value] = $body;
        }

        if ($annex instanceof ContractTemplateVersionInterface) {
            $parts[ContractTemplateKindEnum::Annex->value] = $annex;
        }

        return $parts;
    }

    /**
     * One part, as both the structure it came from and the HTML it produced.
     *
     * @param array<string, string> $values
     *
     * @return array{snapshot: array<string, mixed>, html: string}
     */
    protected function renderPart(
        ContractInterface $contract,
        string $role,
        ContractTemplateVersionInterface $version,
        array $values,
    ): array {
        $locale = $contract->getLocale();
        ['title' => $wordingTitle, 'blocks' => $blocks, 'adapted' => $adapted] = $this->wordingOf($contract, $role, $version);

        try {
            $body = $this->renderer->render($blocks, $values);
        } catch (UnrenderableBlockException $unrenderableBlockException) {
            // Turned into a field error rather than left to bubble: this is a
            // template somebody has to go and fix, and a 500 does not say
            // which block of which trame.
            throw new FieldException('bodyVersion', sprintf('%s (%s)', $unrenderableBlockException->describe($this->translator, $locale), $version->getTemplate()->getName()));
        }

        $title = $this->renderer->title($wordingTitle, $values);

        return [
            'snapshot' => [
                'role' => $role,
                // Sealed as part of the record: whoever reads this contract in
                // ten years learns that its text is not the trame's.
                'adapted' => $adapted,
                'templateId' => $version->getTemplate()->getId(),
                'templateName' => $version->getTemplate()->getName(),
                'versionId' => $version->getId(),
                'versionNumber' => $version->getNumber(),
                'title' => $title,
                'blocks' => $blocks,
            ],
            'html' => sprintf('<section><h1>%s</h1>%s</section>', $title, $body),
        ];
    }

    /**
     * The text a part of this contract is made of: its adaptation when it has
     * one in the contract's language, the trame's otherwise.
     *
     * The one place that answers. Rendering, sealing and the checks before
     * the seal all ask here, so none of them can read one text while another
     * is sealed.
     *
     * @return array{title: string, blocks: list<mixed>, adapted: bool, content: array<string, mixed>}
     */
    protected function wordingOf(ContractInterface $contract, string $role, ContractTemplateVersionInterface $version): array
    {
        $locale = $contract->getLocale();
        $part = ContractTemplateKindEnum::from($role);
        $adapted = $contract->getAdaptedWording($part);

        if (null !== $adapted && $adapted['locale'] === $locale) {
            return ['title' => $adapted['title'], 'blocks' => $adapted['blocks'], 'adapted' => true, 'content' => ['blocks' => $adapted['blocks']]];
        }

        $translation = $version->getTranslation($locale);

        if (!$translation instanceof ContractTemplateVersionTranslationInterface) {
            throw new FieldException('locale', $this->translator->trans('suite.studio.contracts.errors.locale_missing', ['{locale}' => $locale, '{template}' => $version->getTemplate()->getName()]));
        }

        $content = $translation->getContent();
        $blocks = is_array($content['blocks'] ?? null) ? array_values($content['blocks']) : [];

        return ['title' => $translation->getTitle(), 'blocks' => $blocks, 'adapted' => false, 'content' => $content];
    }

    /**
     * The tokens of one family the contract's parts ask for.
     *
     * A trame's part is read in every language it is written in, as before:
     * the preparation screen cannot know which one will be chosen. An adapted
     * part is read as adapted, in the one language it was written in.
     *
     * @return list<string>
     */
    protected function keysInParts(ContractInterface $contract, string $prefix = ContractCustomFieldScanner::PREFIX): array
    {
        $keys = [];

        foreach ($this->partsOf($contract) as $role => $version) {
            $adapted = $contract->getAdaptedWording(ContractTemplateKindEnum::from($role));

            $found = null !== $adapted && $adapted['locale'] === $contract->getLocale()
                ? $this->customFields->keysInWording($adapted['title'], ['blocks' => $adapted['blocks']], $prefix)
                : $this->customFields->keysOf($version, $prefix);

            foreach ($found as $key) {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }

    public function adaptWording(ContractInterface $contract, ContractTemplateKindEnum $part, string $title, array $content): void
    {
        $contract->assertEditable();

        $version = $this->partsOf($contract)[$part->value] ?? null;

        if (!$version instanceof ContractTemplateVersionInterface) {
            throw new FieldException('part', $this->translator->trans('suite.studio.contracts.wording.errors.no_part'));
        }

        $title = mb_trim($title);

        if ('' === $title) {
            throw new FieldException('title', $this->translator->trans('suite.studio.contracts.wording.errors.title_required'));
        }

        if (mb_strlen($title) > self::WORDING_TITLE_MAX) {
            throw new FieldException('title', $this->translator->trans('suite.studio.contracts.wording.errors.title_too_long', ['{max}' => (string) self::WORDING_TITLE_MAX]));
        }

        $blocks = is_array($content['blocks'] ?? null) ? array_values($content['blocks']) : [];

        if ([] === $blocks) {
            throw new FieldException('content', $this->translator->trans('suite.studio.contracts.wording.errors.empty'));
        }

        $this->assertWordingPrintable($contract, $title, $blocks);

        $contract->adaptWording($part, $title, $blocks, new DateTimeImmutable());
        $this->entityManager->flush();

        $this->auditLogger->log('studio', 'contract.wording_adapted', 'Contract', $contract->getId(), [
            ...$this->auditPayload($contract),
            'part' => $part->value,
            'template' => $version->getTemplate()->getName(),
            'versionNumber' => $version->getNumber(),
            'blocks' => count($blocks),
        ]);
    }

    public function resetWording(ContractInterface $contract, ContractTemplateKindEnum $part): void
    {
        $contract->assertEditable();

        if (!$contract->isAdapted($part)) {
            return;
        }

        $contract->resetWording($part);
        $this->entityManager->flush();

        $this->auditLogger->log('studio', 'contract.wording_reset', 'Contract', $contract->getId(), [
            ...$this->auditPayload($contract),
            'part' => $part->value,
        ]);
    }

    /**
     * Refuses a text the seal would refuse, at the moment it is written.
     *
     * Held to the trame's rule: only blocks a contract can print, only
     * variables the catalogue knows. A per-contract field
     * (`{{contract.custom.…}}`) is allowed, and becomes one more blank the
     * contract must fill before it is sealed.
     *
     * @param list<mixed> $blocks
     */
    protected function assertWordingPrintable(ContractInterface $contract, string $title, array $blocks): void
    {
        $values = [...$this->contractVariableResolver->examples(), ...$this->contractVariableResolver->resolve($contract)];

        foreach ($this->customFields->keysInWording($title, ['blocks' => $blocks]) as $key) {
            $values[ContractCustomFieldScanner::PREFIX.$key] = sprintf('[%s]', $key);
        }

        try {
            $html = $this->renderer->title($title, $values).$this->renderer->render($blocks, $values);
        } catch (UnrenderableBlockException $unrenderableBlockException) {
            throw new FieldException('content', $unrenderableBlockException->describe($this->translator, $contract->getLocale()));
        }

        $unknown = $this->renderer->unknownTokens($html, $values, $this->contractVariableResolver->deferredTokens());

        if ([] !== $unknown) {
            throw new FieldException('content', $this->translator->trans('suite.studio.contracts.errors.unknown_tokens', ['{tokens}' => implode(', ', array_map(static fn (string $token): string => sprintf('{{%s}}', $token), $unknown))]));
        }
    }

    protected function assertPublished(ContractTemplateVersionInterface $version, string $field): void
    {
        if ($version->isPublished()) {
            return;
        }

        throw new FieldException($field, $this->translator->trans('suite.studio.contracts.errors.version_not_published', ['{template}' => $version->getTemplate()->getName(), '{number}' => (string) $version->getNumber()]));
    }

    /**
     * `CM-2026-0001`, or whatever prefix the settings carry.
     *
     * Yearly, because that is how a contract is quoted and filed, and because
     * the paper process this replaces already numbered them that way.
     */
    protected function nextReference(): string
    {
        $prefix = $this->settingRepository->get(
            ApplicationParameterEnum::StudioContractPrefix->value,
            SequencePrefixEnum::Contract->value,
        ) ?? SequencePrefixEnum::Contract->value;

        return $this->sequenceGenerator->nextYearly($prefix, (int) new DateTimeImmutable()->format('Y'));
    }

    /**
     * `CM-2026-0001-A1`, when this contract amends another.
     *
     * Derived from the parent rather than drawn from the sequence, so the
     * reference itself carries the parentage: a line in an accounting export
     * says what it belongs to without a join. The rank is counted over the
     * parent's *sealed* amendments, so a draft abandoned before it went
     * anywhere consumes nothing.
     */
    protected function amendmentReference(ContractInterface $contract): ?string
    {
        $parentReference = $contract->getAmendsReference();

        if (null === $parentReference) {
            return null;
        }

        $parent = $contract->getAmends();
        $rank = 1 + ($parent instanceof ContractInterface ? $this->contractRepository->countSealedAmendmentsOf($parent) : 0);

        $contract->setAmendmentRank($rank);

        return sprintf('%s-A%d', $parentReference, $rank);
    }

    /**
     * The amendment tokens a wording asks for that this contract cannot fill.
     *
     * The same shape as the provider-settings guard, and for the same reason:
     * the token is known to the catalogue, so the renderer would happily print
     * an empty string and seal a document that names no parent. This turns
     * that into a refusal that says which trame was chosen by mistake.
     *
     * @return list<string>
     */
    protected function unsetAmendmentTokens(ContractInterface $contract): array
    {
        if ($contract->isAmendment()) {
            return [];
        }

        $used = [];

        foreach ($this->keysInParts($contract, self::AMENDS_PREFIX) as $key) {
            $used[self::AMENDS_PREFIX.$key] = true;
        }

        return array_keys($used);
    }

    protected function createContract(): ContractInterface
    {
        return new Contract();
    }

    /** @return array<string, mixed> */
    protected function auditPayload(ContractInterface $contract): array
    {
        return [
            'reference' => $contract->getReference(),
            'customer' => $contract->getCustomer()->getLegalName(),
            'status' => $contract->getStatus()->value,
            'locale' => $contract->getLocale(),
        ];
    }
}
