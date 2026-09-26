<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\GoogleReviews\Setting;

use Aurora\Module\Configuration\Setting\Configuration\ConfigurationTab;
use Aurora\Module\Configuration\Setting\Configuration\ConfigurationTabProviderInterface;

final readonly class GoogleReviewsConfigurationTabProvider implements ConfigurationTabProviderInterface
{
    public function getTabs(): array
    {
        return [
            new ConfigurationTab(id: 'google_reviews', priority: 81, fields: [], alwaysVisible: true, componentName: 'googleReviews'),
        ];
    }
}
