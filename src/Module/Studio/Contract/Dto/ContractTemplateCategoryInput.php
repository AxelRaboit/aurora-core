<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class ContractTemplateCategoryInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'suite.studio.contract_templates.categories.errors.name_required')]
        #[Assert\Length(max: 100, maxMessage: 'suite.studio.contract_templates.categories.errors.name_too_long')]
        public readonly string $name = '',
        #[Assert\Regex(
            pattern: '/^#[0-9a-fA-F]{6}$/',
            message: 'suite.studio.contract_templates.categories.errors.color_invalid',
        )]
        public readonly ?string $color = null,
    ) {}
}
