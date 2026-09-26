<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Newsletter\Setting;

use Aurora\Module\Configuration\Setting\Provider\OwnedSettingProviderInterface;

final readonly class NewsletterOwnedSettingProvider implements OwnedSettingProviderInterface
{
    public function getOwnedSettingKeys(): iterable
    {
        foreach (NewsletterSettingEnum::cases() as $case) {
            yield $case->value;
        }
    }
}
