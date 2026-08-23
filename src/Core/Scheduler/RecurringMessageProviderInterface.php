<?php

declare(strict_types=1);

namespace Aurora\Core\Scheduler;

use Symfony\Component\Scheduler\RecurringMessage;

/**
 * A module contributes its recurring (cron) messages to the main schedule by
 * implementing this interface. {@see MainSchedule} aggregates every tagged
 * provider, so aurora-core's schedule has NO dependency on module message
 * classes - each module owns its own recurring jobs.
 *
 * Implementations are auto-tagged `aurora.recurring_message_provider`.
 */
interface RecurringMessageProviderInterface
{
    /**
     * @return iterable<RecurringMessage>
     */
    public function getRecurringMessages(): iterable;
}
