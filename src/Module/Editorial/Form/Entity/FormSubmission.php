<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\Entity;

use Aurora\Module\Editorial\Form\Repository\FormSubmissionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FormSubmissionRepository::class)]
#[ORM\Table(name: 'core_form_submissions')]
// The submissions screen reads one form's, newest first, and nothing else.
#[ORM\Index(name: 'idx_form_submission_form_date', columns: ['form_id', 'submitted_at'])]
class FormSubmission extends AbstractFormSubmission
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_form_submission_id', allocationSize: 1)]
    #[ORM\Column]
    private ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
