<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Manager;

use Aurora\Module\Editorial\Post\Dto\PostGalleryInput;
use Aurora\Module\Editorial\Post\Entity\PostInterface;

interface PostGalleryManagerInterface
{
    /**
     * Applies a gallery change and flushes.
     *
     * The only write this contract allows on a post, which is the point: a caller
     * holding this interface cannot publish, retitle or refile anything.
     */
    public function update(PostInterface $post, PostGalleryInput $input): void;
}
