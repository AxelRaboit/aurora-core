<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceNote\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceNote\Entity\SpaceNote;
use Aurora\Module\Studio\SpaceNote\Entity\SpaceNoteInterface;
use Doctrine\Common\Collections\Order;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<SpaceNoteInterface>
 */
class SpaceNoteRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SpaceNote::class, SpaceNoteInterface::class);
    }

    /**
     * Every note of a space: pinned first, then the most recently touched.
     *
     * One order for both views, because they are two readings of the same wall
     * and a note that is third in the list should be third on the wall. Sorted
     * on `updatedAt` rather than `createdAt`: a note one keeps coming back to
     * is the one still in play.
     *
     * The whole space at once, like its threads and its files - a space's notes
     * are a wall somebody reads, not an archive somebody pages through.
     *
     * @return list<SpaceNoteInterface>
     */
    public function findForSpace(CustomerSpaceInterface $space): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.space = :space')
            ->setParameter('space', $space)
            ->orderBy('n.pinned', Order::Descending->value)
            ->addOrderBy('n.updatedAt', Order::Descending->value)
            ->addOrderBy('n.id', Order::Descending->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * The notes whose body carries one document.
     *
     * Narrowed in SQL, then verified in PHP - the trade
     * {@see PostRepository::findUsingDocument()}
     * already makes, and for the same two reasons. Postgres has no `~~` for
     * `json`, so the cast is explicit and the narrowing is a native query; and
     * the narrowing is deliberately loose - asked for 12 it also matches 123 -
     * so the exact answer is read off the blocks themselves.
     *
     * @return list<SpaceNoteInterface>
     */
    public function findUsingDocument(int $documentId): array
    {
        $metadata = $this->getClassMetadata();

        $rows = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            sprintf(
                'SELECT id FROM %s WHERE %s::text LIKE :pattern',
                $metadata->getTableName(),
                $metadata->getColumnName('body'),
            ),
            ['pattern' => sprintf('%%"documentId":%d%%', $documentId)],
            ['pattern' => ParameterType::STRING],
        );

        if ([] === $rows) {
            return [];
        }

        /** @var list<SpaceNoteInterface> $candidates */
        $candidates = $this->createQueryBuilder('n')
            ->where('n.id IN (:ids)')
            ->setParameter('ids', array_map(static fn (mixed $id): int => (int) $id, $rows))
            ->orderBy('n.id', Order::Ascending->value)
            ->getQuery()
            ->getResult();

        return array_values(array_filter(
            $candidates,
            static fn (SpaceNoteInterface $note): bool => self::reallyUses($note, $documentId),
        ));
    }

    /**
     * Whether a note really carries this document, block by block.
     *
     * The narrowing above matches a substring; this reads the id the editor
     * stored next to the address, which is the only exact answer.
     */
    private static function reallyUses(SpaceNoteInterface $note, int $documentId): bool
    {
        foreach ($note->getBody() as $block) {
            $file = $block['data']['file'] ?? null;

            if (is_array($file) && ($file['documentId'] ?? null) === $documentId) {
                return true;
            }
        }

        return false;
    }
}
