<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Serializer;

use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentCommentInterface;

interface SpaceContentCommentSerializerInterface
{
    /** @return array<string, mixed> */
    public function serialize(SpaceContentCommentInterface $comment): array;
}
