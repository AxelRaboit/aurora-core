<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\ClientNotice\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Studio\ClientNotice\Entity\ClientNotice;
use Aurora\Module\Studio\ClientNotice\Entity\ClientNoticeInterface;
use Aurora\Module\Studio\ClientNotice\Enum\ClientNoticeTypeEnum;
use Aurora\Module\Studio\CustomerSpace\Enum\ClientDigestModeEnum;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use DateTimeImmutable;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<ClientNoticeInterface>
 */
class ClientNoticeRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClientNotice::class, ClientNoticeInterface::class);
    }

    /**
     * What a digest would carry for this link: not seen, not mailed yet.
     *
     * @return list<ClientNoticeInterface>
     */
    public function findPendingForLink(SpaceAccessLinkInterface $link): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.link = :link')
            ->andWhere('n.seenAt IS NULL')
            ->andWhere('n.emailedAt IS NULL')
            ->setParameter('link', $link)
            ->orderBy('n.createdAt', Order::Ascending->value)
            ->addOrderBy('n.id', Order::Ascending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * What happened since this person last opened their page, mailed or not.
     *
     * @return list<ClientNoticeInterface>
     */
    public function findUnseenForLink(SpaceAccessLinkInterface $link, int $limit = 100): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.link = :link')
            ->andWhere('n.seenAt IS NULL')
            ->setParameter('link', $link)
            ->orderBy('n.createdAt', Order::Descending->value)
            ->addOrderBy('n.id', Order::Descending->value)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Everything this link had not seen is seen now. One statement, whatever the count. */
    public function markSeenForLink(SpaceAccessLinkInterface $link, DateTimeImmutable $at): int
    {
        return (int) $this->createQueryBuilder('n')
            ->update()
            ->set('n.seenAt', ':at')
            ->where('n.link = :link')
            ->andWhere('n.seenAt IS NULL')
            ->setParameter('at', $at)
            ->setParameter('link', $link)
            ->getQuery()
            ->execute();
    }

    /**
     * The links with something to tell, in spaces that send their digest in
     * the morning.
     *
     * @return list<SpaceAccessLinkInterface>
     */
    public function findLinksWithPendingIn(ClientDigestModeEnum $mode): array
    {
        $ids = $this->createQueryBuilder('n')
            ->select('DISTINCT IDENTITY(n.link)')
            ->join('n.link', 'l')
            ->join('l.space', 's')
            ->where('n.seenAt IS NULL')
            ->andWhere('n.emailedAt IS NULL')
            ->andWhere('s.clientDigest = :mode')
            ->andWhere('s.deletedAt IS NULL')
            ->setParameter('mode', $mode)
            ->getQuery()
            ->getSingleColumnResult();

        if ([] === $ids) {
            return [];
        }

        return $this->getEntityManager()->getRepository(SpaceAccessLinkInterface::class)->findBy(['id' => $ids]);
    }

    /** Whether this link was already told this, about this subject, since that moment. */
    public function wasTold(SpaceAccessLinkInterface $link, ClientNoticeTypeEnum $type, ?string $subject, DateTimeImmutable $since): bool
    {
        $builder = $this->createQueryBuilder('n')
            ->select('n.id')
            ->where('n.link = :link')
            ->andWhere('n.type = :type')
            ->andWhere('n.createdAt >= :since')
            ->setParameter('link', $link)
            ->setParameter('type', $type)
            ->setParameter('since', $since)
            ->setMaxResults(1);

        if (null === $subject) {
            $builder->andWhere('n.subject IS NULL');
        } else {
            $builder->andWhere('n.subject = :subject')->setParameter('subject', $subject);
        }

        return null !== $builder->getQuery()->getOneOrNullResult();
    }

    /**
     * Forgets what was seen long ago. The page shows what is new, and a
     * notice read three months back is not news to anybody.
     */
    public function deleteSeenBefore(DateTimeImmutable $cutoff): int
    {
        return (int) $this->createQueryBuilder('n')
            ->delete()
            ->where('n.seenAt IS NOT NULL')
            ->andWhere('n.seenAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();
    }
}
