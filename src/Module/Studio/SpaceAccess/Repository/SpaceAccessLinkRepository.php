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
            // Les aperçus vivent quelques minutes et appartiennent à un autre
            // lien : les lister ferait croire à des destinataires en trop.
            ->andWhere('l.previewOf IS NULL')
            ->setParameter('space', $space)
            ->orderBy('l.createdAt', Order::Descending->value)
            ->addOrderBy('l.id', Order::Descending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * Les liens d'un espace qui peuvent encore répondre, aujourd'hui.
     *
     * Ni révoqués, ni expirés, ni aperçus, et porteurs du droit de valider :
     * c'est la liste des gens à qui une invitation à relire veut parler. Un
     * lien en lecture seule en recevrait une qu'il ne pourrait pas honorer.
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

    /** L'aperçu en cours d'un lien, s'il y en a un. */
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
