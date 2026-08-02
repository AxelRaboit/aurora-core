<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Comment\Manager;

use Aurora\Module\Editorial\Comment\Dto\CommentInputInterface;
use Aurora\Module\Editorial\Comment\Entity\CommentInterface;
use Aurora\Module\Editorial\Post\Entity\PostInterface;

interface CommentManagerInterface
{
    public function submit(PostInterface $post, CommentInputInterface $input, ?CommentInterface $parent = null): CommentInterface;

    public function approve(CommentInterface $comment): void;

    public function markAsSpam(CommentInterface $comment): void;

    public function delete(CommentInterface $comment): void;

    public function areCommentsEnabled(PostInterface $post): bool;
}
