<?php

declare(strict_types=1);

namespace Aurora\Core\Module\Nav;

/**
 * One block of links inside a {@see ModuleNavView} - the module-level
 * equivalent of a {@see NavSection}.
 *
 * A group with no `labelKey` renders as a bare list of rows. That is the
 * common case and the intended default: a module with four destinations does
 * not need a header saying so, and the column already carries the module's
 * name in its own header. Reach for a label only when a module has enough
 * destinations that they read as two families.
 */
final readonly class ModuleNavGroup
{
    /**
     * @param string    $id       Stable technical identifier, unique within its view.
     *                            Used as the key for the group's fold state, so renaming
     *                            it resets that state - harmless, but pointless churn.
     * @param NavItem[] $items    same value object as the main menu, so a module-level
     *                            link keeps every guarantee a `NavSection` item has:
     *                            privilege filtering, i18n label, admin alias, and a
     *                            route name stable enough to persist a user preference
     *                            against (`getHiddenNavItems()` matches on it)
     * @param ?string   $labelKey translation key for the group header, or null for none
     */
    public function __construct(
        public string $id,
        public array $items,
        public ?string $labelKey = null,
    ) {}
}
