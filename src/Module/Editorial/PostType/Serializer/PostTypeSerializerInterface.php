<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\PostType\Serializer;

use Aurora\Module\Editorial\PostType\Entity\PostTypeInterface;

interface PostTypeSerializerInterface
{
    /** @return array<string, mixed> */
    public function serialize(PostTypeInterface $postType): array;
}
