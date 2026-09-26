<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Instagram\Setting;

use Aurora\Module\Configuration\Setting\Configuration\ConfigurationTab;
use Aurora\Module\Configuration\Setting\Configuration\ConfigurationTabProviderInterface;

final readonly class InstagramConfigurationTabProvider implements ConfigurationTabProviderInterface
{
    public function getTabs(): array
    {
        return [
            new ConfigurationTab(id: 'instagram', priority: 80, fields: [], alwaysVisible: true, componentName: 'instagram'),
        ];
    }
}
