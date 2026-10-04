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
 */
final readonly class NotesRouteGateSubscriber extends AbstractModuleRouteGateSubscriber
{
    public function __construct(private NotesContext $notesContext) {}

    protected function routeNamespaces(): array
    {
        return ['backend_notes_', 'notes_share', 'notes_public'];
    }

    protected function gates(): array
    {
        $enabled = $this->notesContext->isBackendEnabled() && $this->notesContext->isMarkdownEnabled();

        return [
            'backend_notes_' => $enabled,
            'notes_share' => $enabled,
            'notes_public' => $enabled,
        ];
    }
}
