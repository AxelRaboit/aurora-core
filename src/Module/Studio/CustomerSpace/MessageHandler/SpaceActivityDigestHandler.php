<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\MessageHandler;

use Aurora\Core\Mail\Service\MailService;
use Aurora\Core\Notification\Entity\NotificationInterface;
use Aurora\Core\Notification\Repository\NotificationRepository;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\CustomerSpace\Message\SpaceActivityDigestMessage;
use Aurora\Module\Studio\CustomerSpace\Repository\CustomerSpaceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The second look, and the only thing that decides whether an email is sent.
 *
 * **Three questions, in this order, and any of them ends it.** Is anything
 * still unread here - if the person opened the space in the meantime, nothing
 * is sent and that is the point of the delay. Have we already written about
 * this run of unread news - if so we stay quiet, because the mailbox has
 * already been told and repeating it is what turns a useful mail into one
 * people filter. Is there an address to write to at all.
 *
 * **One mail for the space, not one per event.** A client who answers four
 * cards and then writes a message has done one thing worth an interruption:
 * something happened in their space. The unread notifications are listed inside
 * it, so the mail says what, and the link goes to the space rather than to any
 * one of them.
 *
 * **`emailed` is written on every notification it covers**, which is what makes
 * the silence last: further events fold into the same unread run and find the
 * flag already set. Reading the notifications is what re-arms it - the person
 * has come back, so the next thing that happens while they are away is worth a
 * mail again.
 */
#[AsMessageHandler]
final readonly class SpaceActivityDigestHandler
{
    public function __construct(
        private NotificationRepository $notifications,
        private UserRepository $users,
        private CustomerSpaceRepository $spaces,
        private EntityManagerInterface $entityManager,
        private MailService $mail,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    public function __invoke(SpaceActivityDigestMessage $message): void
    {
        $recipient = $this->users->find($message->recipientId);
        $space = $this->spaces->find($message->spaceId);

        // An account deleted, or a space deleted, between the dispatch and
        // here. Neither is an error: there is simply nobody to tell, or
        // nothing to tell them about.
        if (!$recipient instanceof User || null === $space) {
            return;
        }

        $url = $this->urlGenerator->generate(
            'workspace_space_content',
            ['id' => $space->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $unread = $this->notifications->findUnreadForUrl($recipient, $url);

        if ([] === $unread) {
            // Read in the meantime. The delay did its job.
            return;
        }

        foreach ($unread as $notification) {
            if (true === ($notification->getData()['emailed'] ?? false)) {
                // Already written about this run. Quiet until they come back.
                return;
            }
        }

        $this->mail->send(
            to: $recipient->getEmail(),
            subjectKey: 'studio.email.space_activity.subject',
            template: '@Studio/email/space_activity.html.twig',
            context: [
                'space' => $space,
                'lines' => array_map(
                    static fn (NotificationInterface $notification): string => $notification->getTitle(),
                    $unread,
                ),
                'url' => $url,
            ],
            subjectParams: ['{space}' => $space->getName()],
        );

        foreach ($unread as $notification) {
            $notification->setData(['emailed' => true] + ($notification->getData() ?? []));
        }

        $this->entityManager->flush();
    }
}
