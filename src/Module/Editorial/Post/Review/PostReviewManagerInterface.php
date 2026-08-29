<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Review;

use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeImmutable;

interface PostReviewManagerInterface
{
    /** Puts a draft in front of the people who may publish it, and tells them. */
    public function submit(PostInterface $post, ?CoreUserInterface $author = null): void;

    public function approve(PostInterface $post, CoreUserInterface $reviewer, ?DateTimeImmutable $now = null): void;

    /** Sends it back to draft with the reason attached, and tells the author. */
    public function reject(
        PostInterface $post,
        CoreUserInterface $reviewer,
        string $note,
        ?DateTimeImmutable $now = null,
    ): void;
}
