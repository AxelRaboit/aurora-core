<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\Event;

use Aurora\Module\Editorial\Form\Entity\FormInterface;
use Aurora\Module\Editorial\Form\Entity\FormSubmissionInterface;

/**
 * Announced once a submission is stored.
 *
 * The hook for anything a site wants to do with a submission that Editorial
 * has no business knowing about - a client project's own routing, an
 * integration, a counter. Dispatched after the flush, so a listener can rely
 * on the submission having an id.
 */
class FormSubmissionCreatedEvent
{
    public function __construct(
        private readonly FormInterface $form,
        private readonly FormSubmissionInterface $submission,
    ) {}

    public function getForm(): FormInterface
    {
        return $this->form;
    }

    public function getSubmission(): FormSubmissionInterface
    {
        return $this->submission;
    }
}
