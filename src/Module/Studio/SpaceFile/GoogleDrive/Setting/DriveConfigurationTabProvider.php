<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceFile\GoogleDrive\Setting;

use Aurora\Module\Configuration\Setting\Configuration\ConfigurationTab;
use Aurora\Module\Configuration\Setting\Configuration\ConfigurationTabProviderInterface;

/**
 * Pose l'onglet « Google Drive » sur l'écran des réglages.
 *
 * Aucun champ déclaré : le rendu générique enverrait la clé du compte de
 * service au navigateur comme une valeur ordinaire.
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
