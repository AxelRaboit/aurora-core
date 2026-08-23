<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Event\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Planning\Event\Entity\PlanningEventAlert;
use Aurora\Module\Planning\Event\Entity\PlanningEventAlertInterface;
use DateTimeImmutable;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ResolveTargetEntityRepository<PlanningEventAlertInterface> */
class PlanningEventAlertRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanningEventAlert::class, PlanningEventAlertInterface::class);
    }

    /**
     * The alerts that should have fired by now and have not.
     *
     * `remindAt <= now AND sentAt IS NULL`, which is exactly the index on the
     * table. Runs once a minute for the life of the application, so it is the one
     * query in this module that had to be cheap by construction rather than by
     * luck - that is why `remindAt` is a stored column and not `start_at` minus
     * an interval.
     *
     * **No lower bound on how late.** A worker stopped for an hour comes back to
     * an hour of alerts and sends them, rather than deciding they are stale
     * and dropping them silently. A alert arriving late is information; one
     * that never arrives is a bug the user reports as "it did not work".
     *
     * @return list<PlanningEventAlertInterface>
     */
    public function findDue(DateTimeImmutable $now, int $limit = 200): array
    {
        return $this->createQueryBuilder('r')
            // The event and its calendar come along: the notification names both,
            // and lazy-loading them would be two queries per alert.
            ->addSelect('e', 'p')
            ->innerJoin('r.event', 'e')
            ->innerJoin('e.planning', 'p')
            ->where('r.remindAt <= :now')
            ->andWhere('r.sentAt IS NULL')
            ->setParameter('now', $now)
            ->orderBy('r.remindAt', Order::Ascending->value)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
