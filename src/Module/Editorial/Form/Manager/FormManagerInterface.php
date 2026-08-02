<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\Manager;

use Aurora\Module\Editorial\Form\Dto\FormFieldInputInterface;
use Aurora\Module\Editorial\Form\Dto\FormInputInterface;
use Aurora\Module\Editorial\Form\Entity\FormFieldInterface;
use Aurora\Module\Editorial\Form\Entity\FormInterface;
use Aurora\Module\Editorial\Form\Entity\FormSubmissionInterface;
use Aurora\Module\Editorial\Form\Entity\FormTranslationInterface;

interface FormManagerInterface
{
    public function create(FormInputInterface $input): FormInterface;

    public function update(FormInterface $form, FormInputInterface $input): void;

    public function delete(FormInterface $form): void;

    public function createField(FormInterface $form, FormFieldInputInterface $input): FormFieldInterface;

    public function updateField(FormFieldInterface $field, FormFieldInputInterface $input): void;

    public function deleteField(FormFieldInterface $field): void;

    /** @param list<int> $orderedIds */
    public function reorderFields(FormInterface $form, array $orderedIds): void;

    /** @param array<string, string|list<string>> $answers */
    public function submit(FormInterface $form, array $answers, string $locale, ?string $ip): FormSubmissionInterface;

    public function findActiveTranslation(string $locale, string $slug): ?FormTranslationInterface;
}
