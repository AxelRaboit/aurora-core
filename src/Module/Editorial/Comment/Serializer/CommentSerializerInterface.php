<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Comment\Serializer;

use Aurora\Module\Editorial\Comment\Entity\CommentInterface;

interface CommentSerializerInterface
{
    /**
     * The moderation queue's shape. Carries what only a moderator may see -
     * the author's address, the status.
     *
     * @param array<int, array<string, int>> $reactionCounts comment id → type → count
     * @param array<int, int>|null           $replyCounts    comment id → replies, for a list;
     *                                                       without it the replies are counted
     *                                                       from the collection, which loads them
     *
     * @return array<string, mixed>
     */
    public function serialize(CommentInterface $comment, array $reactionCounts = [], ?array $replyCounts = null): array;

    /**
     * What a reader receives. No address, no status: everything here is
     * approved by definition.
     *
     * @param array<int, array<string, int>> $reactionCounts comment id → type → count
     *
     * @return array<string, mixed>
     */
    public function serializeForReader(CommentInterface $comment, array $reactionCounts = []): array;

    /**
     * A whole thread, roots carrying their replies.
     *
     * @param list<CommentInterface>         $comments
     * @param array<int, array<string, int>> $reactionCounts
     *
     * @return array<string, mixed>
     */
    public function serializeThread(array $comments, array $reactionCounts = []): array;
}
