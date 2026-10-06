<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Craft\Setting;

use Aurora\Module\Configuration\Setting\Configuration\ConfigurationTab;
use Aurora\Module\Configuration\Setting\Configuration\ConfigurationTabProviderInterface;
use Aurora\Module\Notes\NotesContext;

/**
 * Pose l'onglet « Craft » sur l'écran des réglages.
 *
 * Aucun champ déclaré : le rendu générique enverrait le jeton au navigateur
 * comme une valeur ordinaire. L'onglet se dessine donc lui-même, exactement
 * comme celui de Pexels et pour la même raison.
 *
 * **Seulement tant que les notes sont allumées.** L'import arrive dans une
 * note, et la route de l'onglet se ferme avec le module : un onglet qui ne
 * se charge plus est pire qu'un onglet absent.
 */
final readonly class CraftConfigurationTabProvider implements ConfigurationTabProviderInterface
{
    public function __construct(private NotesContext $notesContext) {}

    public function getTabs(): array
    {
        if (!$this->notesContext->isSuiteEnabled() || !$this->notesContext->isMarkdownEnabled()) {
            return [];
        }

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
