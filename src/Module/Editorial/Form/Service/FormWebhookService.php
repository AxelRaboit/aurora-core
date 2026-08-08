<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\Service;

use Aurora\Module\Editorial\Form\Entity\FormInterface;
use Aurora\Module\Editorial\Form\Entity\FormSubmissionInterface;
use DateTimeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Announces a submission to the URL the form names.
 *
 * Two things the reference did not do.
 *
 * The payload carried the visitor's IP address. A form's webhook points at
 * whatever third party an administrator configured, so every submission
 * shipped a visitor's address off the installation — they filled in a contact
 * form, they did not agree to that. The address stays here, where it is only
 * ever used to recognise abuse.
 *
 * And the delivery was unsigned, so a receiver had no way to tell a real one
 * from anyone who had guessed the URL. It is signed now, with the application
 * secret, over the exact bytes sent.
 */
readonly class FormWebhookService
{
    public const string SIGNATURE_HEADER = 'X-Aurora-Signature';

    private const int TIMEOUT_SECONDS = 5;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private FormFieldLabeler $labeler,
        #[Autowire(param: 'kernel.secret')]
        private string $secret,
    ) {}

    public function send(FormInterface $form, FormSubmissionInterface $submission, string $locale): void
    {
        $url = mb_trim((string) $form->getWebhookUrl());
        if ('' === $url) {
            return;
        }

        try {
            $body = json_encode(
                $this->payload($form, $submission, $locale),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            );

            $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    self::SIGNATURE_HEADER => hash_hmac('sha256', $body, $this->secret),
                ],
                'body' => $body,
                'timeout' => self::TIMEOUT_SECONDS,
            ]);
        } catch (Throwable $throwable) {
            // A webhook that cannot be reached must not lose the submission
            // that triggered it — the visitor has done nothing wrong, and the
            // data is already stored.
            $this->logger->warning('Form webhook delivery failed.', [
                'url' => $url,
                'form' => $form->getId(),
                'submission' => $submission->getReference(),
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function payload(FormInterface $form, FormSubmissionInterface $submission, string $locale): array
    {
        return [
            'event' => 'form.submitted',
            'form' => [
                'id' => $form->getId(),
                'reference' => $form->getReference(),
                'slug' => $this->labeler->slug($form, $locale),
                'title' => $this->labeler->title($form, $locale),
            ],
            'submission' => [
                'reference' => $submission->getReference(),
                'locale' => $submission->getLocale(),
                'submittedAt' => $submission->getSubmittedAt()->format(DateTimeInterface::ATOM),
            ],
            'fields' => $this->labeler->pairs($form, $submission, $locale),
        ];
    }
}
