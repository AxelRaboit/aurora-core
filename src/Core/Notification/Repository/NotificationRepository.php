<?php

declare(strict_types=1);

namespace Aurora\Core\Notification\Repository;

use Aurora\Core\Notification\Entity\Notification;
use Aurora\Core\Notification\Entity\NotificationInterface;
use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Entity\User;
use DateTimeImmutable;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ResolveTargetEntityRepository<NotificationInterface> */
class NotificationRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class, NotificationInterface::class);
    }

    /** @return list<Notification> */
    public function findRecentForUser(User $user, int $limit = 30): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.recipient = :user')
            ->setParameter('user', $user)
            ->orderBy('n.createdAt', Order::Descending->value)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Whether this person already has an unread notification of this kind
     * about this place.
     *
     * What stops a bell ringing five times for one conversation: repeated news
     * of the same kind, about the same screen, is one thing to go and look at.
     * Keyed on the type and the address rather than on anything inside `data`,
     * because those are two plain columns - a JSON predicate here would be a
     * query nobody can index and, on Postgres, one the `json` type cannot even
     * express without a cast.
     *
     * Read, and it rings again: the previous one has been acted on.
     */
    public function hasUnread(CoreUserInterface $recipient, string $type, string $url): bool
    {
        return 0 < (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->andWhere('n.recipient = :recipient')
            ->andWhere('n.type = :type')
            ->andWhere('n.url = :url')
            ->andWhere('n.readAt IS NULL')
            ->setParameter('recipient', $recipient)
            ->setParameter('type', $type)
            ->setParameter('url', $url)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Everything this person has not read about one screen, oldest first.
     *
     * What the delayed email reads to decide whether there is anything left to
     * say, and to say all of it in one message rather than one per event.
     *
     * @return list<NotificationInterface>
     */
    public function findUnreadForUrl(CoreUserInterface $recipient, string $url): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.recipient = :recipient')
            ->andWhere('n.url = :url')
            ->andWhere('n.readAt IS NULL')
            ->setParameter('recipient', $recipient)
            ->setParameter('url', $url)
            ->orderBy('n.createdAt', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    public function unreadCountForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->andWhere('n.recipient = :user')
            ->andWhere('n.readAt IS NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function markAllReadForUser(User $user): int
    {
        return $this->createQueryBuilder('n')
            ->update()
            ->set('n.readAt', ':now')
            ->andWhere('n.recipient = :user')
            ->andWhere('n.readAt IS NULL')
            ->setParameter('user', $user)
            ->setParameter('now', new DateTimeImmutable())
            ->getQuery()
            ->execute();
    }

    public function deleteAllForUser(User $user): int
    {
        return $this->createQueryBuilder('n')
            ->delete()
            ->andWhere('n.recipient = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }
}
