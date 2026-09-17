<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\Message;

/**
 * Check, in a few minutes, whether this person still needs telling by email.
 *
 * **The delay is the whole feature.** A client writes, this is queued, and by
 * the time it runs the person may well have read the message in the
 * application - in which case nothing is sent. That is how a chat avoids being
 * a mailing list, and it is what the large messaging products do: wait, look
 * again, and only write to somebody who is not there.
 *
 * Carries two ids rather than the notification it came from: several things can
 * happen in one space inside the delay, and what has to be answered when this
 * runs is "does this person have anything unread here", not "is this one row
 * still unread".
 */
final readonly class SpaceActivityDigestMessage
{
    public function __construct(
        public int $recipientId,
        public int $spaceId,
    ) {}
}
