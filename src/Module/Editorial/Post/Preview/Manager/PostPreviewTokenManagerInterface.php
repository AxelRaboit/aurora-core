<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Preview\Manager;

use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Preview\Entity\PostPreviewTokenInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeImmutable;

interface PostPreviewTokenManagerInterface
{
    /** The live preview for this post, minting one only if there is none. */
    public function resolveOrCreate(
        PostInterface $post,
        ?CoreUserInterface $author = null,
        ?DateTimeImmutable $now = null,
    ): PostPreviewTokenInterface;

    public function revoke(PostInterface $post, ?DateTimeImmutable $now = null): void;

    /** Null for unknown and expired alike. */
    public function resolveUsable(string $token, ?DateTimeImmutable $now = null): ?PostPreviewTokenInterface;
}
