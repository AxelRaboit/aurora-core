<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Message;

/**
 * Ticks every minute; carries no payload because the work is "whatever is
 * due now", which only the handler can know.
 */
final readonly class PublishScheduledPostsMessage {}
