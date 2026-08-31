<?php

declare(strict_types=1);

namespace Aurora\Core\Module\Nav;

/**
 * What the side menu shows while the reader is inside a module.
 *
 * The menu is one column with two views, never two columns: the 280 px do not
 * change width, they change contents. The project view is the eight sections
 * of `ModuleRegistry::getNavSections()`; the module view is this - the open
 * module's own destinations, plus optionally a panel component for the cases a
 * list of links cannot express (a folder tree, a note list).
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
