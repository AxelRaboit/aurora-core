<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\Entity;

use Aurora\Module\Editorial\Form\Repository\FormFieldTranslationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FormFieldTranslationRepository::class)]
#[ORM\Table(name: 'core_form_field_translations')]
#[ORM\UniqueConstraint(name: 'uniq_form_field_translation_locale', columns: ['field_id', 'locale'])]
class FormFieldTranslation extends AbstractFormFieldTranslation
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_form_field_translation_id', allocationSize: 1)]
    #[ORM\Column]
    private ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
