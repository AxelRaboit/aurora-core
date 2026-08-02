<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Taxonomy\Entity;

use Aurora\Module\Editorial\Taxonomy\Repository\TaxonomyTermRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TaxonomyTermRepository::class)]
#[ORM\Table(name: 'core_taxonomy_terms')]
// How the term tree is always read: one taxonomy, one level at a time.
#[ORM\Index(name: 'idx_taxonomy_term_taxonomy_parent', columns: ['taxonomy_id', 'parent_id'])]
class TaxonomyTerm extends AbstractTaxonomyTerm
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_taxonomy_term_id', allocationSize: 1)]
    #[ORM\Column]
    private ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
