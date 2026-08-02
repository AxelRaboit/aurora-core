<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\PostType\Entity;

use Aurora\Module\Editorial\PostType\Repository\PostTypeRepository;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyInterface;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PostTypeRepository::class)]
#[ORM\Table(name: 'core_post_types')]
class PostType extends AbstractPostType
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_post_type_id', allocationSize: 1)]
    #[ORM\Column]
    private ?int $id = null;

    /** @var Collection<int, TaxonomyInterface> */
    #[ORM\ManyToMany(targetEntity: TaxonomyInterface::class, inversedBy: 'postTypes')]
    #[ORM\JoinTable(name: 'core_post_type_taxonomies')]
    #[ORM\JoinColumn(name: 'post_type_id', referencedColumnName: 'id')]
    #[ORM\InverseJoinColumn(name: 'taxonomy_id', referencedColumnName: 'id')]
    protected Collection $taxonomies;

    public function getId(): ?int
    {
        return $this->id;
    }
}
