<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Entity;

use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTermInterface;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PostRepository::class)]
#[ORM\Table(name: 'core_posts')]
class Post extends AbstractPost
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_post_id', allocationSize: 1)]
    #[ORM\Column]
    private ?int $id = null;

    /** @var Collection<int, TaxonomyTermInterface> */
    #[ORM\ManyToMany(targetEntity: TaxonomyTermInterface::class, inversedBy: 'posts')]
    #[ORM\JoinTable(name: 'core_post_terms')]
    #[ORM\JoinColumn(name: 'post_id', referencedColumnName: 'id')]
    #[ORM\InverseJoinColumn(name: 'taxonomy_term_id', referencedColumnName: 'id')]
    protected Collection $terms;

    /**
     * Deliberately one-directional: "see also" is an editorial choice on
     * this post, not a claim about the other one.
     *
     * @var Collection<int, PostInterface>
     */
    #[ORM\ManyToMany(targetEntity: PostInterface::class)]
    #[ORM\JoinTable(name: 'core_post_related_posts')]
    #[ORM\JoinColumn(name: 'post_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'related_post_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    protected Collection $relatedPosts;

    public function getId(): ?int
    {
        return $this->id;
    }
}
