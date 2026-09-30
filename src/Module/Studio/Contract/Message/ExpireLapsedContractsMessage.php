<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Message;

/**
 * Mark as expired the contracts no link opens any more.
 *
 * Empty, like the other scheduled messages: which contracts have lapsed is
 * read when the handler runs, not frozen into the payload.
 */
final class ExpireLapsedContractsMessage {}
