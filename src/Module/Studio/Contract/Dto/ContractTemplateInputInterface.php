<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Dto;

use Aurora\Module\Studio\Contract\Enum\ContractTemplateCategoryEnum;
use Aurora\Module\Studio\Contract\Enum\ContractTemplateKindEnum;

interface ContractTemplateInputInterface
{
    public function getName(): string;

    public function getKind(): ContractTemplateKindEnum;

    public function getCategory(): ?ContractTemplateCategoryEnum;
}
