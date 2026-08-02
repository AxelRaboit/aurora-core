<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Serializer;

use Aurora\Module\Editorial\Post\Entity\PostRevisionInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(PostRevisionSerializerInterface::class)]
class PostRevisionSerializer implements PostRevisionSerializerInterface
{
    public function serialize(PostRevisionInterface $revision): array
    {
        $author = $revision->getAuthor();

        return [
            'id' => $revision->getId(),
            'postVersion' => $revision->getPostVersion(),
            'status' => $revision->getStatus()->value,
            'createdAt' => $revision->getCreatedAt()->format(DateTimeInterface::ATOM),
            'author' => $author instanceof CoreUserInterface ? [
                'id' => $author->getId(),
                'email' => $author->getEmail(),
            ] : null,
        ];
    }

    public function serializeFull(PostRevisionInterface $revision): array
    {
        return [
            ...$this->serialize($revision),
            'snapshot' => $revision->getSnapshot(),
        ];
    }
}
