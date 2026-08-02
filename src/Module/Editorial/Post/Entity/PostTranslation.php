<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Entity;

use Aurora\Module\Editorial\Post\Repository\PostTranslationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PostTranslationRepository::class)]
#[ORM\Table(name: 'core_post_translations')]
// `indexBy: 'locale'` keys the in-memory collection; only the database can
// stop a second row for the same locale from ever existing. Without it
// getTranslation() would hand back whichever of the two Doctrine hydrated
// first.
#[ORM\UniqueConstraint(name: 'uniq_post_translation_locale', columns: ['post_id', 'locale'])]
class PostTranslation extends AbstractPostTranslation
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_post_translation_id', allocationSize: 1)]
    #[ORM\Column]
    private ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
