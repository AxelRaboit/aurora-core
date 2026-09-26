<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Newsletter\Setting;

use Aurora\Module\Configuration\Setting\Configuration\ConfigurationTab;
use Aurora\Module\Configuration\Setting\Configuration\ConfigurationTabProviderInterface;

final readonly class NewsletterConfigurationTabProvider implements ConfigurationTabProviderInterface
{
    public function getTabs(): array
    {
        return [
            new ConfigurationTab(id: 'newsletter', priority: 82, fields: [], alwaysVisible: true, componentName: 'newsletter'),
        ];
    }
}
