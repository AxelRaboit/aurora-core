<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Service;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;

use function max;
use function min;

/**
 * How long a signing address stays valid, in days.
 *
 * Thirty by default, because an offer that can still be accepted a year later
 * is a liability and the paper version always carried a validity period. A
 * setting rather than the constant it used to be: the reminders and the
 * retention were already adjustable, and the one number every send prints was
 * the one that was not.
 *
 * Read each time, like {@see ContractRetentionPolicy}: the manager that dates a
 * new link and the screens that announce its duration must quote the same rule.
 * Bounded, so a field emptied or typed wrong neither hands out an address that
 * is dead on arrival nor one that never ends.
 */
final readonly class ContractLinkLifetime
{
    public const int MINIMUM_DAYS = 1;

    public const int MAXIMUM_DAYS = 365;

    public function __construct(
        private SettingRepository $settingRepository,
    ) {}

    public function days(): int
    {
        $days = (int) $this->settingRepository->getOrDefault(ApplicationParameterEnum::StudioContractLinkDays);

        return min(self::MAXIMUM_DAYS, max(self::MINIMUM_DAYS, $days));
    }
}
