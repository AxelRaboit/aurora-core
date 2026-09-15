<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Serializer;

use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentCommentInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

use const DATE_ATOM;

#[AsAlias(SpaceContentCommentSerializerInterface::class)]
class SpaceContentCommentSerializer implements SpaceContentCommentSerializerInterface
{
    /**
     * The same shape on both sides of the thread.
     *
     * The client's page and the studio's read one payload, because they read
     * one conversation: a message that rendered differently depending on who
     * asked is how two people end up arguing about what was said.
     *
     * @return array<string, mixed>
     */
    public function serialize(SpaceContentCommentInterface $comment): array
    {
        return [
            'id' => $comment->getId(),
            'body' => $comment->getBody(),
            // The durable name, not the relation: an account can be deleted and
            // an address revoked, and a message whose author became null is a
            // message nobody wrote.
            'author' => $comment->getAuthorLabel(),
            'fromClient' => $comment->isFromClient(),
            'createdAt' => $comment->getCreatedAt()->format(DATE_ATOM),
        ];
    }
}
