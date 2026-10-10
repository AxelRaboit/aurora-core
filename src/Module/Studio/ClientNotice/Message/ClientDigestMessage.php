<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\ClientNotice\Message;

/**
 * Look again, in half an hour, at what this link has not been told.
 *
 * Dispatched after every piece of news, with a delay. The handler sends only
 * when the last piece is old enough: a studio adding five files and a
 * message in ten minutes queues six of these, and only the last one finds
 * the batch finished and writes.
 */
final readonly class ClientDigestMessage
{
    public function __construct(
        public int $linkId,
    ) {}
}
