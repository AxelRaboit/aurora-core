<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\DocumentCategory\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class DocumentCategoryInput implements DocumentCategoryInputInterface
{
    public function __construct(
        #[Assert\NotBlank(message: 'suite.ged.categories.errors.name_required')]
        #[Assert\Length(max: 150)]
        public readonly string $name = '',
        public readonly ?string $description = null,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }
}
