<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Message;

/**
 * Check the sealed contracts against their hashes.
 *
 * Empty, like the other scheduled messages: which contracts exist is read
 * when the handler runs.
 */
final class VerifyContractsMessage {}
