<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Menu\Entity;

use Aurora\Module\Editorial\Menu\Repository\MenuItemTranslationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MenuItemTranslationRepository::class)]
#[ORM\Table(name: 'core_menu_item_translations')]
class MenuItemTranslation extends AbstractMenuItemTranslation
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_menu_item_translation_id', allocationSize: 1)]
    #[ORM\Column]
    private ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
