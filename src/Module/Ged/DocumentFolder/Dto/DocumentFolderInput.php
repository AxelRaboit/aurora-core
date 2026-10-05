<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\DocumentFolder\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class DocumentFolderInput implements DocumentFolderInputInterface
{
    public function __construct(
        #[Assert\NotBlank(message: 'suite.ged.folders.errors.name_required')]
        #[Assert\Length(max: 150)]
        public readonly string $name = '',
        public readonly ?int $parentId = null,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getParentId(): ?int
    {
        return $this->parentId;
    }
}
