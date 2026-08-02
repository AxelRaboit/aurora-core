<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\Serializer;

use Aurora\Module\Editorial\Form\Entity\FormFieldInterface;
use Aurora\Module\Editorial\Form\Entity\FormInterface;
use Aurora\Module\Editorial\Form\Entity\FormSubmissionInterface;
use Aurora\Module\Editorial\Form\Service\FormFieldLabeler;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

use const DATE_ATOM;

#[AsAlias(FormSerializerInterface::class)]
class FormSerializer implements FormSerializerInterface
{
    public function __construct(protected readonly FormFieldLabeler $labeler) {}

    public function serialize(FormInterface $form): array
    {
        $translations = [];
        foreach ($form->getTranslations() as $locale => $translation) {
            $translations[(string) $locale] = [
                'title' => $translation->getTitle(),
                'slug' => $translation->getSlug(),
                'description' => $translation->getDescription(),
            ];
        }

        return [
            'id' => $form->getId(),
            'reference' => $form->getReference(),
            'notifyEmail' => $form->getNotifyEmail(),
            'webhookUrl' => $form->getWebhookUrl(),
            'crmSync' => $form->isCrmSync(),
            'active' => $form->isActive(),
            'steps' => $form->getSteps(),
            'translations' => $translations,
            'fields' => array_map($this->serializeField(...), array_values($form->getFields()->toArray())),
            'updatedAt' => $form->getUpdatedAt()->format(DATE_ATOM),
        ];
    }

    public function serializeForReader(FormInterface $form, string $locale): array
    {
        $translation = $form->getTranslation($locale);

        return [
            'id' => $form->getId(),
            'title' => $translation?->getTitle() ?? '',
            'description' => $translation?->getDescription(),
            'steps' => $form->getSteps(),
            'fields' => array_map(
                fn (FormFieldInterface $field): array => $this->serializeFieldForReader($field, $locale),
                array_values($form->getFields()->toArray()),
            ),
        ];
    }

    public function serializeSubmission(FormSubmissionInterface $submission, string $locale): array
    {
        return [
            'id' => $submission->getId(),
            'reference' => $submission->getReference(),
            'locale' => $submission->getLocale(),
            'submittedAt' => $submission->getSubmittedAt()->format(DATE_ATOM),
            'ip' => $submission->getIp(),
            'pairs' => $this->labeler->pairs($submission->getForm(), $submission, $locale),
        ];
    }

    /** @return array<string, mixed> */
    protected function serializeField(FormFieldInterface $field): array
    {
        $translations = [];
        foreach ($field->getTranslations() as $locale => $translation) {
            $translations[(string) $locale] = [
                'label' => $translation->getLabel(),
                'placeholder' => $translation->getPlaceholder(),
                'options' => $translation->getOptions(),
            ];
        }

        return [
            'id' => $field->getId(),
            'reference' => $field->getReference(),
            'type' => $field->getType()->value,
            'required' => $field->isRequired(),
            'position' => $field->getPosition(),
            'conditions' => $field->getConditions(),
            'conditionsLogic' => $field->getConditionsLogic()->value,
            'step' => $field->getStep(),
            'translations' => $translations,
        ];
    }

    /** @return array<string, mixed> */
    protected function serializeFieldForReader(FormFieldInterface $field, string $locale): array
    {
        $translation = $field->getTranslation($locale);

        return [
            'id' => $field->getId(),
            'type' => $field->getType()->value,
            'required' => $field->isRequired(),
            'label' => $translation?->getLabel() ?? '',
            'placeholder' => $translation?->getPlaceholder(),
            'options' => $translation?->getOptions() ?? [],
            // The conditions travel to the browser so it can hide a field the
            // moment an answer changes. The server evaluates them again on
            // submit: this copy is for responsiveness, not for trust.
            'conditions' => $field->getConditions(),
            'conditionsLogic' => $field->getConditionsLogic()->value,
            'step' => $field->getStep(),
        ];
    }
}
