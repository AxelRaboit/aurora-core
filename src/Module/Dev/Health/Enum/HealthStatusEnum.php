<?php

declare(strict_types=1);

namespace Aurora\Module\Dev\Health\Enum;

/**
 * Where a part of the system stands, as the « État du système » block draws it.
 *
 * - `ok`: running as it should;
 * - `warning`: running, but something deserves a look (a restart counted, a
 *   disk getting full, a certificate in three weeks);
 * - `danger`: not running, or about to stop (a silent worker, a failed
 *   message, a database that does not answer);
 * - `unknown`: nothing to judge yet (a task that has not run since the
 *   deployment);
 * - `off`: not configured here, which is not a fault (no Mercure hub, no
 *   systemd units listed).
 */
enum HealthStatusEnum: string
{
    case Ok = 'ok';

    case Warning = 'warning';

    case Danger = 'danger';

    case Unknown = 'unknown';

    case Off = 'off';

    /** How much it weighs in the summary: the worst one wins. */
    public function weight(): int
    {
        return match ($this) {
            self::Danger => 3,
            self::Warning => 2,
            self::Unknown => 1,
            self::Ok, self::Off => 0,
        };
    }

    /** @param list<self> $statuses */
    public static function worstOf(array $statuses): self
    {
        $worst = self::Ok;

        foreach ($statuses as $status) {
            if ($status->weight() > $worst->weight()) {
                $worst = $status;
            }
        }

        return $worst;
    }
}
