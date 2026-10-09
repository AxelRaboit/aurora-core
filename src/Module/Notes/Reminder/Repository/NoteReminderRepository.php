<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Reminder\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Reminder\Entity\NoteReminder;
use Aurora\Module\Notes\Reminder\Entity\NoteReminderInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<NoteReminderInterface>
 */
class NoteReminderRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NoteReminder::class, NoteReminderInterface::class);
    }

    /** The reminder a person is waiting for on a note, if any. */
    public function findWaiting(CoreUserInterface $user, MarkdownNoteInterface $note): ?NoteReminderInterface
    {
        return $this->createQueryBuilder('r')
            ->where('r.user = :user')
            ->andWhere('r.note = :note')
            ->andWhere('r.sentAt IS NULL')
            ->setParameter('user', $user)
            ->setParameter('note', $note)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The reminders whose time has come, oldest first.
     *
     * @return list<NoteReminderInterface>
     */
    public function findDue(DateTimeImmutable $now, int $limit = 200): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('n')
            ->join('r.note', 'n')
            ->where('r.sentAt IS NULL')
            ->andWhere('r.remindAt <= :now')
            ->setParameter('now', $now)
            ->orderBy('r.remindAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
