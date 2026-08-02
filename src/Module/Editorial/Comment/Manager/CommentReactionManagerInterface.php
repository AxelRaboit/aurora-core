<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Comment\Manager;

use Aurora\Module\Editorial\Comment\Entity\CommentInterface;
use Aurora\Module\Editorial\Comment\Enum\ReactionTypeEnum;
use Symfony\Component\HttpFoundation\Request;

interface CommentReactionManagerInterface
{
    /**
     * Adds, switches or withdraws this reader's reaction, and answers with the
     * comment's tallies afterwards.
     *
     * @return array<string, int> type → count
     */
    public function toggle(CommentInterface $comment, ReactionTypeEnum $type, string $fingerprint): array;

    public function fingerprint(Request $request): string;
}
