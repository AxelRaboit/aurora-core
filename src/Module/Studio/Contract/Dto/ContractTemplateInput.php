<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Dto;

use Aurora\Module\Studio\Contract\Enum\ContractTemplateCategoryEnum;
use Aurora\Module\Studio\Contract\Enum\ContractTemplateKindEnum;
use Symfony\Component\Validator\Constraints as Assert;

class ContractTemplateInput implements ContractTemplateInputInterface
{
    public function __construct(
        #[Assert\NotBlank(message: 'backend.studio.contract_templates.errors.name_required')]
        #[Assert\Length(max: 180, maxMessage: 'backend.studio.contract_templates.errors.name_too_long')]
        public readonly string $name = '',
        public readonly ContractTemplateKindEnum $kind = ContractTemplateKindEnum::Body,
        // Null is a value here, not a missing one: it is how a template says
        // nobody has classified it yet.
        public readonly ?ContractTemplateCategoryEnum $category = null,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getKind(): ContractTemplateKindEnum
    {
        return $this->kind;
    }

    public function getCategory(): ?ContractTemplateCategoryEnum
    {
        return $this->category;
    }
}
