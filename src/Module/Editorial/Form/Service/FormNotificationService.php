<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\Service;

use Aurora\Core\Mail\Service\MailService;
use Aurora\Module\Editorial\Form\Entity\FormInterface;
use Aurora\Module\Editorial\Form\Entity\FormSubmissionInterface;
use Aurora\Module\Editorial\Form\Enum\FormFieldTypeEnum;

/**
 * The two emails a submission causes: one to whoever watches the form, one
 * back to the person who filled it in.
 */
readonly class FormNotificationService
{
    public function __construct(
        private MailService $mail,
        private FormFieldLabeler $labeler,
    ) {}

    /**
     * Falls back to the site administrator when the form names no address:
     * a form whose submissions reach nobody is a form nobody answers, and
     * that failure is invisible from the builder.
     */
    public function notifyAdmin(FormInterface $form, FormSubmissionInterface $submission, string $locale): void
    {
        $recipient = mb_trim((string) $form->getNotifyEmail());
        $context = $this->context($form, $submission, $locale);

        if ('' === $recipient) {
            $this->mail->sendToAdmin(
                'editorial.mail.form.subject_admin',
                '@Editorial/email/form_submission.html.twig',
                $context,
                ['{form}' => $context['formTitle']],
            );

            return;
        }

        $this->mail->send(
            $recipient,
            'editorial.mail.form.subject_admin',
            '@Editorial/email/form_submission.html.twig',
            $context,
            locale: $locale,
            subjectParams: ['{form}' => $context['formTitle']],
        );
    }

    /**
     * Only when the form asked for an address and the visitor gave a usable
     * one. Confirming to an unvalidated address would make the site a way to
     * send mail to anybody.
     */
    public function notifySubmitter(FormInterface $form, FormSubmissionInterface $submission, string $locale): void
    {
        $email = $this->submitterEmail($form, $submission);
        if (null === $email) {
            return;
        }

        $context = $this->context($form, $submission, $locale);

        $this->mail->send(
            $email,
            'editorial.mail.form.subject_confirmation',
            '@Editorial/email/form_submission_confirmation.html.twig',
            $context,
            locale: $locale,
            subjectParams: ['{form}' => $context['formTitle']],
        );
    }

    private function submitterEmail(FormInterface $form, FormSubmissionInterface $submission): ?string
    {
        $data = $submission->getData();

        foreach ($form->getFields() as $field) {
            if (FormFieldTypeEnum::Email !== $field->getType()) {
                continue;
            }

            $value = $data[(string) $field->getId()] ?? null;
            if (is_string($value) && false !== filter_var($value, FILTER_VALIDATE_EMAIL)) {
                return $value;
            }
        }

        return null;
    }

    /** @return array{formTitle: string, submission: FormSubmissionInterface, pairs: list<array{label: string, value: string}>} */
    private function context(FormInterface $form, FormSubmissionInterface $submission, string $locale): array
    {
        return [
            'formTitle' => $this->labeler->title($form, $locale),
            'submission' => $submission,
            'pairs' => $this->labeler->pairs($form, $submission, $locale),
        ];
    }
}
