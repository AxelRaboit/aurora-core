<?php

declare(strict_types=1);

namespace Aurora\Core\Module\Nav;

/**
 * What the side menu shows while the reader is inside a module.
 *
 * The menu is one column with two views, never two columns: the column does not
 * change width, it changes contents. The project view is the eight sections
 * of `ModuleRegistry::getNavSections()`; the module view is this - the open
 * module's own destinations, plus optionally a panel component for the cases a
 * list of links cannot express (a folder tree, a note list).
 *
 * **The two views repeat each other, and that is the arrangement, not an
 * oversight.** They are exclusive: the column shows one or the other, so a
 * module view that listed only what the project menu cannot show would strand
 * the reader - inside the GED, with no row for Tags, the only way to Tags is
 * back out to the project view and in again. The module view has to be
 * self-sufficient, which makes the repetition structural.
 *
 * Which leaves the question of whether the *project* menu should shrink to one
 * row per module, now that all eight have a view. It should not. The rows it
 * repeats are the one-click path to a destination from anywhere else in the
 * application, and collapsing them would put every cross-module jump behind
 * two clicks to save a duplication that costs nothing: both lists are built by
 * the same `NavItem` builders inside each module, so they cannot drift the way
 * two hand-maintained copies do - `GedModuleTest` asserts they agree.
 *
 * Declared through {@see ModuleNavViewProviderInterface}, which a module opts
 * into. A module that declares nothing keeps a menu that never switches away
 * from the project view - which is every module today, and why adding this
 * changes nothing on screen until a module asks for it.
 */
final readonly class ModuleNavView
{
    /**
     * @param string           $moduleId       The owning module's id, which is also its
     *                                         `NavSection` id - the two have been equal for
     *                                         all eight modules since the "1 module = 1
     *                                         NavSection" convention. That equality is what
     *                                         lets the view inherit the section's colour
     *                                         from `useSidemenuSectionTheme` instead of
     *                                         declaring a second palette.
     * @param ModuleNavGroup[] $groups         Ordered as declared. No `priority` here on
     *                                         purpose: a module writes its own view in one
     *                                         place, so the array order *is* the intent -
     *                                         unlike `NavSection`, which has to merge
     *                                         contributions from modules that cannot see
     *                                         each other.
     * @param ?string          $panelComponent Vue component path (the `vue_component`
     *                                         convention, e.g.
     *                                         `'ged/backend/documents/FolderTreePanel'`),
     *                                         mounted under the links. Null for a
     *                                         links-only view.
     */
    public function __construct(
        public string $moduleId,
        public array $groups = [],
        public ?string $panelComponent = null,
    ) {}
}
