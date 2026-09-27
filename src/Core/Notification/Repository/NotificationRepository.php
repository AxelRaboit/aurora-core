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
    /**
     * Which of these people still have an unread notification of this type
     * and address, in one query, keyed by user id.
     *
     * @param list<CoreUserInterface> $recipients
     *
     * @return array<int, true>
     */
    public function recipientsWithUnread(array $recipients, string $type, string $url): array
    {
        if ([] === $recipients) {
            return [];
        }

        $ids = $this->createQueryBuilder('n')
            ->select('DISTINCT IDENTITY(n.recipient)')
            ->andWhere('n.recipient IN (:recipients)')
            ->andWhere('n.type = :type')
            ->andWhere('n.url = :url')
            ->andWhere('n.readAt IS NULL')
            ->setParameter('recipients', $recipients)
            ->setParameter('type', $type)
            ->setParameter('url', $url)
            ->getQuery()
            ->getSingleColumnResult();

        return array_fill_keys(array_map(intval(...), $ids), true);
    }

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
     * Everything this person has not read about one screen or a place in it,
     * oldest first: the screen's path, and the same path with a query that
     * points inside it (a card of a space).
     *
     * @return list<NotificationInterface>
     */
    public function findUnreadUnderPath(CoreUserInterface $recipient, string $path): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.recipient = :recipient')
            ->andWhere('n.url = :path OR n.url LIKE :inside')
            ->andWhere('n.readAt IS NULL')
            ->setParameter('recipient', $recipient)
            ->setParameter('path', $path)
            ->setParameter('inside', addcslashes($path, '%_\\').'?%')
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
