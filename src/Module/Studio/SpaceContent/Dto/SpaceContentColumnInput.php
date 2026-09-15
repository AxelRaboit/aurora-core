<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class SpaceContentColumnInput implements SpaceContentColumnInputInterface
{
    public function __construct(
        #[Assert\NotBlank(message: 'backend.studio.space_content.errors.column_name_required')]
        #[Assert\Length(max: 100, maxMessage: 'backend.studio.space_content.errors.column_name_too_long')]
        public readonly string $name = '',
    ) {}

    public function getName(): string
    {
        return $this->name;
    }
}
