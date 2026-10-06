<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\EventSubscriber;

use Aurora\Core\Module\EventSubscriber\AbstractModuleRouteGateSubscriber;
use Aurora\Module\Notes\NotesContext;

/**
 * Closes the notes' routes when the module is switched off, for everyone or
 * for one person.
 *
 * The screens, and the addresses handed out from them: a shared note and a
 * published space are the notes still publishing, and notes switched off
 * should stop. The menu shows the module only when both its toggles are on,
 * so the routes ask the same question.
 *
 * Studio's `workspace_space_notes_*` too: the Notes tab of a customer space
 * opens a note space, and with the notes off the tab is hidden and its route
 * closed with the rest.
 */
final readonly class NotesRouteGateSubscriber extends AbstractModuleRouteGateSubscriber
{
    public function __construct(private NotesContext $notesContext) {}

    protected function routeNamespaces(): array
    {
        return ['suite_notes_', 'notes_share', 'notes_public', 'workspace_space_notes'];
    }

    protected function gates(): array
    {
        $enabled = $this->notesContext->isSuiteEnabled() && $this->notesContext->isMarkdownEnabled();

        return [
            'suite_notes_' => $enabled,
            'notes_share' => $enabled,
            'notes_public' => $enabled,
            'workspace_space_notes' => $enabled,
        ];
    }
}
