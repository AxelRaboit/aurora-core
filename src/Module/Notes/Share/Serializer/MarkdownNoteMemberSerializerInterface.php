<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Share\Serializer;

use Aurora\Module\Notes\Share\Entity\MarkdownNoteMemberInterface;

interface MarkdownNoteMemberSerializerInterface
{
    /** @return array<string, mixed> */
    public function serialize(MarkdownNoteMemberInterface $member): array;
}
