<?php

declare(strict_types=1);

namespace Aurora\Core\Module\Contract;

use Aurora\Core\Module\Nav\ModuleNavView;

/**
 * Optional companion interface for {@see ModuleInterface}: lets a module
 * declare the view the side menu switches to while the reader is inside it,
 * aggregated by {@see ModuleNavResolver}.
 *
 * Opt-in rather than a method on `ModuleInterface`, for the same reason
 * {@see ModuleToggleProviderInterface} is: aurora-client projects implement
 * `ModuleInterface` themselves, and a new required method would break every
 * one of them on `composer update`. A module that has no second level simply
 * does not implement this, and the resolver skips it.
 *
 * Six modules currently express their own navigation inside the page instead -
 * a `w-44` column in `SettingsApp`, seven tabs in a JS array in
 * `AdministrationApp`, a `w-72` aside in the GED and in Notes. This is where
 * that declaration is meant to move: once here, a destination is a route, so it
 * carries a privilege of its own, appears in the breadcrumb, and is found by
 * the search palette - none of which a tab in the URL fragment can do.
 */
interface ModuleNavViewProviderInterface
{
    /**
     * The module's own navigation, or null when it has none.
     *
     * Returning null is not the same as returning an empty view: null means
     * "this module has no second level", and the menu never leaves the project
     * view. Called on every backend page render, so keep it cheap - declare
     * structure, do not query.
     */
    public function getModuleNavView(): ?ModuleNavView;
}
