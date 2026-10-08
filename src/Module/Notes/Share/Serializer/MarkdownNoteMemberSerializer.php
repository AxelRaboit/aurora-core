<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Share\Serializer;

use Aurora\Module\Notes\Share\Entity\MarkdownNoteMemberInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(MarkdownNoteMemberSerializerInterface::class)]
class MarkdownNoteMemberSerializer implements MarkdownNoteMemberSerializerInterface
{
    public function serialize(MarkdownNoteMemberInterface $member): array
    {
        return [
            // The account, not the row: the screen removes somebody by who
            // they are, and it never has to carry the membership's own id.
            'userId' => $member->getUser()->getId(),
            'name' => $member->getUser()->getName(),
            'role' => $member->getRole()->value,
            'canWrite' => $member->getRole()->canWrite(),
            'createdAt' => $member->getCreatedAt()->format('c'),
        ];
    }
}
