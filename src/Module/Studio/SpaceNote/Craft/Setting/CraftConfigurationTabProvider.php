<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceNote\Craft\Setting;

use Aurora\Module\Configuration\Setting\Configuration\ConfigurationTab;
use Aurora\Module\Configuration\Setting\Configuration\ConfigurationTabProviderInterface;

/**
 * Pose l'onglet « Craft » sur l'écran des réglages.
 *
 * Aucun champ déclaré : le rendu générique enverrait le jeton au navigateur
 * comme une valeur ordinaire. L'onglet se dessine donc lui-même, exactement
 * comme celui de Pexels et pour la même raison.
 */
final readonly class CraftConfigurationTabProvider implements ConfigurationTabProviderInterface
{
    public function getTabs(): array
    {
        return [
            new ConfigurationTab(
                id: 'craft',
                priority: 75,
                fields: [],
                alwaysVisible: true,
                componentName: 'craft',
            ),
        ];
    }
}
