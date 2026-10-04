<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\EventSubscriber;

use Aurora\Core\Module\EventSubscriber\AbstractModuleRouteGateSubscriber;
use Aurora\Module\Ged\GedContext;

/**
 * Closes the media library's routes when its toggles are off, sub-module by
 * sub-module: switching off "Tags" alone closes the tag screens and leaves
 * the documents open, as Editorial and Studio do for theirs.
 *
 * Two addresses stay with the top-level toggle only. `backend_ged_files`
 * serves draft files to other modules too (a space's attachments, a deck's
 * pictures): switching off the documents screen must not break them. And the
 * permalink `/document/{id}` is left open like `/uploads/{path}`, whose file
 * it redirects to: closing the one while the other answers protects nothing
 * and breaks the documents placed in the site's pages.
 */
final readonly class GedRouteGateSubscriber extends AbstractModuleRouteGateSubscriber
{
    public function __construct(private GedContext $gedContext) {}

    protected function routeNamespaces(): array
    {
        return ['backend_ged_', 'frontend_ged_'];
    }

    protected function gates(): array
    {
        return [
            'backend_ged_' => $this->gedContext->isBackendEnabled(),
            'backend_ged_documents' => $this->gedContext->isDocumentsEnabled(),
            // Pexels fills the documents library: no documents, no import.
            'backend_ged_pexels' => $this->gedContext->isDocumentsEnabled(),
            'backend_ged_categories' => $this->gedContext->isCategoriesEnabled(),
            'backend_ged_tags' => $this->gedContext->isTagsEnabled(),
            'backend_ged_folders' => $this->gedContext->isFoldersEnabled(),
            'frontend_ged_' => $this->gedContext->isFrontendEnabled(),
        ];
    }
}
