<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Taxonomy\Entity;

use Aurora\Module\Editorial\Taxonomy\Repository\TaxonomyTermTranslationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TaxonomyTermTranslationRepository::class)]
#[ORM\Table(name: 'core_taxonomy_term_translations')]
#[ORM\UniqueConstraint(name: 'uniq_taxonomy_term_translation_locale', columns: ['term_id', 'locale'])]
// Term slugs are a public URL segment, so they have to be unique per locale
// across every taxonomy - `/fr/theme/boulange` names exactly one term.
#[ORM\UniqueConstraint(name: 'uniq_term_locale_slug', columns: ['locale', 'slug'])]
class TaxonomyTermTranslation extends AbstractTaxonomyTermTranslation
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_taxonomy_term_translation_id', allocationSize: 1)]
    #[ORM\Column]
    private ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
