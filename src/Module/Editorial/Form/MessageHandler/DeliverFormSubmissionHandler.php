<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\MessageHandler;

use Aurora\Module\Editorial\Form\Entity\FormSubmissionInterface;
use Aurora\Module\Editorial\Form\Message\DeliverFormSubmissionMessage;
use Aurora\Module\Editorial\Form\Repository\FormSubmissionRepository;
use Aurora\Module\Editorial\Form\Service\FormNotificationService;
use Aurora\Module\Editorial\Form\Service\FormWebhookService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Everything a submission causes once it is safely stored.
 *
 * It used to run inside the visitor's own request, and an unreachable mail
 * server therefore answered them with an error for a message that had in fact
 * been recorded - so they wrote it again. Out here a failure is the worker's
 * problem: the transport retries it, and what it cannot deliver is kept in
 * `failed` instead of being lost.
 *
 * Exceptions are deliberately left to propagate. A retry can mail the owner
 * twice when the first of the two mails went out and the second did not, and
 * can call the webhook again - which is the trade accepted here, because the
 * failure the other way round is a message nobody ever hears about. Webhook
 * payloads carry the submission reference, so a receiver that minds can tell
 * the repeat from a new one.
 */
#[AsMessageHandler]
final readonly class DeliverFormSubmissionHandler
{
    public function __construct(
        private FormSubmissionRepository $submissionRepository,
        private FormNotificationService $notificationService,
        private FormWebhookService $webhookService,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(DeliverFormSubmissionMessage $message): void
    {
        $submission = $this->submissionRepository->find($message->submissionId);

        if (!$submission instanceof FormSubmissionInterface) {
            // Gone between the visitor pressing send and a worker picking this
            // up: an owner emptying the list, or the retention purge. Retrying
            // would never find it, so this ends here rather than failing.
            $this->logger->info('Form submission {id} vanished before its notifications were sent.', [
                'id' => $message->submissionId,
            ]);

            return;
        }

        $form = $submission->getForm();
        $locale = $submission->getLocale();

        $this->notificationService->notifyAdmin($form, $submission, $locale);
        $this->notificationService->notifySubmitter($form, $submission, $locale);

        $this->webhookService->send($form, $submission, $locale);
    }
}
