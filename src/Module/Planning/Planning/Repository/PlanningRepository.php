<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Planning\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Planning\Planning\Entity\Planning;
use Aurora\Module\Planning\Planning\Entity\PlanningInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ResolveTargetEntityRepository<PlanningInterface> */
class PlanningRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Planning::class, PlanningInterface::class);
    }

    /**
     * The calendars one person may look at: their own, plus the shared ones.
     *
     * Ordered by name and not by id, because this list is a sidebar somebody
     * reads rather than a page of results, and creation order means nothing to
     * them.
     *
     * @return list<PlanningInterface>
     */
    public function findVisibleTo(CoreUserInterface $user): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.owner = :owner OR p.visibility = :shared')
            ->setParameter('owner', $user)
            ->setParameter('shared', 'shared')
            ->orderBy('p.name', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * The next free colour for a new calendar.
     *
     * Walks the palette in order and takes the first slot nobody is using, so two
     * calendars made in a row look different without anybody choosing. Once every
     * slot is taken it starts over: eight is the palette's ceiling, and a ninth
     * calendar sharing a colour is better than a ninth colour nobody can tell
     * from the first.
     */
    public function nextFreeColourSlot(): int
    {
        $taken = array_column(
            $this->createQueryBuilder('p')->select('DISTINCT p.colourSlot AS slot')->getQuery()->getArrayResult(),
            'slot',
        );

        for ($slot = 1; $slot <= 8; ++$slot) {
            if (!in_array($slot, $taken, true)) {
                return $slot;
            }
        }

        return 1;
    }
}
