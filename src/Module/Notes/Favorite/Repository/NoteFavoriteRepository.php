<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Favorite\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Notes\Favorite\Entity\NoteFavorite;
use Aurora\Module\Notes\Favorite\Entity\NoteFavoriteInterface;
use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<NoteFavoriteInterface>
 */
class NoteFavoriteRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NoteFavorite::class, NoteFavoriteInterface::class);
    }

    /** When a person pinned this note or this folder, or nothing. */
    public function favoritedAt(CoreUserInterface $user, MarkdownNoteInterface|NoteFolderInterface $item): ?string
    {
        $favorite = $this->findOneBy($item instanceof MarkdownNoteInterface ? ['user' => $user, 'note' => $item] : ['user' => $user, 'folder' => $item]);

        return $favorite?->getCreatedAt()->format(DateTimeInterface::ATOM);
    }

    /**
     * What a person has pinned: the id of the note or the folder, and the
     * time of the action, which orders the panel.
     *
     * @return array{notes: array<int, string>, folders: array<int, string>}
     */
    public function mapFor(CoreUserInterface $user): array
    {
        $rows = $this->createQueryBuilder('f')
            ->select('IDENTITY(f.note) AS noteId', 'IDENTITY(f.folder) AS folderId', 'f.createdAt')
            ->where('f.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getArrayResult();

        $map = ['notes' => [], 'folders' => []];
        foreach ($rows as $row) {
            $at = $row['createdAt']->format(DateTimeInterface::ATOM);

            if (null !== $row['noteId']) {
                $map['notes'][(int) $row['noteId']] = $at;
            } elseif (null !== $row['folderId']) {
                $map['folders'][(int) $row['folderId']] = $at;
            }
        }

        return $map;
    }
}
