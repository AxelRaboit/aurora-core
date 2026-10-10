<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceAccess\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLink;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use DateTimeImmutable;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<SpaceAccessLinkInterface>
 */
class SpaceAccessLinkRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SpaceAccessLink::class, SpaceAccessLinkInterface::class);
    }

    /**
     * The row a selector names, with its space and customer joined.
     *
     * By selector only. The secret is never a query parameter: it is compared
     * in constant time against the stored hash once the row is in hand, which
     * is what keeps a timing difference from telling somebody they guessed half
     * of it.
     */
    /** The link a short address names, by the hash of that name. */
    public function findByAliasHash(string $aliasHash): ?SpaceAccessLinkInterface
    {
        return $this->createQueryBuilder('l')
            ->addSelect('s', 'c')
            ->innerJoin('l.space', 's')
            ->innerJoin('s.customer', 'c')
            ->andWhere('l.aliasHash = :aliasHash')
            ->setParameter('aliasHash', $aliasHash)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findBySelector(string $selector): ?SpaceAccessLinkInterface
    {
        return $this->createQueryBuilder('l')
            ->addSelect('s', 'c')
            ->innerJoin('l.space', 's')
            ->innerJoin('s.customer', 'c')
            ->andWhere('l.selector = :selector')
            ->setParameter('selector', $selector)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Every link of a space, newest first.
     *
     * Revoked and expired ones included: the screen that issues links is also
     * the one that answers "who did we send this to", and a list that hid the
     * closed ones would answer it wrong.
     *
     * @return list<SpaceAccessLinkInterface>
     */
    public function findForSpace(CustomerSpaceInterface $space): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.space = :space')
            // Previews live a few minutes and belong to another link: listing
            // them would suggest extra recipients.
            ->andWhere('l.previewOf IS NULL')
            ->setParameter('space', $space)
            ->orderBy('l.createdAt', Order::Descending->value)
            ->addOrderBy('l.id', Order::Descending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * A space's links that can still answer, today.
     *
     * Not revoked, not expired, not previews, and carrying the right to
     * approve: it is the list of people a review invitation wants to reach. A
     * read-only link would receive one it could not act on.
     *
     * @return list<SpaceAccessLinkInterface>
     */
    public function findApproversForSpace(CustomerSpaceInterface $space, DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.space = :space')
            ->andWhere('l.previewOf IS NULL')
            ->andWhere('l.revokedAt IS NULL')
            ->andWhere('l.expiresAt > :now')
            ->andWhere('l.canApprove = true')
            ->setParameter('space', $space)
            ->setParameter('now', $now)
            ->orderBy('l.createdAt', Order::Descending->value)
            ->addOrderBy('l.id', Order::Descending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * Every address of this space that still opens it, previews left out.
     *
     * @return list<SpaceAccessLinkInterface>
     */
    public function findUsableForSpace(CustomerSpaceInterface $space, DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.space = :space')
            ->andWhere('l.previewOf IS NULL')
            ->andWhere('l.revokedAt IS NULL')
            ->andWhere('l.expiresAt > :now')
            ->setParameter('space', $space)
            ->setParameter('now', $now)
            ->orderBy('l.id', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * The live links of these spaces that stop working between now and then,
     * per space: an access that runs out unnoticed is a client who finds a
     * dead page.
     *
     * @param list<int> $spaceIds
     *
     * @return array<int, int> space id => count
     */
    public function countExpiringBySpace(array $spaceIds, DateTimeImmutable $now, DateTimeImmutable $before): array
    {
        if ([] === $spaceIds) {
            return [];
        }

        $rows = $this->createQueryBuilder('l')
            ->select('IDENTITY(l.space) AS space, COUNT(l.id) AS expiring')
            ->where('l.space IN (:spaces)')
            ->andWhere('l.previewOf IS NULL')
            ->andWhere('l.revokedAt IS NULL')
            ->andWhere('l.expiresAt > :now')
            ->andWhere('l.expiresAt <= :before')
            ->setParameter('spaces', $spaceIds)
            ->setParameter('now', $now)
            ->setParameter('before', $before)
            ->groupBy('l.space')
            ->getQuery()
            ->getScalarResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['space']] = (int) $row['expiring'];
        }

        return $counts;
    }

    /** A link's current preview, if there is one. */
    public function findPreviewOf(SpaceAccessLinkInterface $link): ?SpaceAccessLinkInterface
    {
        return $this->createQueryBuilder('l')
            ->where('l.previewOf = :link')
            ->setParameter('link', $link)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
