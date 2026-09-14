<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\MessageHandler;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Editorial\Form\Message\PurgeFormSubmissionsMessage;
use Aurora\Module\Editorial\Form\Repository\FormSubmissionRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

use const DATE_ATOM;

/**
 * Forgets form answers past the age the site keeps them for.
 *
 * A submission holds a name, an address, whatever was written and the IP it
 * came from. Nothing here used to expire, so a form left running collected
 * personal data for as long as the site lived - which is the kind of thing a
 * site has to be able to say a duration about.
 *
 * The answer to "how long" is the site's, not Aurora's, so the setting ships
 * off. A version that started deleting a client's mail the day it installed
 * itself would be worse than the problem.
 */
#[AsMessageHandler]
final readonly class PurgeFormSubmissionsHandler
{
    /**
     * A night's work, at most. The purge runs daily, so a backlog drains over
     * a few nights rather than holding one transaction open over a table.
     */
    private const int BATCH = 500;

    public function __construct(
        private FormSubmissionRepository $submissionRepository,
        private SettingRepository $settingRepository,
        private EntityManagerInterface $entityManager,
        private AuditLogger $auditLogger,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(PurgeFormSubmissionsMessage $message): void
    {
        $days = (int) $this->settingRepository->get(
            ApplicationParameterEnum::FormSubmissionRetentionDays->value,
            ApplicationParameterEnum::FormSubmissionRetentionDays->getDefaultValue(),
        );

        // Zero keeps everything, which is the shipped default: submissions are
        // business records, and a site that has not chosen a duration has not
        // asked for anything to be deleted.
        if ($days <= 0) {
            return;
        }

        $expired = $this->submissionRepository->findSubmittedBefore(
            new DateTimeImmutable(sprintf('-%d days', $days)),
            self::BATCH,
        );

        if ([] === $expired) {
            return;
        }

        foreach ($expired as $submission) {
            // Logged before removal, and without the answers: what is recorded
            // is that a row was forgotten and which one, never a copy of the
            // personal data this exists to erase.
            $this->auditLogger->log('editorial', 'form_submission.purged', 'FormSubmission', $submission->getId(), [
                'reference' => $submission->getReference(),
                'form' => $submission->getForm()->getId(),
                'submittedAt' => $submission->getSubmittedAt()->format(DATE_ATOM),
            ]);

            $this->entityManager->remove($submission);
        }

        $this->entityManager->flush();

        $this->logger->info('Purged {count} form submission(s) older than {days} days.', [
            'count' => count($expired),
            'days' => $days,
        ]);
    }
}
