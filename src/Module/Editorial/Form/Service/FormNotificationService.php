<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\Service;

use Aurora\Core\Mail\Service\MailService;
use Aurora\Module\Editorial\Form\Entity\FormInterface;
use Aurora\Module\Editorial\Form\Entity\FormSubmissionInterface;
use Aurora\Module\Editorial\Form\Enum\FormFieldTypeEnum;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The two emails a submission causes: one to whoever watches the form, one
 * back to the person who filled it in.
 */
readonly class FormNotificationService
{
    public function __construct(
        private MailService $mail,
        private FormFieldLabeler $labeler,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    /**
     * Falls back to the site administrator when the form names no address:
     * a form whose submissions reach nobody is a form nobody answers, and
     * that failure is invisible from the builder.
     *
     * Replies go to whoever wrote, not to the sending address. Without it the
     * only way to answer a message is to reopen the builder and copy the
     * address out by hand, once per person.
     */
    public function notifyAdmin(FormInterface $form, FormSubmissionInterface $submission, string $locale): void
    {
        $recipient = mb_trim((string) $form->getNotifyEmail());

        // The site's own language, not the visitor's. This mail is read by
        // whoever runs the site, and a submission through the Spanish form
        // used to arrive with its questions - and its subject line - in
        // Spanish, which says nothing about the message and makes the inbox
        // unsortable.
        $readerLocale = $this->mail->emailLocale() ?? $locale;
        $context = $this->context($form, $submission, $readerLocale) + [
            'submissionUrl' => $this->submissionUrl($form),
        ];
        $replyTo = $this->submitterEmail($form, $submission);

        if ('' === $recipient) {
            $this->mail->sendToAdmin(
                'editorial.mail.form.subject_admin',
                '@Editorial/email/form_submission.html.twig',
                $context,
                ['{form}' => $context['formTitle']],
                replyTo: $replyTo,
            );

            return;
        }

        $this->mail->send(
            $recipient,
            'editorial.mail.form.subject_admin',
            '@Editorial/email/form_submission.html.twig',
            $context,
            locale: $readerLocale,
            subjectParams: ['{form}' => $context['formTitle']],
            replyTo: $replyTo,
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

    /**
     * Where the submission can be read in full, with the ones around it.
     *
     * The form's own screen rather than the submission's: there is no page for
     * a single one, and the list opens on the newest - which is the one this
     * mail announces.
     */
    private function submissionUrl(FormInterface $form): string
    {
        return $this->urlGenerator->generate(
            'backend_editorial_forms_show',
            ['id' => $form->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
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
