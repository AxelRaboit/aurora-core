<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deck\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Studio\Deck\Entity\Deck;
use Aurora\Module\Studio\Deck\Entity\DeckInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<DeckInterface>
 */
class DeckRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Deck::class, DeckInterface::class);
    }

    /**
     * Every deck, newest first.
     *
     * The opposite of the customer list, and for the opposite reason: a deck is
     * looked for by what was worked on lately, not by a name one already knows.
     *
     * @return list<DeckInterface>
     */
    public function findAllForList(): array
    {
        /** @var list<DeckInterface> $decks */
        $decks = $this->createQueryBuilder('d')
            ->leftJoin('d.category', 'c')->addSelect('c')
            ->leftJoin('d.customer', 'cu')->addSelect('cu')
            ->orderBy('d.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $decks;
    }

    /**
     * How many slides each deck holds, indexed by deck id.
     *
     * @return array<int, int>
     */
    public function countSlidesByDeck(): array
    {
        /** @var list<array{id: int, total: int}> $rows */
        $rows = $this->createQueryBuilder('d')
            ->select('d.id AS id, COUNT(s.id) AS total')
            ->leftJoin('d.slides', 's')
            ->groupBy('d.id')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['id']] = (int) $row['total'];
        }

        return $counts;
    }
}
