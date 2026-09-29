<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deck\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Core\Search\LikePattern;
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
     * Every deck with its slides, for the pictures they draw.
     *
     * What the library's usage badges walk. `findAllForList()` left the
     * slides lazy, so the walk cost a query per deck, each time the library
     * opened.
     *
     * @return list<DeckInterface>
     */
    public function findAllWithSlides(): array
    {
        /** @var list<DeckInterface> $decks */
        $decks = $this->createQueryBuilder('d')
            ->leftJoin('d.slides', 's')->addSelect('s')
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

    /**
     * Les présentations dont le titre contient le terme.
     *
     * Le client et la catégorie viennent avec, parce que la recherche globale
     * les affiche sous le titre. Les plus récemment modifiées d'abord, comme
     * la liste.
     *
     * @return list<DeckInterface>
     */
    public function searchByTitle(string $term, int $limit): array
    {
        if ('' === mb_trim($term)) {
            return [];
        }

        return $this->createQueryBuilder('d')
            ->leftJoin('d.category', 'c')->addSelect('c')
            ->leftJoin('d.customer', 'cu')->addSelect('cu')
            ->where('LOWER(d.title) LIKE :term')
            ->setParameter('term', LikePattern::contains($term))
            ->orderBy('d.updatedAt', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
