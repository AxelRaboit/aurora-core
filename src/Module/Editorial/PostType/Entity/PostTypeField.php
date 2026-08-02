<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\PostType\Entity;

use Aurora\Module\Editorial\PostType\Repository\PostTypeFieldRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PostTypeFieldRepository::class)]
#[ORM\Table(name: 'core_post_type_fields')]
class PostTypeField extends AbstractPostTypeField
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_post_type_field_id', allocationSize: 1)]
    #[ORM\Column]
    private ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
