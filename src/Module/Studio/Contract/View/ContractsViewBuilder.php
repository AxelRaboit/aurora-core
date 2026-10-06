<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\View;

use Aurora\Core\Locale\Service\LocaleOptionsProviderInterface;
use Aurora\Core\Money\Enum\CurrencyEnum;
use Aurora\Core\Routing\PathTemplateGenerator;
use Aurora\Module\Studio\Contract\Entity\ContractInterface;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateInterface;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateVersionInterface;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateVersionTranslationInterface;
use Aurora\Module\Studio\Contract\Enum\ContractTemplateKindEnum;
use Aurora\Module\Studio\Contract\Enum\ContractTerminationOriginEnum;
use Aurora\Module\Studio\Contract\Repository\ContractRepository;
use Aurora\Module\Studio\Contract\Repository\ContractTemplateRepository;
use Aurora\Module\Studio\Contract\Serializer\ContractSerializerInterface;
use Aurora\Module\Studio\Contract\Service\ContractCustomFieldScanner;
use Aurora\Module\Studio\Contract\Service\ContractLinkLifetime;
use Aurora\Module\Studio\Contract\Service\ContractVariableCatalogue;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\Customer\Repository\CustomerRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class ContractsViewBuilder
{
    public function __construct(
        private ContractRepository $contractRepository,
        private ContractTemplateRepository $templateRepository,
        private CustomerRepository $customerRepository,
        private ContractSerializerInterface $serializer,
        private LocaleOptionsProviderInterface $localeOptions,
        private PathTemplateGenerator $pathTemplates,
        private UrlGeneratorInterface $urlGenerator,
        private ContractCustomFieldScanner $customFields,
        private ContractVariableCatalogue $variables,
        private ContractLinkLifetime $linkLifetime,
    ) {}

    /**
     * The page where one part of a contract is adapted for its client.
     *
     * Carries both texts: the trame's, which is what a reset goes back to
     * and what the differences are measured against, and the adapted one when
     * there is one. The editor opens on the adapted text, or on a copy of the
     * trame's for a first adaptation.
     *
     * @return array<string, mixed>
     */
    public function wordingView(ContractInterface $contract, ContractTemplateKindEnum $part): array
    {
        $id = $contract->getId();
        $version = $this->versionFor($contract, $part);
        $translation = $version?->getTranslation($contract->getLocale());
        $adapted = $contract->getAdaptedWording($part);

        $parts = [];
        foreach (ContractTemplateKindEnum::cases() as $each) {
            $eachVersion = $this->versionFor($contract, $each);

            if ($eachVersion instanceof ContractTemplateVersionInterface) {
                $parts[] = [
                    'key' => $each->value,
                    'templateName' => $eachVersion->getTemplate()->getName(),
                    'versionNumber' => $eachVersion->getNumber(),
                    'isAdapted' => $contract->isAdapted($each),
                    'path' => $this->urlGenerator->generate('suite_studio_contracts_wording', ['id' => $id, 'part' => $each->value]),
                ];
            }
        }

        $content = $translation instanceof ContractTemplateVersionTranslationInterface ? $translation->getContent() : [];

        return [
            'contract' => $this->serializer->serialize($contract),
            'part' => $part->value,
            'parts' => $parts,
            'template' => $version instanceof ContractTemplateVersionInterface ? [
                'id' => $version->getTemplate()->getId(),
                'name' => $version->getTemplate()->getName(),
                'versionId' => $version->getId(),
                'versionNumber' => $version->getNumber(),
            ] : null,
            'original' => $translation instanceof ContractTemplateVersionTranslationInterface ? [
                'title' => $translation->getTitle(),
                'blocks' => array_values(is_array($content['blocks'] ?? null) ? $content['blocks'] : []),
            ] : null,
            'adapted' => null === $adapted ? null : [
                'title' => $adapted['title'],
                'blocks' => $adapted['blocks'],
                'adaptedAt' => $adapted['adaptedAt'],
                'baseVersionId' => $adapted['baseVersionId'],
            ],
            'locale' => $contract->getLocale(),
            'variableGroups' => $this->variables->groups(),
            'savePath' => $this->urlGenerator->generate('suite_studio_contracts_wording_save', ['id' => $id, 'part' => $part->value]),
            'resetPath' => $this->urlGenerator->generate('suite_studio_contracts_wording_reset', ['id' => $id, 'part' => $part->value]),
            'showPath' => $this->urlGenerator->generate('suite_studio_contracts_show', ['id' => $id]),
            'templateVersionPath' => $this->pathTemplates->generate('suite_studio_contract_templates_editor', ['id' => '__id__', 'versionId' => '__versionId__']),
        ];
    }

    private function versionFor(ContractInterface $contract, ContractTemplateKindEnum $part): ?ContractTemplateVersionInterface
    {
        return ContractTemplateKindEnum::Body === $part ? $contract->getBodyVersion() : $contract->getAnnexVersion();
    }

    /** @return array<string, mixed> */
    public function indexView(): array
    {
        // Read once for the rows and for the choice of a parent to amend,
        // which both walk every contract.
        $all = $this->contractRepository->findAllForIndex();

        return [
            'contracts' => $this->serializer->serializeMany($all),
            'customers' => $this->customerOptions(),
            // Only what can actually produce a document: live templates with
            // something published. Offering the rest means offering a dead end.
            'bodies' => $this->templateOptions(ContractTemplateKindEnum::Body),
            'annexes' => $this->templateOptions(ContractTemplateKindEnum::Annex),
            'locales' => $this->localeOptions->getActiveOptions(),
            'currencies' => $this->currencyOptions(),
            'createPath' => $this->urlGenerator->generate('suite_studio_contracts_create'),
            'updatePath' => $this->pathTemplates->generate('suite_studio_contracts_update', ['id' => '__id__']),
            'previewPath' => $this->pathTemplates->generate('suite_studio_contracts_preview', ['id' => '__id__']),
            'duplicatePath' => $this->pathTemplates->generate('suite_studio_contracts_duplicate', ['id' => '__id__']),
            'pdfPath' => $this->pathTemplates->generate('suite_studio_contracts_pdf', ['id' => '__id__']),
            // One address for every row, whatever state it is in: the signed
            // file when there is one, a working copy otherwise.
            'exportPath' => $this->pathTemplates->generate('suite_studio_contracts_export', ['id' => '__id__']),
            // The contract's own screen, where every other gesture is.
            'showPath' => $this->pathTemplates->generate('suite_studio_contracts_show', ['id' => '__id__']),
            // What an amendment may be attached to. Only concluded, running,
            // non-amendment contracts, so the picker cannot offer a choice the
            // manager would refuse a second later.
            'amendable' => $this->amendable($all),
            // Quoted by the guide; the setting decides it, not the wording.
            'linkDays' => $this->linkLifetime->days(),
        ];
    }

    /** @return array<string, mixed> */
    public function showView(ContractInterface $contract): array
    {
        $id = $contract->getId();
        $path = fn (string $route): string => $this->urlGenerator->generate($route, ['id' => $id]);

        return [
            'contract' => $this->serializer->serializeDocument($contract),
            'indexPath' => $this->urlGenerator->generate('suite_studio_contracts'),
            // Every gesture the screen can offer, so the next step is always a
            // button on this page rather than a trip back to the list.
            'updatePath' => $path('suite_studio_contracts_update'),
            'deletePath' => $path('suite_studio_contracts_delete'),
            'previewPath' => $path('suite_studio_contracts_preview'),
            'freezePath' => $path('suite_studio_contracts_freeze'),
            'sendPath' => $path('suite_studio_contracts_send'),
            'remindPath' => $path('suite_studio_contracts_remind'),
            'revokeLinkPath' => $path('suite_studio_contracts_revoke_link'),
            'cancelPath' => $path('suite_studio_contracts_cancel'),
            'duplicatePath' => $path('suite_studio_contracts_duplicate'),
            'countersignPath' => $path('suite_studio_contracts_countersign'),
            'pdfPath' => $path('suite_studio_contracts_pdf'),
            'exportPath' => $path('suite_studio_contracts_export'),
            'terminatePath' => $path('suite_studio_contracts_terminate'),
            'terminationOrigins' => $this->terminationOrigins(),
            // How long the address a send hands out stays valid, as the
            // buttons and the confirmation say it.
            'linkDays' => $this->linkLifetime->days(),
            // Where an amendment starts from: the list, with this contract
            // already chosen. One screen creates contracts, and an amendment
            // is a contract.
            'amendPath' => $this->urlGenerator->generate('suite_studio_contracts', ['amends' => $id]),
            'showPath' => $this->pathTemplates->generate('suite_studio_contracts_show', ['id' => '__id__']),
            'templateVersionPath' => $this->pathTemplates->generate('suite_studio_contract_templates_editor', ['id' => '__id__', 'versionId' => '__versionId__']),
            'wordingPath' => $this->pathTemplates->generate('suite_studio_contracts_wording', ['id' => $id, 'part' => '__part__']),
            // What the edit form offers, as on the list: a draft is corrected
            // where it is read.
            'customers' => $contract->isFrozen() ? [] : $this->customerOptions(),
            'bodies' => $contract->isFrozen() ? [] : $this->templateOptions(ContractTemplateKindEnum::Body),
            'annexes' => $contract->isFrozen() ? [] : $this->templateOptions(ContractTemplateKindEnum::Annex),
            'locales' => $this->localeOptions->getActiveOptions(),
            'currencies' => $this->currencyOptions(),
            'amendable' => $contract->isFrozen() ? [] : $this->amendable($this->contractRepository->findAllForIndex()),
        ];
    }

    /**
     * The contracts an amendment can be attached to.
     *
     * The same four rules the manager enforces, applied here so the picker
     * never offers what the freeze would refuse: concluded, not itself an
     * amendment, not terminated. The customer travels with each entry, because
     * choosing a parent decides the customer rather than the other way round.
     *
     * @param list<ContractInterface> $contracts
     *
     * @return list<array<string, mixed>>
     */
    private function amendable(array $contracts): array
    {
        $amendable = [];

        foreach ($contracts as $contract) {
            if (!$contract->getStatus()->isConcluded()) {
                continue;
            }

            if ($contract->isAmendment()) {
                continue;
            }

            // Until the termination takes effect: during the notice the contract
            // still binds, and an amendment is how its last months change.
            if ($contract->isTerminationEffective()) {
                continue;
            }

            $amendable[] = [
                'id' => $contract->getId(),
                'reference' => $contract->getReference(),
                'customerId' => $contract->getCustomer()->getId(),
                'customerName' => $contract->getCustomer()->getLegalName(),
            ];
        }

        return $amendable;
    }

    /** @return list<array{value: string, labelKey: string}> */
    private function terminationOrigins(): array
    {
        return array_map(
            static fn (ContractTerminationOriginEnum $origin): array => [
                'value' => $origin->value,
                'labelKey' => $origin->getLabel(),
            ],
            ContractTerminationOriginEnum::cases(),
        );
    }

    /** @return list<array<string, mixed>> */
    public function contracts(): array
    {
        return $this->serializer->serializeMany($this->contractRepository->findAllForIndex());
    }

    /** @return array<string, mixed> */
    public function listPayload(): array
    {
        return ['success' => true, 'contracts' => $this->contracts()];
    }

    /** @return list<array{value: string, label: string}> */
    private function customerOptions(): array
    {
        return array_map(
            static fn (CustomerInterface $customer): array => [
                'value' => (string) $customer->getId(),
                'label' => $customer->getLegalName(),
            ],
            $this->customerRepository->findAllOrdered(),
        );
    }

    /** @return list<array{value: string, label: string, category: string|null, customFields: list<string>}> */
    private function templateOptions(ContractTemplateKindEnum $kind): array
    {
        return array_map(
            fn (ContractTemplateInterface $template): array => [
                'value' => (string) $template->getId(),
                'label' => $template->getName(),
                // Carried, not filtered on. The form sorts the list by category
                // to make a library of twenty readable, but every trame stays
                // reachable: a body written for one activity is sometimes the
                // right starting point for another, and a picker that hides it
                // would be helping in a way that costs an hour.
                'category' => $template->getCategory()?->getName(),
                // The blanks this trame will ask for, so the form can put them
                // on screen the moment it is chosen rather than at the freeze,
                // where a refusal means going back and starting again.
                'customFields' => $this->customFieldsOf($template),
            ],
            $this->templateRepository->findSelectable($kind),
        );
    }

    /** @return list<string> */
    private function customFieldsOf(ContractTemplateInterface $template): array
    {
        $version = $template->getLatestPublishedVersion();

        return $version instanceof ContractTemplateVersionInterface ? $this->customFields->keysOf($version) : [];
    }

    /** @return list<array{value: string, label: string}> */
    private function currencyOptions(): array
    {
        return array_map(
            static fn (CurrencyEnum $currency): array => [
                'value' => $currency->value,
                'label' => sprintf('%s (%s)', $currency->value, $currency->symbol()),
            ],
            CurrencyEnum::cases(),
        );
    }
}
