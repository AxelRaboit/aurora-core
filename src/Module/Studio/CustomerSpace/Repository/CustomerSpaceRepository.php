<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Enum\CustomerSpaceStatusEnum;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<CustomerSpaceInterface>
 */
class CustomerSpaceRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CustomerSpace::class, CustomerSpaceInterface::class);
    }

    /**
     * Every space, active ones first, then by name.
     *
     * Archived rows are returned rather than filtered out: the page keeps them
     * behind its own switch, and a repository that hid them would need a second
     * method the day somebody looks for last year's client. Ordering by status
     * puts them at the bottom, which is the same answer at a tenth of the cost.
     *
     * The customer and the members are joined now, not looked up per row: the
     * list shows the company's name and the team's faces, and both are one
     * query here and one per space without it.
     *
     * @return list<CustomerSpaceInterface>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('c', 'm', 'u')
            ->join('s.customer', 'c')
            ->leftJoin('s.members', 'm')
            ->leftJoin('m.user', 'u')
            ->orderBy('s.status', Order::Ascending->value)
            ->addOrderBy('s.name', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * Les espaces qu'une personne voit.
     *
     * **Être membre décide enfin de quelque chose.** L'écran fait composer une
     * équipe, ce qui se lit comme une attribution ; jusqu'ici ça n'en était
     * pas une, et quiconque pouvait voir un espace les voyait tous. Un
     * équipier ne voit plus que les siens.
     *
     * `$seesAll` plutôt qu'un rôle lu ici : le dépôt n'a pas à connaître la
     * sécurité, et l'appelant sait déjà si la personne court-circuite les
     * privilèges. C'est aussi ce qui garde la méthode testable sans jeton.
     *
     * @return list<CustomerSpaceInterface>
     */
    public function findVisibleTo(CoreUserInterface $user, bool $seesAll): array
    {
        if ($seesAll) {
            return $this->findAllOrdered();
        }

        // Les identifiants d'abord, la liste ensuite : filtrer sur la jointure
        // qui ramène les membres ne rendrait que les membres retenus par le
        // filtre, donc une équipe amputée d'elle-même sur chaque carte.
        $ids = $this->createQueryBuilder('s')
            ->select('s.id')
            ->join('s.members', 'm')
            ->where('m.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleColumnResult();

        if ([] === $ids) {
            return [];
        }

        return $this->createQueryBuilder('s')
            ->addSelect('c', 'm', 'u')
            ->join('s.customer', 'c')
            ->leftJoin('s.members', 'm')
            ->leftJoin('m.user', 'u')
            ->where('s.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('s.status', Order::Ascending->value)
            ->addOrderBy('s.name', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * Cette personne voit-elle cet espace ?
     *
     * Posée à part de la liste parce que l'écran d'un espace se demande la
     * même chose pour une seule ligne, et qu'y répondre en chargeant les
     * autres serait payer la liste pour une question fermée.
     */
    public function isVisibleTo(CustomerSpaceInterface $space, CoreUserInterface $user, bool $seesAll): bool
    {
        if ($seesAll) {
            return true;
        }

        return 0 < (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->join('s.members', 'm')
            ->where('s = :space')
            ->andWhere('m.user = :user')
            ->setParameter('space', $space)
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * How many spaces name this customer.
     *
     * Asked before a customer is deleted, so the refusal can say how many
     * spaces stand in the way instead of letting the foreign key answer with a
     * driver exception.
     */
    /**
     * Combien d'espaces par état.
     *
     * Groupé plutôt qu'un compte par état : le tableau de bord les affiche
     * tous, et trois requêtes pour trois nombres qui sortent de la même table
     * seraient trois allers-retours pour rien.
     *
     * @return array<string, int>
     */
    public function countGroupedByStatus(): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('s.status AS status, COUNT(s.id) AS total')
            ->groupBy('s.status')
            ->getQuery()
            ->getScalarResult();

        $counts = [];

        foreach ($rows as $row) {
            $status = $row['status'];
            $counts[$status instanceof CustomerSpaceStatusEnum ? $status->value : (string) $status] = (int) $row['total'];
        }

        return $counts;
    }

    public function countForCustomer(CustomerInterface $customer): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.customer = :customer')
            ->setParameter('customer', $customer)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The palette slot the fewest spaces are wearing.
     *
     * Not "the first free one": spaces are deleted and re-created, and a
     * first-free walk hands the same colour to the two most recent ones as soon
     * as an early space goes. Counting spreads them instead, and past eight it
     * keeps spreading rather than piling onto slot one.
     */
    public function leastUsedColourSlot(): int
    {
        /** @var list<array{slot: int, total: int}> $rows */
        $rows = $this->createQueryBuilder('s')
            ->select('s.colourSlot AS slot', 'COUNT(s.id) AS total')
            ->groupBy('s.colourSlot')
            ->getQuery()
            ->getArrayResult();

        $used = [];
        foreach ($rows as $row) {
            $used[(int) $row['slot']] = (int) $row['total'];
        }

        $best = CustomerSpace::DEFAULT_COLOUR_SLOT;
        $bestCount = PHP_INT_MAX;

        for ($slot = 1; $slot <= CustomerSpace::MAX_COLOUR_SLOT; ++$slot) {
            $count = $used[$slot] ?? 0;

            if ($count < $bestCount) {
                $best = $slot;
                $bestCount = $count;
            }
        }

        return $best;
    }
}
