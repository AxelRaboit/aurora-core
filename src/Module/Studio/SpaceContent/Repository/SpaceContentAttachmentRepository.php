<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Repository;

use Aurora\Core\Repository\ResolveTargetEntityRepository;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentAttachment;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentAttachmentInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItemInterface;
use Doctrine\Common\Collections\Order;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ResolveTargetEntityRepository<SpaceContentAttachmentInterface>
 */
class SpaceContentAttachmentRepository extends ResolveTargetEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SpaceContentAttachment::class, SpaceContentAttachmentInterface::class);
    }

    /**
     * Every file of a space, in reading order, grouped by the card it is on.
     *
     * The whole space in one query, for the reason
     * {@see SpaceContentCommentRepository::findForSpaceByItem()} gives: the
     * three views draw every card at once, and a query per card would be a
     * round trip per click for data the page already needs.
     *
     * The document is joined rather than left to lazy loading. A card shows a
     * thumbnail, so every one of these rows is dereferenced immediately, and
     * without the join a board of forty cards is forty extra queries.
     *
     * @return array<int, list<SpaceContentAttachmentInterface>> keyed by item id
     */
    public function findForSpaceByItem(CustomerSpaceInterface $space): array
    {
        /** @var list<SpaceContentAttachmentInterface> $attachments */
        $attachments = $this->createQueryBuilder('a')
            ->addSelect('d')
            ->join('a.item', 'i')
            ->join('a.document', 'd')
            ->where('i.space = :space')
            ->setParameter('space', $space)
            ->orderBy('a.position', Order::Ascending->value)
            ->addOrderBy('a.id', Order::Ascending->value)
            ->getQuery()
            ->getResult();

        $byItem = [];
        foreach ($attachments as $attachment) {
            $byItem[(int) $attachment->getItem()->getId()][] = $attachment;
        }

        return $byItem;
    }

    /**
     * The next free slot on an item.
     *
     * A file lands after the ones already there rather than at the front: what
     * is uploaded last is the newest, and a carousel that reorders itself on
     * every upload is one nobody can arrange.
     */
    public function nextPosition(SpaceContentItemInterface $item): int
    {
        $highest = $this->createQueryBuilder('a')
            ->select('MAX(a.position)')
            ->where('a.item = :item')
            ->setParameter('item', $item)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $highest ? 0 : ((int) $highest) + 1;
    }

    /**
     * @return list<SpaceContentAttachmentInterface>
     */
    public function findForItem(SpaceContentItemInterface $item): array
    {
        /** @var list<SpaceContentAttachmentInterface> $attachments */
        $attachments = $this->createQueryBuilder('a')
            ->addSelect('d')
            ->join('a.document', 'd')
            ->where('a.item = :item')
            ->setParameter('item', $item)
            ->orderBy('a.position', Order::Ascending->value)
            ->addOrderBy('a.id', Order::Ascending->value)
            ->getQuery()
            ->getResult();

        return $attachments;
    }

    /**
     * The attachments that point at one document, card and space joined.
     *
     * Asked by the library when somebody is about to delete a file. Both
     * relations are fetched because the answer names the card and the space
     * it sits in, and a usage list is short by nature - there is no board of
     * forty rows here to lazy-load one at a time.
     *
     * @return list<SpaceContentAttachmentInterface>
     */
    public function findUsingDocument(int $documentId): array
    {
        /** @var list<SpaceContentAttachmentInterface> $attachments */
        $attachments = $this->createQueryBuilder('a')
            ->addSelect('i', 's')
            ->join('a.item', 'i')
            ->join('i.space', 's')
            ->where('a.document = :document')
            ->setParameter('document', $documentId)
            ->orderBy('a.id', Order::Ascending->value)
            ->getQuery()
            ->getResult();

        return $attachments;
    }
}
