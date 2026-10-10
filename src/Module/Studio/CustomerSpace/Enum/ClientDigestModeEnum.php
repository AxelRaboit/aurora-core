<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\Enum;

/**
 * When a space writes to its client about what the studio did.
 *
 * **Off by default, decided space by space.** Writing to somebody else's
 * customer is a decision the studio takes for each relationship: one client
 * wants to hear of every file, another reads their page on Mondays and would
 * take a mail an hour for spam. Nothing is sent until somebody chooses.
 *
 * - `off`: nothing is mailed. The news is still written, and the client's
 *   page shows it at their next visit.
 * - `delayed`: one digest half an hour after the studio's last gesture, so a
 *   batch of five files and a message makes one mail, not six.
 * - `daily`: one digest in the morning, at the site's time.
 *
 * The values are persisted: add and remove, never rename.
 */
enum ClientDigestModeEnum: string
{
    case Off = 'off';

    case Delayed = 'delayed';

    case Daily = 'daily';

    public function getLabelKey(): string
    {
        return sprintf('suite.studio.spaces.client_digest.modes.%s', $this->value);
    }

    public function sends(): bool
    {
        return self::Off !== $this;
    }
}
