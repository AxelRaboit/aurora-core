<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Manager;

use Aurora\Module\Editorial\Post\Dto\PostInputInterface;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Entity\PostRevisionInterface;

interface PostManagerInterface
{
    public function create(PostInputInterface $input): PostInterface;

    public function update(PostInterface $post, PostInputInterface $input): void;

    /** Soft delete - the post goes to the trash and can come back. */
    public function delete(PostInterface $post): void;

    public function restore(PostInterface $post): void;

    public function forceDelete(PostInterface $post): void;

    public function restoreRevision(PostInterface $post, PostRevisionInterface $revision): void;

    /** Permanently deletes every trashed post. Returns how many went. */
    public function emptyTrash(): int;

    /**
     * Downgrades Published to PendingReview when the caller may not publish,
     * so a save never fails outright on permissions alone. Pass the post when
     * editing: the publish decision depends on who owns it.
     */
    /** Whether the last `demoteIfNotPublishable` call actually demoted. */
    public function wasDemotedToReview(): bool;

    public function demoteIfNotPublishable(PostInputInterface $input, ?PostInterface $post = null): PostInputInterface;
}
