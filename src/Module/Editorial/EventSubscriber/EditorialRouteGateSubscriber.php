<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\EventSubscriber;

use Aurora\Core\Frontend\EventSubscriber\FrontendRouteGateSubscriber;
use Aurora\Core\Module\EventSubscriber\AbstractModuleRouteGateSubscriber;
use Aurora\Module\Editorial\EditorialContext;
use Aurora\Module\Editorial\EditorialFrontendDescriptor;

/**
 * Closes Editorial's backend routes when the toggles behind them are off.
 *
 * One gate per toggle rather than one for the whole module: an admin who
 * turns off Taxonomies alone expects the taxonomy screens to go and the
 * posts to stay.
 *
 * The public routes are not listed here — they are gated by
 * {@see FrontendRouteGateSubscriber}
 * through the prefixes {@see EditorialFrontendDescriptor}
 * declares, and gating them twice would only give the rule two places to
 * drift from.
 */
final readonly class EditorialRouteGateSubscriber extends AbstractModuleRouteGateSubscriber
{
    public function __construct(private EditorialContext $editorialContext) {}

    protected function routeNamespace(): string
    {
        return 'backend_editorial_';
    }

    protected function gates(): array
    {
        return [
            'backend_editorial_' => $this->editorialContext->isBackendEnabled(),
            'backend_editorial_posts' => $this->editorialContext->isPostsEnabled(),
            'backend_editorial_post_types' => $this->editorialContext->isPostTypesEnabled(),
            'backend_editorial_taxonomies' => $this->editorialContext->isTaxonomiesEnabled(),
            'backend_editorial_menus' => $this->editorialContext->isMenusEnabled(),
        ];
    }
}
