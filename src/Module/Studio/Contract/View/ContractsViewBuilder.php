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
        return [
            'contracts' => $this->contracts(),
            'customers' => $this->customerOptions(),
            // Only what can actually produce a document: live templates with
            // something published. Offering the rest means offering a dead end.
            'bodies' => $this->templateOptions(ContractTemplateKindEnum::Body),
            'annexes' => $this->templateOptions(ContractTemplateKindEnum::Annex),
            'locales' => $this->localeOptions->getActiveOptions(),
            'currencies' => $this->currencyOptions(),
            'createPath' => $this->urlGenerator->generate('backend_studio_contracts_create'),
            'updatePath' => $this->pathTemplates->generate('backend_studio_contracts_update', ['id' => '__id__']),
            'deletePath' => $this->pathTemplates->generate('backend_studio_contracts_delete', ['id' => '__id__']),
            'freezePath' => $this->pathTemplates->generate('backend_studio_contracts_freeze', ['id' => '__id__']),
            'sendPath' => $this->pathTemplates->generate('backend_studio_contracts_send', ['id' => '__id__']),
            'revokeLinkPath' => $this->pathTemplates->generate('backend_studio_contracts_revoke_link', ['id' => '__id__']),
            'countersignPath' => $this->pathTemplates->generate('backend_studio_contracts_countersign', ['id' => '__id__']),
            'pdfPath' => $this->pathTemplates->generate('backend_studio_contracts_pdf', ['id' => '__id__']),
            'showPath' => $this->pathTemplates->generate('backend_studio_contracts_show', ['id' => '__id__']),
            'terminatePath' => $this->pathTemplates->generate('backend_studio_contracts_terminate', ['id' => '__id__']),
            'terminationOrigins' => $this->terminationOrigins(),
            // What an amendment may be attached to. Only concluded, running,
            // non-amendment contracts, so the picker cannot offer a choice the
            // manager would refuse a second later.
            'amendable' => $this->amendable(),
        ];
    }

    /** @return array<string, mixed> */
    public function showView(ContractInterface $contract): array
    {
        return [
            'contract' => $this->serializer->serializeDocument($contract),
            'indexPath' => $this->urlGenerator->generate('backend_studio_contracts'),
            'freezePath' => $this->urlGenerator->generate('backend_studio_contracts_freeze', ['id' => $contract->getId()]),
            'sendPath' => $this->urlGenerator->generate('backend_studio_contracts_send', ['id' => $contract->getId()]),
            'revokeLinkPath' => $this->urlGenerator->generate('backend_studio_contracts_revoke_link', ['id' => $contract->getId()]),
            'countersignPath' => $this->urlGenerator->generate('backend_studio_contracts_countersign', ['id' => $contract->getId()]),
            'pdfPath' => $this->urlGenerator->generate('backend_studio_contracts_pdf', ['id' => $contract->getId()]),
            'terminatePath' => $this->urlGenerator->generate('backend_studio_contracts_terminate', ['id' => $contract->getId()]),
            'terminationOrigins' => $this->terminationOrigins(),
            // Where an amendment starts from: the list, with this contract
            // already chosen. One screen creates contracts, and an amendment
            // is a contract.
            'amendPath' => $this->urlGenerator->generate('backend_studio_contracts', ['amends' => $contract->getId()]),
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
     * @return list<array<string, mixed>>
     */
    private function amendable(): array
    {
        $amendable = [];

        foreach ($this->contractRepository->findAllForIndex() as $contract) {
            if (!$contract->getStatus()->isConcluded()) {
                continue;
            }

            if ($contract->isAmendment()) {
                continue;
            }

            if ($contract->isTerminated()) {
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
        return array_map($this->serializer->serialize(...), $this->contractRepository->findAllForIndex());
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
