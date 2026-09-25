<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\GitHub\Setting;

use Aurora\Module\Configuration\Setting\Configuration\ConfigurationTab;
use Aurora\Module\Configuration\Setting\Configuration\ConfigurationTabProviderInterface;

/**
 * Pose l'onglet « GitHub » sur l'écran des réglages.
 *
 * Aucun champ déclaré : l'onglet se dessine lui-même, pour vérifier chaque
 * identifiant avant de l'enregistrer.
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
