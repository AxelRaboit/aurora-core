<?php

declare(strict_types=1);

namespace Aurora\Module\Ged;

use Aurora\Core\Module\Contract\ModuleInterface;
use Aurora\Core\Module\Contract\ModuleNavViewProviderInterface;
use Aurora\Core\Module\Contract\ModuleToggleProviderInterface;
use Aurora\Core\Module\Nav\ModuleNavGroup;
use Aurora\Core\Module\Nav\ModuleNavView;
use Aurora\Core\Module\Nav\NavItem;
use Aurora\Core\Module\Nav\NavPermission;
use Aurora\Core\Module\Nav\NavSection;
use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;

final readonly class GedModule implements ModuleInterface, ModuleNavViewProviderInterface, ModuleToggleProviderInterface
{
    /**
     * The panel the module view mounts under its links.
     *
     * The folder tree is the case a list of links cannot express: it is
     * hierarchical, it is as long as the reader made it, and its rows are data
     * rather than destinations declared in PHP.
     */
    private const string FOLDER_TREE_PANEL = 'ged/backend/documents/FolderTreePanel';

    public function __construct(private GedContext $gedContext) {}

    public function getId(): string
    {
        return 'ged';
    }

    public function getPermissions(): array
    {
        return [
            new NavPermission('ged.documents.view'),
            new NavPermission('ged.documents.create'),
            new NavPermission('ged.documents.edit'),
            new NavPermission('ged.documents.delete'),
            new NavPermission('ged.categories.view'),
            new NavPermission('ged.categories.create'),
            new NavPermission('ged.categories.edit'),
            new NavPermission('ged.categories.delete'),
            new NavPermission('ged.tags.manage'),
            new NavPermission('ged.folders.manage'),
        ];
    }

    public function getNavSections(): array
    {
        if (!$this->gedContext->isBackendEnabled()) {
            return [];
        }

        $items = $this->enabledNavItems();

        if ([] === $items) {
            return [];
        }

        return [new NavSection('ged', $items, priority: 35)];
    }

    public function getCatalogNavSections(): array
    {
        return [
            new NavSection('ged', [
                $this->documentsNavItem(),
                $this->categoriesNavItem(),
                $this->tagsNavItem(),
                $this->foldersNavItem(),
            ], priority: 35),
        ];
    }

    /**
     * The menu the reader gets while they are inside the GED.
     *
     * The same four destinations as the project view, in one headerless group:
     * four rows do not read as two families, and the column already carries the
     * module's name in its header. What the view adds is underneath them - the
     * folder tree, which is the whole point of declaring a view here rather
     * than leaving the GED on the project menu.
     *
     * The panel is named only when both documents and folders are on. A tree of
     * folders with no way to open one is a decoration, and a reader who cannot
     * see documents has no use for the folder they live in.
     */
    public function getModuleNavView(): ?ModuleNavView
    {
        if (!$this->gedContext->isBackendEnabled()) {
            return null;
        }

        $items = $this->enabledNavItems();

        if ([] === $items) {
            return null;
        }

        $showsTree = $this->gedContext->isDocumentsEnabled() && $this->gedContext->isFoldersEnabled();

        return new ModuleNavView(
            'ged',
            [new ModuleNavGroup('destinations', $items)],
            panelComponent: $showsTree ? self::FOLDER_TREE_PANEL : null,
        );
    }

    public function getToggles(): array
    {
        return [
            ModuleParameterEnum::GedBackend->toToggle(),
            ModuleParameterEnum::GedDocuments->toToggle(),
            ModuleParameterEnum::GedCategories->toToggle(),
            ModuleParameterEnum::GedTags->toToggle(),
            ModuleParameterEnum::GedFolders->toToggle(),
            ModuleParameterEnum::GedFrontend->toToggle(),
        ];
    }

    /**
     * The destinations whose sub-feature is on, in menu order.
     *
     * One list for the project menu and the module view. They were two literal
     * copies of the same four entries, and the module view would have made a
     * third - the kind of triplication where the copies stop agreeing one
     * toggle at a time.
     *
     * The catalog keeps its own list on purpose: it shows what the module
     * *offers*, disabled sub-features included, so it must not consult the
     * toggles.
     *
     * @return NavItem[]
     */
    private function enabledNavItems(): array
    {
        $items = [];

        if ($this->gedContext->isDocumentsEnabled()) {
            $items[] = $this->documentsNavItem();
        }

        if ($this->gedContext->isCategoriesEnabled()) {
            $items[] = $this->categoriesNavItem();
        }

        if ($this->gedContext->isTagsEnabled()) {
            $items[] = $this->tagsNavItem();
        }

        if ($this->gedContext->isFoldersEnabled()) {
            $items[] = $this->foldersNavItem();
        }

        return $items;
    }

    private function documentsNavItem(): NavItem
    {
        return new NavItem('backend_ged_documents', 'backend.nav.documents', 'folder-open', requiredPrivilege: 'ged.documents.view', descriptionKey: 'backend.nav.documents_description');
    }

    private function categoriesNavItem(): NavItem
    {
        return new NavItem('backend_ged_categories', 'backend.nav.ged_categories', 'tags', requiredPrivilege: 'ged.categories.view', descriptionKey: 'backend.nav.ged_categories_description');
    }

    private function tagsNavItem(): NavItem
    {
        return new NavItem('backend_ged_tags', 'backend.nav.ged_tags', 'tag', requiredPrivilege: 'ged.tags.manage', descriptionKey: 'backend.nav.ged_tags_description');
    }

    private function foldersNavItem(): NavItem
    {
        return new NavItem('backend_ged_folders', 'backend.nav.ged_folders', 'folder', requiredPrivilege: 'ged.folders.manage', descriptionKey: 'backend.nav.ged_folders_description');
    }
}
