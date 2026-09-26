<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\GoogleReviews\Setting;

use Aurora\Module\Configuration\Setting\Provider\OwnedSettingProviderInterface;

final readonly class GoogleReviewsOwnedSettingProvider implements OwnedSettingProviderInterface
{
    public function getOwnedSettingKeys(): iterable
    {
        foreach (GoogleReviewsSettingEnum::cases() as $case) {
            yield $case->value;
        }
    }
}
