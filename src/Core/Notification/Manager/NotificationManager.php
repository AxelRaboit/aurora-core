<?php

declare(strict_types=1);

namespace Aurora\Core\Notification\Manager;

use Aurora\Core\Notification\Entity\Notification;
use Aurora\Core\Notification\Entity\NotificationInterface;
use Aurora\Core\Notification\Repository\NotificationRepository;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Lightweight notifier - persists in-app notifications and (optionally) sends email.
 * Keep email synchronous for now; queueing via Messenger is a future improvement.
 */
#[AsAlias(NotificationManagerInterface::class)]
class NotificationManager implements NotificationManagerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly NotificationRepository $notificationRepository,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function notify(
        CoreUserInterface $recipient,
        string $type,
        string $title,
        ?string $body = null,
        ?string $url = null,
        array $data = [],
    ): NotificationInterface {
        $notification = $this->createNotification();
        $notification->setRecipient($recipient)
            ->setType($type)
            ->setTitle($title)
            ->setBody($body)
            ->setUrl($url)
            ->setData($data);
        $this->entityManager->persist($notification);
        $this->entityManager->flush();

        return $notification;
    }

    public function markRead(NotificationInterface $notification): void
    {
        if (!$notification->getReadAt() instanceof DateTimeImmutable) {
            $notification->markAsRead();
            $this->entityManager->flush();
        }
    }

    public function markAllReadForUser(User $user): int
    {
        return $this->notificationRepository->markAllReadForUser($user);
    }

    public function delete(NotificationInterface $notification): void
    {
        $this->entityManager->remove($notification);
        $this->entityManager->flush();
    }

    public function deleteAllForUser(User $user): int
    {
        return $this->notificationRepository->deleteAllForUser($user);
    }

    protected function createNotification(): NotificationInterface
    {
        return new Notification();
    }
}
