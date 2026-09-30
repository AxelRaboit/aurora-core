<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\View;

use Aurora\Core\Locale\Service\LocaleOptionsProviderInterface;
use Aurora\Core\Money\Enum\CurrencyEnum;
use Aurora\Core\Routing\PathTemplateGenerator;
use Aurora\Module\Studio\Contract\Entity\ContractInterface;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateInterface;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateVersionInterface;
use Aurora\Module\Studio\Contract\Enum\ContractTemplateKindEnum;
use Aurora\Module\Studio\Contract\Enum\ContractTerminationOriginEnum;
use Aurora\Module\Studio\Contract\Repository\ContractRepository;
use Aurora\Module\Studio\Contract\Repository\ContractTemplateRepository;
use Aurora\Module\Studio\Contract\Serializer\ContractSerializerInterface;
use Aurora\Module\Studio\Contract\Service\ContractCustomFieldScanner;
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
    ) {}

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
            'createPath' => $this->urlGenerator->generate('backend_studio_contracts_create'),
            'updatePath' => $this->pathTemplates->generate('backend_studio_contracts_update', ['id' => '__id__']),
            'previewPath' => $this->pathTemplates->generate('backend_studio_contracts_preview', ['id' => '__id__']),
            'duplicatePath' => $this->pathTemplates->generate('backend_studio_contracts_duplicate', ['id' => '__id__']),
            'pdfPath' => $this->pathTemplates->generate('backend_studio_contracts_pdf', ['id' => '__id__']),
            // One address for every row, whatever state it is in: the signed
            // file when there is one, a working copy otherwise.
            'exportPath' => $this->pathTemplates->generate('backend_studio_contracts_export', ['id' => '__id__']),
            // The contract's own screen, where every other gesture is.
            'showPath' => $this->pathTemplates->generate('backend_studio_contracts_show', ['id' => '__id__']),
            // What an amendment may be attached to. Only concluded, running,
            // non-amendment contracts, so the picker cannot offer a choice the
            // manager would refuse a second later.
            'amendable' => $this->amendable($all),
        ];
    }

    /** @return array<string, mixed> */
    public function showView(ContractInterface $contract): array
    {
        $id = $contract->getId();
        $path = fn (string $route): string => $this->urlGenerator->generate($route, ['id' => $id]);

        return [
            'contract' => $this->serializer->serializeDocument($contract),
            'indexPath' => $this->urlGenerator->generate('backend_studio_contracts'),
            // Every gesture the screen can offer, so the next step is always a
            // button on this page rather than a trip back to the list.
            'updatePath' => $path('backend_studio_contracts_update'),
            'deletePath' => $path('backend_studio_contracts_delete'),
            'previewPath' => $path('backend_studio_contracts_preview'),
            'freezePath' => $path('backend_studio_contracts_freeze'),
            'sendPath' => $path('backend_studio_contracts_send'),
            'remindPath' => $path('backend_studio_contracts_remind'),
            'revokeLinkPath' => $path('backend_studio_contracts_revoke_link'),
            'cancelPath' => $path('backend_studio_contracts_cancel'),
            'duplicatePath' => $path('backend_studio_contracts_duplicate'),
            'countersignPath' => $path('backend_studio_contracts_countersign'),
            'pdfPath' => $path('backend_studio_contracts_pdf'),
            'exportPath' => $path('backend_studio_contracts_export'),
            'terminatePath' => $path('backend_studio_contracts_terminate'),
            'terminationOrigins' => $this->terminationOrigins(),
            // Where an amendment starts from: the list, with this contract
            // already chosen. One screen creates contracts, and an amendment
            // is a contract.
            'amendPath' => $this->urlGenerator->generate('backend_studio_contracts', ['amends' => $id]),
            'showPath' => $this->pathTemplates->generate('backend_studio_contracts_show', ['id' => '__id__']),
            'templateVersionPath' => $this->pathTemplates->generate('backend_studio_contract_templates_editor', ['id' => '__id__', 'versionId' => '__versionId__']),
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
                // Carried, not filtered on. The form narrows the list by trade
                // to make a library of twenty readable, but every trame stays
                // reachable: a body written for one activity is sometimes the
                // right starting point for another, and a picker that hides it
                // would be helping in a way that costs an hour.
                'category' => $template->getCategory()?->value,
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
