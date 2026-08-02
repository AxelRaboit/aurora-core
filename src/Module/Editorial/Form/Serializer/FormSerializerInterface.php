<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\Serializer;

use Aurora\Module\Editorial\Form\Entity\FormInterface;
use Aurora\Module\Editorial\Form\Entity\FormSubmissionInterface;

interface FormSerializerInterface
{
    /**
     * Everything the builder edits, every locale at once.
     *
     * @return array<string, mixed>
     */
    public function serialize(FormInterface $form): array;

    /**
     * One locale's worth, for the public page. No webhook, no notify address,
     * no CRM flag: those are the site's business, not the visitor's.
     *
     * @return array<string, mixed>
     */
    public function serializeForReader(FormInterface $form, string $locale): array;

    /** @return array<string, mixed> */
    public function serializeSubmission(FormSubmissionInterface $submission, string $locale): array;
}
