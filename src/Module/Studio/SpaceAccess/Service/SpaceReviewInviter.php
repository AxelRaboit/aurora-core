<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceAccess\Service;

use Aurora\Core\Mail\Service\MailService;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceAccess\Repository\SpaceAccessLinkRepository;
use Aurora\Module\Studio\SpaceContent\Workload\SpaceWorkload;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Ask a client to go and review their content plan.
 *
 * **Triggered by hand, and that is the point.** The studio prepares a week of
 * posts in one go; an automatic send for each card that becomes visible would
 * fill the client's inbox while the batch is still being built. The one who
 * knows when the batch is ready is the one who prepared it.
 *
 * **The address they already have, not a new one.** This used to issue a new
 * link per recipient and revoke the old one, because the long address exists
 * in readable form only at creation. Each invitation therefore closed the
 * address the client had bookmarked. The mail now carries the address the
 * application can rebuild ({@see SpaceLinkMailer::addressOf()}), which opens
 * the same page, so an invitation changes nothing about how they get in.
 *
 * **Nothing goes out if there is nothing to review.** An email announcing zero
 * pending posts is an email that teaches people to ignore the next ones.
 */
final readonly class SpaceReviewInviter
{
    public function __construct(
        private SpaceAccessLinkRepository $accessLinkRepository,
        private SpaceWorkload $workload,
        private MailService $mailService,
        private SpaceLinkMailer $linkMailer,
        private LoggerInterface $logger,
    ) {}

    /**
     * Writes to everyone who may answer in this space.
     *
     * @return array{awaiting: int, notified: int} what was waiting, and how
     *                                             many people were notified
     */
    public function invite(CustomerSpaceInterface $space): array
    {
        // As everywhere else: what the client's page shows and waits for. The
        // count included internal steps, so it announced items to the client
        // that they could not find when opening their page.
        $awaiting = $this->workload->forSpace($space)->withClient;

        if (0 === $awaiting) {
            return ['awaiting' => 0, 'notified' => 0];
        }

        $notified = 0;

        foreach ($this->accessLinkRepository->findApproversForSpace($space, new DateTimeImmutable()) as $link) {
            $url = $this->linkMailer->addressOf($link);
            if (null === $url) {
                continue;
            }

            try {
                $this->write($space, $link, $url, $awaiting);
            } catch (Throwable $exception) {
                // The address they hold still works: nothing was revoked to
                // send this, so a mail server that does not answer costs one
                // message and nothing else.
                $this->logger->error('Space review invitation could not be sent to {email}: {reason}', [
                    'email' => $link->getRecipientEmail(),
                    'reason' => $exception->getMessage(),
                ]);

                continue;
            }

            ++$notified;
        }

        return ['awaiting' => $awaiting, 'notified' => $notified];
    }

    private function write(CustomerSpaceInterface $space, SpaceAccessLinkInterface $link, string $url, int $awaiting): void
    {
        $this->mailService->send(
            to: $link->getRecipientEmail(),
            subjectKey: 'studio.email.space_review.subject',
            template: '@Studio/email/space_review.html.twig',
            context: [
                'space' => $space,
                'awaiting' => $awaiting,
                'url' => $url,
                'expiresAt' => $link->getExpiresAt(),
            ],
            locale: $this->linkMailer->localeOf($space),
            subjectParameters: ['{space}' => $space->getName(), '{count}' => (string) $awaiting],
        );
    }
}
