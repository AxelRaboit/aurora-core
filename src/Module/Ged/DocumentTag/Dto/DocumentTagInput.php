<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\DocumentTag\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class DocumentTagInput implements DocumentTagInputInterface
{
    public function __construct(
        #[Assert\NotBlank(message: 'suite.ged.tags.errors.name_required')]
        #[Assert\Length(max: 100)]
        public readonly string $name = '',
        #[Assert\Length(max: 7)]
        public readonly ?string $color = null,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }
}
