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
use Aurora\Module\Studio\Contract\Dto\ContractInputInterface;
use Aurora\Module\Studio\Contract\Entity\Contract;
use Aurora\Module\Studio\Contract\Entity\ContractInterface;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateInterface;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateVersionInterface;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateVersionTranslationInterface;
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
use Aurora\Module\Studio\Contract\Termination\Dto\ContractTerminationInputInterface;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\Customer\Repository\CustomerRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\Translation\TranslatorInterface;

use function implode;
use function is_array;
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
    /**
     * The tokens only an amendment can fill.
     *
     * Grouped under one prefix so the guard is a prefix scan rather than a
     * list to keep in step with the catalogue.
     */
    public const string AMENDS_PREFIX = 'contract.amends_';

    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly AuditLogger $auditLogger,
        protected readonly ContractVariableResolver $variables,
        protected readonly ContractDocumentRenderer $renderer,
        protected readonly ContractCanonicalizer $canonicalizer,
        protected readonly ContractSeal $seal,
        protected readonly SequenceGenerator $sequenceGenerator,
        protected readonly SettingRepository $settingRepository,
        protected readonly CustomerRepository $customerRepository,
        protected readonly ContractTemplateRepository $templateRepository,
        protected readonly TranslatorInterface $translator,
        protected readonly ContractCustomFieldScanner $customFields,
        protected readonly ContractRetentionPolicy $retention,
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
            throw new FieldException('customerId', $this->translator->trans('backend.studio.contracts.errors.customer_required'));
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
            throw new FieldException('amendsId', $this->translator->trans('backend.studio.contracts.errors.amends_not_found'));
        }

        if (!$parent->getStatus()->isConcluded()) {
            throw new FieldException('amendsId', $this->translator->trans('backend.studio.contracts.errors.amends_not_concluded'));
        }

        if ($parent->isAmendment()) {
            throw new FieldException('amendsId', $this->translator->trans('backend.studio.contracts.errors.amends_is_amendment'));
        }

        if ($parent->isTerminated()) {
            throw new FieldException('amendsId', $this->translator->trans('backend.studio.contracts.errors.amends_terminated'));
        }

        if ($parent->getCustomer()->getId() !== $customer->getId()) {
            throw new FieldException('amendsId', $this->translator->trans('backend.studio.contracts.errors.amends_other_customer'));
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
            throw new FieldException($field, $this->translator->trans('backend.studio.contracts.errors.template_not_found'));
        }

        $version = $template->getLatestPublishedVersion();

        if (!$version instanceof ContractTemplateVersionInterface) {
            throw new FieldException($field, $this->translator->trans('backend.studio.contracts.errors.template_never_published', ['{template}' => $template->getName()]));
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

        if (false === $date) {
            throw new FieldException('effectiveDate', $this->translator->trans('backend.studio.contracts.errors.effective_date_invalid'));
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

        $this->entityManager->remove($contract);
        $this->entityManager->flush();
    }

    public function terminate(ContractInterface $contract, ContractTerminationInputInterface $input): void
    {
        if (!$contract->getStatus()->isConcluded()) {
            // Nothing to end. A contract that was never concluded is withdrawn
            // by revoking its link, or refused by the customer, and calling
            // either of those a termination would put three different events
            // under one word.
            throw new FieldException('status', $this->translator->trans('backend.studio.contracts.errors.terminate_not_concluded'));
        }

        if ($contract->isTerminated()) {
            throw new FieldException('status', $this->translator->trans('backend.studio.contracts.errors.already_terminated'));
        }

        $origin = ContractTerminationOriginEnum::tryFrom($input->getOrigin());

        if (!$origin instanceof ContractTerminationOriginEnum) {
            throw new FieldException('origin', $this->translator->trans('backend.studio.contracts.errors.termination_origin_invalid'));
        }

        $noticedAt = $this->dateOrFail($input->getNoticedAt(), 'noticedAt');
        $effectiveAt = $this->dateOrFail($input->getEffectiveAt(), 'effectiveAt');

        if ($effectiveAt < $noticedAt) {
            // A notice period runs forward. The other order is not a shorter
            // notice, it is a typo, and storing it would make every report
            // that subtracts the two dates produce a negative period.
            throw new FieldException('effectiveAt', $this->translator->trans('backend.studio.contracts.errors.termination_before_notice'));
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
            throw new FieldException($field, $this->translator->trans('backend.studio.contracts.errors.termination_date_invalid'));
        }

        return $date;
    }

    public function retentionYears(): int
    {
        return $this->retention->years();
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
        if (!$this->retention->hasElapsed($contract)) {
            $until = $this->retention->until($contract);

            throw new FieldException('status', $this->translator->trans('backend.studio.contracts.errors.retention_not_elapsed', ['{date}' => $until?->format('d/m/Y') ?? '-']));
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
        $values = $this->variables->resolve($contract);
        $deferred = $this->variables->deferredTokens();
        $shown = [...$values, ...$this->pendingPlaceholders($contract, $values, $deferred)];

        $html = '';

        foreach ($this->partsOf($contract) as $role => $version) {
            $html .= $this->renderPart($role, $version, $contract->getLocale(), $shown)['html'];
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
            throw new FieldException('bodyVersion', $this->translator->trans('backend.studio.contracts.errors.body_required'));
        }

        // Only published wording can be sent. A draft version is work in
        // progress by definition, and freezing one would seal a document its
        // author had not finished writing.
        $this->assertPublished($body, 'bodyVersion');

        $annex = $contract->getAnnexVersion();

        if ($annex instanceof ContractTemplateVersionInterface) {
            $this->assertPublished($annex, 'annexVersion');
        }

        // Checked before a reference is minted: a contract refused here has
        // consumed nothing, and the sequence has no gap to explain.
        $missing = $this->missingCustomFields($contract);

        if ([] !== $missing) {
            throw new FieldException('customFields', $this->translator->trans('backend.studio.contracts.errors.custom_fields_missing', ['{fields}' => implode(', ', $missing)]));
        }

        // The provider's own identity comes from the settings, so a blank
        // there is not something this screen can fix. Named as a settings
        // problem rather than as an unknown token, which is what it looks like
        // from the renderer's side.
        $unsetProvider = $this->unsetProviderSettings($contract, $this->variables->providerValues());

        if ([] !== $unsetProvider) {
            throw new FieldException('bodyVersion', $this->translator->trans('backend.studio.contracts.errors.provider_settings_missing', ['{fields}' => implode(', ', $unsetProvider)]));
        }

        // Refused before a reference is minted, like every other guard here: a
        // trame written as an amendment, sealed as a standalone contract, would
        // print blanks where it names the document it modifies.
        $unsetAmendment = $this->unsetAmendmentTokens($contract);

        if ([] !== $unsetAmendment) {
            throw new FieldException('amendsId', $this->translator->trans('backend.studio.contracts.errors.amendment_tokens_without_parent', ['{fields}' => implode(', ', $unsetAmendment)]));
        }

        // Minted before the rendering, because the reference is printed inside
        // the document and therefore has to be part of what the hash covers.
        $reference = $this->amendmentReference($contract) ?? $this->nextReference();
        $contract->setReference($reference);

        $values = $this->variables->resolve($contract);
        $deferred = $this->variables->deferredTokens();

        $parts = [];
        $html = '';

        foreach ($this->partsOf($contract) as $role => $version) {
            $part = $this->renderPart($role, $version, $contract->getLocale(), $values);
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
            throw new FieldException('bodyVersion', $this->translator->trans('backend.studio.contracts.errors.unknown_tokens', ['{tokens}' => implode(', ', $unknown)]));
        }

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

        foreach ($this->partsOf($contract) as $version) {
            foreach ($this->customFields->keysOf($version) as $key) {
                if (!isset($filled[$key])) {
                    $missing[$key] = true;
                }
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

        foreach ($this->partsOf($contract) as $version) {
            foreach ($this->customFields->keysOf($version, ContractCustomFieldScanner::PROVIDER_PREFIX) as $key) {
                $token = ContractCustomFieldScanner::PROVIDER_PREFIX.$key;

                if (!isset($values[$token])) {
                    $missing[$token] = true;
                }
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
            throw new FieldException('annexVersion', $this->translator->trans('backend.studio.contracts.errors.governing_locale_conflict', ['{locales}' => implode(', ', array_keys($declared))]));
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
        string $role,
        ContractTemplateVersionInterface $version,
        string $locale,
        array $values,
    ): array {
        $translation = $version->getTranslation($locale);

        if (!$translation instanceof ContractTemplateVersionTranslationInterface) {
            throw new FieldException('locale', $this->translator->trans('backend.studio.contracts.errors.locale_missing', ['{locale}' => $locale, '{template}' => $version->getTemplate()->getName()]));
        }

        $content = $translation->getContent();
        $blocks = is_array($content['blocks'] ?? null) ? $content['blocks'] : [];

        try {
            $body = $this->renderer->render($blocks, $values);
        } catch (UnrenderableBlockException $unrenderableBlockException) {
            // Turned into a field error rather than left to bubble: this is a
            // template somebody has to go and fix, and a 500 does not say
            // which block of which trame.
            throw new FieldException('bodyVersion', sprintf('%s (%s)', $unrenderableBlockException->getMessage(), $version->getTemplate()->getName()));
        }

        $title = $this->renderer->substitute($translation->getTitle(), $values);

        return [
            'snapshot' => [
                'role' => $role,
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

    protected function assertPublished(ContractTemplateVersionInterface $version, string $field): void
    {
        if ($version->isPublished()) {
            return;
        }

        throw new FieldException($field, $this->translator->trans('backend.studio.contracts.errors.version_not_published', ['{template}' => $version->getTemplate()->getName(), '{number}' => (string) $version->getNumber()]));
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

        foreach ($this->partsOf($contract) as $version) {
            foreach ($this->customFields->keysOf($version, self::AMENDS_PREFIX) as $key) {
                $used[self::AMENDS_PREFIX.$key] = true;
            }
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
