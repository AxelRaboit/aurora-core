<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\Manager;

use Aurora\Core\Contact\Event\ContactSignalEvent;
use Aurora\Core\Locale\Service\TranslationLocaleSyncerInterface;
use Aurora\Core\Sequence\SequenceGenerator;
use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Editorial\Form\Dto\FormFieldInputInterface;
use Aurora\Module\Editorial\Form\Dto\FormInputInterface;
use Aurora\Module\Editorial\Form\Entity\Form;
use Aurora\Module\Editorial\Form\Entity\FormField;
use Aurora\Module\Editorial\Form\Entity\FormFieldInterface;
use Aurora\Module\Editorial\Form\Entity\FormInterface;
use Aurora\Module\Editorial\Form\Entity\FormSubmission;
use Aurora\Module\Editorial\Form\Entity\FormSubmissionInterface;
use Aurora\Module\Editorial\Form\Entity\FormTranslationInterface;
use Aurora\Module\Editorial\Form\Enum\FormFieldTypeEnum;
use Aurora\Module\Editorial\Form\Event\FormSubmissionCreatedEvent;
use Aurora\Module\Editorial\Form\Repository\FormTranslationRepository;
use Aurora\Module\Editorial\Form\Service\FormNotificationService;
use Aurora\Module\Editorial\Form\Service\FormWebhookService;
use Aurora\Module\Editorial\Setting\EditorialSettingEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsAlias(FormManagerInterface::class)]
class FormManager implements FormManagerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly FormTranslationRepository $translationRepository,
        protected readonly TranslatorInterface $translator,
        protected readonly SluggerInterface $slugger,
        protected readonly FormNotificationService $notificationService,
        protected readonly FormWebhookService $webhookService,
        protected readonly SequenceGenerator $sequenceGenerator,
        protected readonly SettingRepository $settingRepository,
        protected readonly EventDispatcherInterface $eventDispatcher,
        protected readonly TranslationLocaleSyncerInterface $translationSyncer,
        protected readonly AuditLogger $auditLogger,
    ) {}

    public function create(FormInputInterface $input): FormInterface
    {
        $form = $this->createForm();
        $this->applyInput($form, $input);

        $this->entityManager->persist($form);
        $this->entityManager->flush();

        $form->setReference($this->sequenceGenerator->next(
            $this->settingRepository->getOrDefault(EditorialSettingEnum::FormPrefix),
        ));
        $this->entityManager->flush();

        $this->auditCreated($form);

        return $form;
    }

    public function update(FormInterface $form, FormInputInterface $input): void
    {
        $this->applyInput($form, $input);
        $form->touch();

        $this->entityManager->flush();

        $this->auditUpdated($form);
    }

    public function delete(FormInterface $form): void
    {
        $this->auditDeleted($form);

        $this->entityManager->remove($form);
        $this->entityManager->flush();
    }

    public function createField(FormInterface $form, FormFieldInputInterface $input): FormFieldInterface
    {
        $field = $this->createFormField();
        $form->addField($field);

        $this->applyFieldInput($field, $input);
        $field->setPosition($form->getFields()->count() - 1);
        $form->touch();

        $this->entityManager->persist($field);
        $this->entityManager->flush();

        $field->setReference($this->sequenceGenerator->next(
            $this->settingRepository->getOrDefault(EditorialSettingEnum::FormFieldPrefix),
        ));
        $this->entityManager->flush();

        return $field;
    }

    public function updateField(FormFieldInterface $field, FormFieldInputInterface $input): void
    {
        $this->applyFieldInput($field, $input);
        $field->getForm()->touch();

        $this->entityManager->flush();
    }

    /**
     * Deleting a field also unhooks every condition that referred to it.
     *
     * A condition pointing at a field that no longer exists can never be met,
     * so the fields depending on it would vanish from the form for good —
     * silently, and with nothing in the builder to explain it.
     */
    public function deleteField(FormFieldInterface $field): void
    {
        $form = $field->getForm();
        $removedId = (int) $field->getId();

        foreach ($form->getFields() as $other) {
            $conditions = array_values(array_filter(
                $other->getConditions(),
                static fn (array $condition): bool => $condition['fieldId'] !== $removedId,
            ));

            if (count($conditions) !== count($other->getConditions())) {
                $other->setConditions($conditions);
            }
        }

        $form->removeField($field);
        $form->touch();

        $this->entityManager->remove($field);
        $this->entityManager->flush();
    }

    public function reorderFields(FormInterface $form, array $orderedIds): void
    {
        $fieldsById = [];
        foreach ($form->getFields() as $field) {
            $fieldsById[(int) $field->getId()] = $field;
        }

        foreach ($orderedIds as $position => $fieldId) {
            // An id the form does not own is skipped rather than rejected: a
            // stale row in the browser should not lose the rest of the order.
            if (isset($fieldsById[$fieldId])) {
                $fieldsById[$fieldId]->setPosition($position);
            }
        }

        $form->touch();
        $this->entityManager->flush();
    }

    public function findActiveTranslation(string $locale, string $slug): ?FormTranslationInterface
    {
        $translation = $this->translationRepository->findOneByLocaleAndSlug($locale, $slug);

        return $translation instanceof FormTranslationInterface && $translation->getForm()->isActive()
            ? $translation
            : null;
    }

    /**
     * Stores the submission, then tells everyone who asked to be told.
     *
     * The order matters: the submission is committed before any of the
     * outbound work, so a mail server that is down or a webhook that hangs
     * cannot cost the visitor what they typed.
     */
    public function submit(FormInterface $form, array $answers, string $locale, ?string $ip): FormSubmissionInterface
    {
        $submission = $this->createFormSubmission();
        $submission->setForm($form);
        $submission->setData($answers);
        $submission->setLocale($locale);
        $submission->setIp($ip);

        $this->entityManager->persist($submission);
        $this->entityManager->flush();

        $submission->setReference($this->sequenceGenerator->next(
            $this->settingRepository->getOrDefault(EditorialSettingEnum::FormSubmissionPrefix),
        ));
        $this->entityManager->flush();

        $this->notificationService->notifyAdmin($form, $submission, $locale);
        $this->notificationService->notifySubmitter($form, $submission, $locale);

        $this->eventDispatcher->dispatch(new FormSubmissionCreatedEvent($form, $submission));
        $this->signalContact($form, $submission);

        $this->webhookService->send($form, $submission, $locale);

        return $submission;
    }

    // ── Hooks: instanciation ──────────────────────────────────────────────────

    protected function createForm(): FormInterface
    {
        return new Form();
    }

    protected function createFormField(): FormFieldInterface
    {
        return new FormField();
    }

    protected function createFormSubmission(): FormSubmissionInterface
    {
        return new FormSubmission();
    }

    // ── Hooks: hydratation ────────────────────────────────────────────────────

    protected function applyInput(FormInterface $form, FormInputInterface $input): void
    {
        $form->setNotifyEmail($input->getNotifyEmail());
        $form->setWebhookUrl($input->getWebhookUrl());
        $form->setCrmSync($input->isCrmSync());
        $form->setActive($input->isActive());
        $form->setSteps($input->getSteps());

        foreach ($input->getTranslations() as $locale => $payload) {
            $slug = $this->slugFor($payload['title'], $payload['slug'] ?? null);
            $this->assertSlugIsFree((string) $locale, $slug, $form->getId());

            $translation = $form->translate((string) $locale);
            $translation->setTitle($payload['title']);
            $translation->setSlug($slug);
            $translation->setDescription($payload['description'] ?? null);
        }

        foreach ($this->translationSyncer->stale($form->getTranslations(), array_keys($input->getTranslations())) as $stale) {
            $form->getTranslations()->removeElement($stale);
        }
    }

    protected function applyFieldInput(FormFieldInterface $field, FormFieldInputInterface $input): void
    {
        $field->setType($input->getType());
        $field->setRequired($input->isRequired());
        $field->setConditions($input->getConditions());
        $field->setConditionsLogic($input->getConditionsLogic());
        $field->setStep($input->getStep());

        foreach ($input->getTranslations() as $locale => $payload) {
            $translation = $field->translate((string) $locale);
            $translation->setLabel($payload['label']);
            $translation->setPlaceholder($payload['placeholder'] ?? null);
            // Options belong to the types that offer a choice. Keeping them
            // after a change of type would leave a text field carrying a list
            // nothing reads, and restore it if the type were changed back.
            $translation->setOptions($input->getType()->hasOptions() ? $payload['options'] : []);
        }

        foreach ($this->translationSyncer->stale($field->getTranslations(), array_keys($input->getTranslations())) as $stale) {
            $field->getTranslations()->removeElement($stale);
        }
    }

    // ── Hooks: audit ──────────────────────────────────────────────────────────

    protected function auditCreated(FormInterface $form): void
    {
        $this->auditLogger->log('editorial', 'form.created', 'Form', $form->getId(), $this->auditPayload($form));
    }

    protected function auditUpdated(FormInterface $form): void
    {
        $this->auditLogger->log('editorial', 'form.updated', 'Form', $form->getId(), $this->auditPayload($form));
    }

    protected function auditDeleted(FormInterface $form): void
    {
        $this->auditLogger->log('editorial', 'form.deleted', 'Form', $form->getId(), $this->auditPayload($form));
    }

    /** @return array<string, mixed> */
    protected function auditPayload(FormInterface $form): array
    {
        return ['reference' => $form->getReference(), 'active' => $form->isActive()];
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /**
     * Announces the captured contact on the core signal, for a CRM to pick up
     * if one is installed. Editorial never imports it.
     */
    private function signalContact(FormInterface $form, FormSubmissionInterface $submission): void
    {
        if (!$form->isCrmSync()) {
            return;
        }

        $email = $this->firstAnswerOfType($form, $submission, FormFieldTypeEnum::Email);
        if (null === $email) {
            return;
        }

        $this->eventDispatcher->dispatch(new ContactSignalEvent(
            email: $email,
            fullName: $this->firstAnswerOfType($form, $submission, FormFieldTypeEnum::Text) ?? '',
            phone: $this->firstAnswerOfType($form, $submission, FormFieldTypeEnum::Tel),
            sourceKey: 'form',
        ));
    }

    private function firstAnswerOfType(FormInterface $form, FormSubmissionInterface $submission, FormFieldTypeEnum $type): ?string
    {
        $data = $submission->getData();

        foreach ($form->getFields() as $field) {
            if ($field->getType() !== $type) {
                continue;
            }

            $value = $data[(string) $field->getId()] ?? null;
            if (is_string($value) && '' !== mb_trim($value)) {
                return mb_trim($value);
            }
        }

        return null;
    }

    private function slugFor(string $title, ?string $slug): string
    {
        if (null !== $slug && '' !== $slug) {
            return $slug;
        }

        return $this->slugger->slug($title)->lower()->toString();
    }

    /**
     * The slug is a public URL segment, so two forms cannot share one in the
     * same locale — and the database says so too. Caught here to answer with
     * a sentence rather than a constraint violation.
     */
    private function assertSlugIsFree(string $locale, string $slug, ?int $formId): void
    {
        if ($this->translationRepository->isSlugTaken($locale, $slug, $formId)) {
            throw new FieldException(sprintf('translations[%s].slug', $locale), $this->translator->trans('backend.forms.errors.slug_taken', ['{slug}' => $slug]));
        }
    }
}
