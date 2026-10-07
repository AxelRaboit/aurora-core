<?php

declare(strict_types=1);

namespace Aurora\Module\Beacon\Setting;

use Aurora\Module\Configuration\Setting\Provider\OwnedSettingProviderInterface;

/**
 * The rows the beacon writes, so the deploy-time sync leaves them be.
 *
 * Both used to be bare string constants, which `OwnedSettingCoverageTest` does
 * not see, and `aurora:application-parameter` deleted them at every release:
 * production drew a new instance id with each version and showed up as five
 * instances, and an allowlist edited on the screen came back to its defaults.
 * Found on 07/10/2026.
 */
final readonly class BeaconOwnedSettingProvider implements OwnedSettingProviderInterface
{
    public function getOwnedSettingKeys(): iterable
    {
        foreach (BeaconSettingEnum::cases() as $case) {
            yield $case->value;
        }
    }
}
