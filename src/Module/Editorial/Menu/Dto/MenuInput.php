<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Menu\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Only the name and the description. The location is not the editor's to
 * change: it is the contract between a menu and the template that renders
 * it, and a menu with a location no theme asks for renders nowhere.
 */
class MenuInput implements MenuInputInterface
{
    public function __construct(
        #[Assert\NotBlank(message: 'backend.menus.errors.name_required')]
        #[Assert\Length(max: 100)]
        public readonly string $name,
        #[Assert\Length(max: 500)]
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
