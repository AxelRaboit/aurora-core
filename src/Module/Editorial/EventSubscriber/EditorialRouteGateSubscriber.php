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
 * The public content routes are not listed here — they are gated by
 * {@see FrontendRouteGateSubscriber} through the prefixes
 * {@see EditorialFrontendDescriptor} declares, and gating them twice would
 * only give the rule two places to drift from. The crawler-facing files are
 * the exception: they carry a toggle of their own, so an administrator can
 * keep the site public and stop publishing a sitemap — something Core's gate
 * knows nothing about.
 */
final readonly class EditorialRouteGateSubscriber extends AbstractModuleRouteGateSubscriber
{
    public function __construct(private EditorialContext $editorialContext) {}

    protected function routeNamespaces(): array
    {
        return ['backend_editorial_', 'editorial_sitemap', 'editorial_robots', 'editorial_rss', 'editorial_post_comment', 'editorial_comment_'];
    }

    protected function gates(): array
    {
        return [
            'backend_editorial_' => $this->editorialContext->isBackendEnabled(),
            'backend_editorial_posts' => $this->editorialContext->isPostsEnabled(),
            'backend_editorial_post_types' => $this->editorialContext->isPostTypesEnabled(),
            'backend_editorial_taxonomies' => $this->editorialContext->isTaxonomiesEnabled(),
            'backend_editorial_menus' => $this->editorialContext->isMenusEnabled(),
            'backend_editorial_comments' => $this->editorialContext->isCommentsEnabled(),
            // The public endpoints go with the screen that moderates them:
            // accepting comments nobody can approve is worse than not
            // accepting them.
            'editorial_post_comment' => $this->editorialContext->isCommentsEnabled(),
            'editorial_comment_' => $this->editorialContext->isCommentsEnabled(),
            // The crawler-facing files. They are public routes, so the front
            // toggle already covers them through Core's gate; this is the one
            // that lets an administrator keep the site public and stop
            // publishing a sitemap.
            'editorial_sitemap' => $this->editorialContext->isSeoEnabled(),
            'editorial_robots' => $this->editorialContext->isSeoEnabled(),
            'editorial_rss' => $this->editorialContext->isSeoEnabled(),
        ];
    }
}
