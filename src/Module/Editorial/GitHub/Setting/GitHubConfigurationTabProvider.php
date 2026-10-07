<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\GitHub\Setting;

use Aurora\Module\Configuration\Setting\Configuration\ConfigurationTab;
use Aurora\Module\Configuration\Setting\Configuration\ConfigurationTabProviderInterface;

/**
 * Puts the "GitHub" tab on the settings screen.
 *
 * No declared field: the tab draws itself, to check each identifier before
 * saving it.
 */
final readonly class GitHubConfigurationTabProvider implements ConfigurationTabProviderInterface
{
    public function getTabs(): array
    {
        return [
            new ConfigurationTab(
                id: 'github',
                priority: 78,
                fields: [],
                alwaysVisible: true,
                componentName: 'github',
            ),
        ];
    }
}
