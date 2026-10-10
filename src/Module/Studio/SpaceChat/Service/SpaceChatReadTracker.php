<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceChat\Service;

use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatChannelInterface;
use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatReadMarkerInterface;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Moves a reader's mark to now, in one room.
 *
 * Called when the room is on screen and when the reader writes in it: what
 * somebody answered, they have read. A preview reads without writing, like
 * every other gesture of a preview.
 *
 * **One statement, insert or update.** Two tabs of the same person mark the
 * same room at the same moment often enough - the panel marks on every
 * message that arrives - and a find-then-persist would let the second one
 * trip the unique index and close the entity manager in the middle of a
 * request. PostgreSQL's `ON CONFLICT` settles it where the race happens.
 */
readonly class SpaceChatReadTracker
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    public function markRead(SpaceChatChannelInterface $channel, ?CoreUserInterface $user, ?SpaceAccessLinkInterface $link = null): void
    {
        $reader = $user ?? $link;
        if (null === $reader || ($link instanceof SpaceAccessLinkInterface && !$user instanceof CoreUserInterface && $link->isPreview())) {
            return;
        }

        $metadata = $this->entityManager->getClassMetadata(SpaceChatReadMarkerInterface::class);
        $sequence = $metadata->sequenceGeneratorDefinition['sequenceName'] ?? 'seq_core_space_chat_read_marker_id';
        $column = $user instanceof CoreUserInterface ? 'user_id' : 'link_id';

        $this->entityManager->getConnection()->executeStatement(
            sprintf(
                'INSERT INTO %1$s (id, channel_id, %2$s, read_at) VALUES (nextval(\'%3$s\'), :channel, :reader, :at)
                 ON CONFLICT (channel_id, %2$s) DO UPDATE SET read_at = EXCLUDED.read_at',
                $metadata->getTableName(),
                $column,
                $sequence,
            ),
            ['channel' => $channel->getId(), 'reader' => $reader->getId(), 'at' => new DateTimeImmutable()],
            ['at' => Types::DATETIME_IMMUTABLE],
        );
    }
}
