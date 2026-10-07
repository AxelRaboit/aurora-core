<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceAccess\Service;

use Aurora\Core\Mail\Service\MailService;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceAccess\Manager\SpaceAccessLinkManager;
use Aurora\Module\Studio\SpaceAccess\Manager\SpaceAccessLinkManagerInterface;
use Aurora\Module\Studio\SpaceAccess\Repository\SpaceAccessLinkRepository;
use Aurora\Module\Studio\SpaceContent\Workload\SpaceWorkload;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Throwable;

/**
 * Ask a client to go and review their content plan.
 *
 * **Triggered by hand, and that is the point.** The studio prepares a week of
 * posts in one go; an automatic send for each card that becomes visible would
 * fill the client's inbox while the batch is still being built. The one who
 * knows when the batch is ready is the one who prepared it.
 *
 * **A new link per recipient, the old one revoked.** A link's token only
 * exists in clear text when it is created: only its hash is stored, so that a
 * stolen database does not open a client's content plan. The address of a
 * link already issued therefore cannot be put in an email again, and a new
 * one has to be issued. Revoking the previous one is what keeps a client from
 * piling up six open addresses after six invitations: they always have
 * exactly one valid, the last one received.
 *
 * **Nothing goes out if there is nothing to review.** An email announcing zero
 * pending posts is an email that teaches people to ignore the next ones.
 */
final readonly class SpaceReviewInviter
{
    public function __construct(
        private SpaceAccessLinkRepository $links,
        private SpaceAccessLinkManagerInterface $linkManager,
        private SpaceWorkload $workload,
        private MailService $mail,
        private UrlGeneratorInterface $urlGenerator,
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

        $now = new DateTimeImmutable();
        $notified = 0;

        foreach ($this->links->findApproversForSpace($space, $now) as $previous) {
            $fresh = $this->reissue($previous);

            try {
                $this->write($space, $fresh, $awaiting);
            } catch (Throwable $exception) {
                // **The email first, the revocation after.** A mail server that
                // does not answer would otherwise leave the client with no
                // valid address and without the message that gave them a new
                // one, that is, locked out without knowing it. Here their old
                // address keeps working, and it is the new one, which nobody
                // received, that gets closed.
                $this->linkManager->revoke($fresh);
                $this->logger->error('Space review invitation could not be sent to {email}: {reason}', [
                    'email' => $previous->getRecipientEmail(),
                    'reason' => $exception->getMessage(),
                ]);

                continue;
            }

            $this->linkManager->revoke($previous);
            ++$notified;
        }

        return ['awaiting' => $awaiting, 'notified' => $notified];
    }

    /**
     * The same link, brand new.
     *
     * The rights are copied: the invitation does not change what the person is
     * allowed to do, it only gives them an address again. The validity starts
     * again from the default delay rather than from what was left on the
     * previous one, otherwise an invitation sent the day before an expiry would
     * give one day to answer.
     */
    private function reissue(SpaceAccessLinkInterface $previous): SpaceAccessLinkInterface
    {
        return $this->linkManager->issue(
            $previous->getSpace(),
            $previous->getRecipientEmail(),
            $previous->getLabel(),
            SpaceAccessLinkManager::DEFAULT_VALID_DAYS,
            $previous->canApprove(),
            $previous->canComment(),
            $previous->canChat(),
            $previous->canUpload(),
            $previous->canSeeDrive(),
        );
    }

    private function write(CustomerSpaceInterface $space, SpaceAccessLinkInterface $link, int $awaiting): void
    {
        $this->mail->send(
            to: $link->getRecipientEmail(),
            subjectKey: 'studio.email.space_review.subject',
            template: '@Studio/email/space_review.html.twig',
            context: [
                'space' => $space,
                'awaiting' => $awaiting,
                'url' => $this->urlGenerator->generate(
                    'public_space_show',
                    [
                        'selector' => $link->getSelector(),
                        // Readable only once, at creation: this message is
                        // exactly why one was just created.
                        'token' => $link->getPlainToken(),
                    ],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                ),
                'expiresAt' => $link->getExpiresAt(),
            ],
            subjectParameters: ['{space}' => $space->getName(), '{count}' => (string) $awaiting],
        );
    }
}
