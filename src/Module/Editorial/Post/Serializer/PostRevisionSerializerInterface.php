<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Serializer;

use Aurora\Module\Editorial\Post\Entity\PostRevisionInterface;

interface PostRevisionSerializerInterface
{
    /** A row in the revision list. @return array<string, mixed> */
    public function serialize(PostRevisionInterface $revision): array;

    /** The same, plus the snapshot the diff view compares. @return array<string, mixed> */
    public function serializeFull(PostRevisionInterface $revision): array;
}
