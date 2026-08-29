<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Message;

/**
 * Take down every publication whose end date has passed.
 *
 * Its own message rather than a second job inside the publishing one: they run on
 * the same cadence and answer opposite questions, and a handler doing both would
 * make a failure in either look like a failure in the other.
 */
final readonly class UnpublishScheduledPostsMessage {}
