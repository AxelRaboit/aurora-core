<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Link\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Planning\Link\Entity\PlanningShareLink;
use Aurora\Module\Planning\Link\Entity\PlanningShareLinkInterface;
use Aurora\Module\Planning\Planning\Entity\PlanningInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<PlanningShareLinkInterface>
 */
class PlanningShareLinkRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanningShareLink::class, PlanningShareLinkInterface::class);
    }

    /**
     * A link by its token, whatever its state.
     *
     * Deliberately not filtered on expiry or revocation. The route has to tell a
     * token that never existed from one that has stopped working - not to say so on
     * screen, where both get the same answer, but to record the second as a use and
     * leave the first alone. A query that hid expired rows would make every visit
     * to a closed link look like a probe for a random string.
     */
    public function findByToken(string $token): ?PlanningShareLinkInterface
    {
        return $this->findOneBy(['token' => $token]);
    }

    /**
     * Every link reaching a calendar this person owns.
     *
     * Owned, not visible: a guest address is something you hand out about your own
     * calendar, and somebody a calendar was shared *with* has no business
     * enumerating - or revoking - the addresses its owner created.
     *
     * @return list<PlanningShareLinkInterface>
     */
    public function findForOwner(CoreUserInterface $owner): array
    {
        /** @var list<PlanningShareLinkInterface> $result */
        $result = $this->createQueryBuilder('l')
            ->distinct()
            ->innerJoin('l.calendars', 'c')
            ->where('c.owner = :owner')
            ->setParameter('owner', $owner)
            // Newest first: the one just created is the one being looked for.
            ->orderBy('l.createdAt', Order::Descending->value)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * The links pointing at one calendar, for the modal that manages it.
     *
     * @return list<PlanningShareLinkInterface>
     */
    public function findForCalendar(PlanningInterface $planning): array
    {
        /** @var list<PlanningShareLinkInterface> $result */
        $result = $this->createQueryBuilder('l')
            ->innerJoin('l.calendars', 'c')
            ->where('c = :planning')
            ->setParameter('planning', $planning)
            ->orderBy('l.createdAt', Order::Descending->value)
            ->getQuery()
            ->getResult();

        return $result;
    }
}
