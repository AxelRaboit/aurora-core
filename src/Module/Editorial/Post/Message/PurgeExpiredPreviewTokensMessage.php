<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Message;

/**
 * Drop preview tokens that have run out.
 *
 * Housekeeping rather than security: an expired token is already refused at the
 * route. But nothing deleted them, so the table only ever grew - and a table that
 * only grows is one somebody eventually has to explain.
 */
final readonly class PurgeExpiredPreviewTokensMessage {}
