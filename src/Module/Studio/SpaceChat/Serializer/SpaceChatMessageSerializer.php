<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceChat\Serializer;

use Aurora\Module\Studio\SpaceChat\Entity\SpaceChatMessageInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

use const DATE_ATOM;

#[AsAlias(SpaceChatMessageSerializerInterface::class)]
class SpaceChatMessageSerializer implements SpaceChatMessageSerializerInterface
{
    /**
     * The same shape on both sides, and the same shape down both pipes.
     *
     * The studio's page, the client's page, and the message pushed through the
     * hub all read this one array. That matters more here than it does for a
     * card's thread: a message that arrived live and the same message after a
     * refresh have to be the same object, or the list will draw one of them
     * twice the moment somebody reloads.
     *
     * @return array<string, mixed>
     */
    public function serialize(SpaceChatMessageInterface $message): array
    {
        return [
            'id' => $message->getId(),
            'body' => $message->getBody(),
            // The durable name, not the relation: an account can be deleted and
            // an address revoked, and a message whose author became null is a
            // message nobody wrote.
            'author' => $message->getAuthorLabel(),
            'fromClient' => $message->isFromClient(),
            'createdAt' => $message->getCreatedAt()->format(DATE_ATOM),
        ];
    }
}
