<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Dto;

use Aurora\Core\Support\Str;
use Aurora\Module\Studio\Contract\Enum\ContractTemplateCategoryEnum;
use Aurora\Module\Studio\Contract\Enum\ContractTemplateKindEnum;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(ContractTemplateInputFactoryInterface::class)]
class ContractTemplateInputFactory implements ContractTemplateInputFactoryInterface
{
    /** @param array<string, mixed> $data */
    public function fromArray(array $data): ContractTemplateInputInterface
    {
        return new ContractTemplateInput(
            name: Str::trimFromArray($data, 'name'),
            // An unreadable kind falls back to a body rather than throwing:
            // the picker only offers two values, and a body is the one that
            // stands on its own, so it is the safer of the two to assume.
            kind: ContractTemplateKindEnum::tryFrom(Str::trimFromArray($data, 'kind'))
                ?? ContractTemplateKindEnum::Body,
            // An unreadable category falls back to none, which is the honest
            // answer: the value said nothing usable, so nothing is claimed.
            // Unlike the kind, there is no safer of two to assume here -
            // picking a trade at random is exactly what must not happen.
            category: ContractTemplateCategoryEnum::tryFrom(Str::trimFromArray($data, 'category')),
        );
    }
}
