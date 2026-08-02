<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\Entity;

use Aurora\Module\Editorial\Form\Repository\FormTranslationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FormTranslationRepository::class)]
#[ORM\Table(name: 'core_form_translations')]
#[ORM\UniqueConstraint(name: 'uniq_form_translation_locale', columns: ['form_id', 'locale'])]
// The slug is a public URL segment, so it names exactly one form per locale.
#[ORM\UniqueConstraint(name: 'uniq_form_locale_slug', columns: ['locale', 'slug'])]
class FormTranslation extends AbstractFormTranslation
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_form_translation_id', allocationSize: 1)]
    #[ORM\Column]
    private ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
