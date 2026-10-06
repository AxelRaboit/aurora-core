<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Craft\Setting;

use Aurora\Module\Configuration\Setting\Configuration\ConfigurationTab;
use Aurora\Module\Configuration\Setting\Configuration\ConfigurationTabProviderInterface;
use Aurora\Module\Notes\NotesContext;

/**
 * Puts the "Craft" tab on the settings screen.
 *
 * No declared field: the generic rendering would send the token to the
 * browser like an ordinary value. The tab therefore draws itself, exactly
 * like the Pexels one and for the same reason.
 *
 * **Only as long as notes are on.** The import lands in a note, and the tab's
 * route closes with the module: a tab that no longer loads is worse than a
 * missing tab.
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
