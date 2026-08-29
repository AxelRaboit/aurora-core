<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Share\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Share\Entity\MarkdownNoteShareLink;
use Aurora\Module\Notes\Share\Entity\MarkdownNoteShareLinkInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ResolveTargetEntityRepository<MarkdownNoteShareLinkInterface> */
class MarkdownNoteShareLinkRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarkdownNoteShareLink::class, MarkdownNoteShareLinkInterface::class);
    }

    public function findByToken(string $token): ?MarkdownNoteShareLinkInterface
    {
        return $this->findOneBy(['token' => $token]);
    }

    /**
     * Every link ever made for a note, revoked ones included.
     *
     * Revoked rows stay in the list on purpose: "who did I share this with, and
     * when did I stop" is the question the screen exists to answer, and hiding
     * the revocations answers only half of it.
     *
     * @return list<MarkdownNoteShareLinkInterface>
     */
    public function findForNote(MarkdownNoteInterface $note): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.note = :note')
            ->setParameter('note', $note)
            ->orderBy('l.createdAt', Order::Descending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * Links belonging to notes this person owns.
     *
     * The ownership filter lives in the query rather than in a check afterwards:
     * a link is addressed by a token, so any route that reaches one by id has to
     * prove whose it is before it does anything with it.
     */
    public function findOneOwnedBy(CoreUserInterface $user, int $id): ?MarkdownNoteShareLinkInterface
    {
        return $this->createQueryBuilder('l')
            ->join('l.note', 'n')
            ->where('l.id = :id')
            ->andWhere('n.user = :user')
            ->setParameter('id', $id)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
