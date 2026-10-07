<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceFile\GoogleDrive\Setting;

use Aurora\Module\Configuration\Setting\Configuration\ConfigurationTab;
use Aurora\Module\Configuration\Setting\Configuration\ConfigurationTabProviderInterface;

/**
 * Puts the "Google Drive" tab on the settings screen.
 *
 * No field declared: the generic rendering would send the service account
 * key to the browser as an ordinary value.
 */
final readonly class DriveConfigurationTabProvider implements ConfigurationTabProviderInterface
{
    public function getTabs(): array
    {
        return [
            new ConfigurationTab(
                id: 'drive',
                priority: 76,
                fields: [],
                alwaysVisible: true,
                componentName: 'drive',
            ),
        ];
    }
}
