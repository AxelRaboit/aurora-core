<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceFile\GoogleDrive\Setting;

use Aurora\Module\Configuration\Setting\Provider\OwnedSettingProviderInterface;

/**
 * The two rows the Drive tab owns, so the deployment sync leaves them alone.
 *
 * Without this, the key would disappear at the next release and the
 * integration would switch off without anyone understanding why.
 */
final readonly class DriveOwnedSettingProvider implements OwnedSettingProviderInterface
{
    public function getOwnedSettingKeys(): iterable
    {
        foreach (DriveSettingEnum::cases() as $case) {
            yield $case->value;
        }
    }
}
