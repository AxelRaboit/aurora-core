<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Message;

/**
 * Tell each space's team about the reviews that passed their deadline.
 *
 * Empty, like the other scheduled messages: what is late is a question for
 * the handler, asked when it runs, not a list frozen when it was queued.
 */
final class NotifyLateReviewsMessage {}
