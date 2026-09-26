<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Instagram\Setting;

use Aurora\Module\Configuration\Setting\Provider\OwnedSettingProviderInterface;

final readonly class InstagramOwnedSettingProvider implements OwnedSettingProviderInterface
{
    public function getOwnedSettingKeys(): iterable
    {
        foreach (InstagramSettingEnum::cases() as $case) {
            yield $case->value;
        }
    }
}
