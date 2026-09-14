<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\Message;

/**
 * Announce a submission that is already stored: the owner's mail, the
 * visitor's confirmation, the webhook.
 *
 * Carries the id and nothing else. A message outlives the request that queued
 * it, so anything copied into it is a snapshot that can already be wrong by
 * the time a worker reads it - the row is the truth.
 */
final readonly class DeliverFormSubmissionMessage
{
    public function __construct(public int $submissionId) {}
}
